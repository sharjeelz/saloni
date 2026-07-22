<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingNotifier;
use App\Services\Booking\BookingService;
use App\Services\Otp\OtpService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public, unauthenticated booking surface for a salon's hosted page
 * (book.app/{slug}). The salon is resolved by slug and pinned as the tenant
 * so every query stays scoped to it.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        protected AvailabilityService $availability,
        protected BookingService $booking,
        protected OtpService $otp,
        protected BookingNotifier $notifier,
    ) {}

    /** Salon header/branding for the booking page. */
    public function salon(Salon $salon): JsonResponse
    {
        $this->pin($salon);

        return response()->json([
            'data' => $salon->only(['name', 'slug', 'brand_color', 'logo_path', 'locale', 'timezone']),
        ]);
    }

    public function branches(Salon $salon): JsonResponse
    {
        $this->pin($salon);

        return response()->json([
            'data' => Branch::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'address', 'city', 'maps_url', 'lat', 'lng']),
        ]);
    }

    public function services(Salon $salon): JsonResponse
    {
        $this->pin($salon);

        return response()->json([
            'data' => Service::with(['category:id,name,name_en,sort_order', 'staff:id,name,title'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /** Bookable slots for a service at a branch on a date (E5-2 / E6-1). */
    public function availability(Request $request, Salon $salon): JsonResponse
    {
        $this->pin($salon);

        $salonId = Tenancy::id();
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        // Scoped lookups — a foreign id simply 404s.
        $branch = Branch::where('is_active', true)->findOrFail($data['branch_id']);
        $service = Service::where('is_active', true)->findOrFail($data['service_id']);

        $slots = $this->availability->slots(
            $branch,
            $service,
            $data['staff_id'] ?? null,
            $data['date'],
        );

        return response()->json([
            'meta' => [
                'date' => $data['date'],
                'service' => $service->only(['id', 'name', 'duration_min', 'price', 'currency']),
                'branch_id' => $branch->id,
                'slot_step_minutes' => AvailabilityService::SLOT_STEP_MINUTES,
            ],
            'data' => $slots,
        ]);
    }

    /**
     * Embeddable "Book Now" widget (E10-2): a copy-paste snippet the salon adds
     * to its own site. Also returns the booking URL + a QR target (E10-3) which
     * the client renders as a QR image.
     */
    public function widget(Salon $salon): JsonResponse
    {
        $this->pin($salon);

        $base = rtrim(config('app.frontend_url', config('app.url')), '/');
        $bookingUrl = "{$base}/book/{$salon->slug}";
        $color = $salon->brand_color ?: '#1E5C4A';

        $snippet = <<<HTML
        <a href="{$bookingUrl}" target="_blank" rel="noopener"
           style="display:inline-block;padding:12px 20px;border-radius:9999px;
                  background:{$color};color:#fff;font-family:system-ui,sans-serif;
                  font-weight:600;text-decoration:none">احجز الآن · Book Now</a>
        HTML;

        return response()->json([
            'data' => [
                'booking_url' => $bookingUrl,
                'qr_target' => $bookingUrl, // client renders this as a QR image
                'embed_html' => $snippet,
            ],
        ]);
    }

    /** Send a verification code to the customer's phone (E6-2). */
    public function requestOtp(Request $request, Salon $salon): JsonResponse
    {
        $this->pin($salon);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE]]);

        $result = $this->otp->request('phone', $data['phone'], 'booking', Tenancy::id());
        if ($result['throttled'] ?? false) {
            return response()->json(['message' => 'A code was just sent. Please wait a moment.'], 429);
        }

        return response()->json(array_filter([
            'message' => 'Verification code sent.',
            'debug_code' => $result['debug_code'] ?? null, // null in production
        ], fn ($v) => $v !== null));
    }

    /**
     * Look up a customer's upcoming bookings by phone (after OTP) — so they can
     * manage a booking from the main page without the original link.
     */
    public function lookup(Request $request, Salon $salon): JsonResponse
    {
        $this->pin($salon);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $this->otp->verify('phone', $data['phone'], $data['code'], 'booking', Tenancy::id())) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $customer = Customer::where('phone', $data['phone'])->first();
        $bookings = $customer
            ? Appointment::where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('starts_at', '>', now())
                ->with(['service:id,name', 'staff:id,name'])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Appointment $a) => [
                    'reference' => $a->reference,
                    'manage_token' => $a->public_token,
                    'starts_at' => $a->starts_at,
                    'status' => $a->status,
                    'service' => $a->service?->name,
                    'staff' => $a->staff?->name,
                ])
            : collect();

        return response()->json(['data' => $bookings->values()]);
    }

    /**
     * Confirm a booking: verify the phone OTP, create the customer + appointment,
     * and text a confirmation (E6-2 / E6-3).
     */
    public function confirm(Request $request, Salon $salon): JsonResponse
    {
        $this->pin($salon);

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'staff_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $this->otp->verify('phone', $data['phone'], $data['code'], 'booking', Tenancy::id())) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        // Scoped lookups — foreign / inactive ids 404.
        $branch = Branch::where('is_active', true)->findOrFail($data['branch_id']);
        $service = Service::where('is_active', true)->findOrFail($data['service_id']);
        $staff = User::where('role', 'staff')->where('is_active', true)->findOrFail($data['staff_id']);

        $customer = $this->booking->resolveCustomer($data['name'], $data['phone']);

        // One active booking per customer (per salon) via the public page —
        // configurable; admin walk-ins bypass this. Point them at the existing
        // booking so they can cancel it.
        $max = (int) config('booking.max_active_per_customer', 1);
        if ($max > 0) {
            $active = Appointment::where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->get();

            if ($active->count() >= $max) {
                $existing = $active->first();

                return response()->json([
                    'message' => 'You already have an upcoming booking. Please cancel it before booking again, or use a different number.',
                    'existing' => [
                        'reference' => $existing->reference,
                        'manage_token' => $existing->public_token,
                        'starts_at' => $existing->starts_at,
                    ],
                ], 409);
            }
        }

        try {
            $appointment = $this->booking->book(
                $branch, $service, $staff, $customer, $data['date'], $data['time'], source: 'online',
            );
        } catch (SlotUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->notifier->confirmation($appointment);
        $this->notifier->notifyOwners($appointment); // E8-3

        return response()->json([
            'message' => 'Booking confirmed.',
            'data' => [
                'reference' => $appointment->reference,       // short, human
                'manage_token' => $appointment->public_token, // for the manage link
                'starts_at' => $appointment->starts_at,
                'service' => $service->name,
                'staff' => $staff->name,
            ],
        ], 201);
    }

    /** View a booking from its manage link (E6-4). */
    public function manageShow(string $token): JsonResponse
    {
        $appointment = $this->resolveByToken($token);

        return response()->json([
            'data' => $appointment->load(
                'service:id,name,duration_min',
                'staff:id,name',
                'branch:id,name,address',
                'salon:id,name,slug,brand_color,timezone',
            ),
        ]);
    }

    /** Available slots to reschedule THIS booking (excludes its own time). */
    public function manageAvailability(Request $request, string $token): JsonResponse
    {
        $appointment = $this->resolveByToken($token);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);

        $slots = $this->availability->slots(
            Branch::findOrFail($appointment->branch_id),
            Service::findOrFail($appointment->service_id),
            $appointment->staff_id,
            $data['date'],
            $appointment->id, // ignore this appointment so its slot stays offered
        );

        return response()->json(['data' => $slots]);
    }

    /** Cancel from the manage link (E6-4). */
    public function cancel(string $token): JsonResponse
    {
        $appointment = $this->resolveByToken($token);

        abort_if(in_array($appointment->status, ['cancelled', 'done'], true), 422, 'This booking can no longer be cancelled.');

        $appointment->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => 'customer',
        ]);

        return response()->json(['message' => 'Booking cancelled.']);
    }

    /** Reschedule to a new free slot from the manage link (E6-4). */
    public function reschedule(Request $request, string $token): JsonResponse
    {
        $appointment = $this->resolveByToken($token);
        abort_if(in_array($appointment->status, ['cancelled', 'done'], true), 422, 'This booking can no longer be changed.');

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        // Move in place — keeps the same manage link / reference (BUG-3 fix).
        try {
            $this->booking->reschedule($appointment, $data['date'], $data['time']);
        } catch (SlotUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Booking rescheduled.',
            'data' => [
                'reference' => $appointment->reference,
                'manage_token' => $appointment->public_token,
                'starts_at' => $appointment->starts_at,
            ],
        ]);
    }

    /** Resolve an appointment by its public token, pinning its salon as tenant. */
    protected function resolveByToken(string $token): Appointment
    {
        $appointment = Appointment::withoutGlobalScope('salon')
            ->where('public_token', $token)
            ->firstOrFail();

        Tenancy::set($appointment->salon_id);

        return $appointment;
    }

    /** Resolve + pin the tenant; 404 for inactive/unknown salons. */
    protected function pin(Salon $salon): void
    {
        abort_unless($salon->is_active, 404);
        Tenancy::set($salon);
    }
}
