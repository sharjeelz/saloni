<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Database\QueryException;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(protected BookingService $booking) {}

    /** Calendar feed (E7-1). Owners see the whole salon; staff see their own. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'reference' => ['nullable', 'string', 'max:12'],
        ]);

        $query = Appointment::with(['customer:id,name,phone', 'service:id,name,name_en,duration_min', 'staff:id,name'])
            ->when($user->isStaff(), fn ($q) => $q->where('staff_id', $user->id));

        if (! empty($data['reference'])) {
            // Look up a specific booking by its short reference — across all dates.
            $query->where('reference', strtoupper(trim($data['reference'])));
        } else {
            // Day/range view, interpreted in the salon timezone → UTC bounds.
            $tz = Salon::find(Tenancy::id())?->timezone ?? 'Asia/Riyadh';
            $from = ($data['from'] ?? null) ? Carbon::parse($data['from'], $tz)->startOfDay()->utc() : null;
            $to = ($data['to'] ?? null) ? Carbon::parse($data['to'], $tz)->endOfDay()->utc() : null;

            $query
                ->when($from, fn ($q) => $q->where('starts_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('starts_at', '<=', $to))
                ->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
                ->when($data['staff_id'] ?? null, fn ($q, $id) => $q->where('staff_id', $id))
                ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        }

        return response()->json(['data' => $query->orderBy('starts_at')->get()]);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeAppointment($request, $appointment);

        return response()->json([
            'data' => $appointment->load(['customer', 'service:id,name,name_en,duration_min,price', 'staff:id,name', 'branch:id,name']),
        ]);
    }

    /** Manual walk-in booking (E7-2). No OTP; staff/owner initiated. */
    public function store(Request $request): JsonResponse
    {
        $salonId = Tenancy::id();

        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('salon_id', $salonId)],
            'service_id' => ['required', Rule::exists('services', 'id')->where('salon_id', $salonId)],
            'staff_id' => ['required', Rule::exists('users', 'id')->where(fn ($q) =>
                $q->where('salon_id', $salonId)->where('role', 'staff'))],
            'customer_name' => ['required', 'string', 'min:2', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $customer = $this->booking->resolveCustomer($data['customer_name'], $data['customer_phone']);

        try {
            $appointment = $this->booking->book(
                Branch::findOrFail($data['branch_id']),
                Service::findOrFail($data['service_id']),
                User::findOrFail($data['staff_id']),
                $customer,
                $data['date'],
                $data['time'],
                source: 'walk_in',
            );
        } catch (SlotUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $appointment->load('customer:id,name,phone', 'service:id,name,name_en')], 201);
    }

    /** Mark done / no-show / cancelled / confirmed (E7-3). */
    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeAppointment($request, $appointment);

        $data = $request->validate([
            'status' => ['required', Rule::in(Appointment::STATUSES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = $data['status'];
        $appointment->status = $status;

        if ($status === 'cancelled') {
            $appointment->cancelled_at = now();
            $appointment->cancelled_by = $request->user()->role; // owner | staff
            $appointment->cancellation_reason = $data['reason'] ?? null;
        } else {
            // Leaving the cancelled state — don't carry stale cancellation metadata.
            $appointment->cancelled_at = null;
            $appointment->cancelled_by = null;
            $appointment->cancellation_reason = null;
        }

        if ($status === 'done') {
            $appointment->customer?->update(['last_visit_at' => $appointment->ends_at]);
        }

        try {
            $appointment->save();
        } catch (QueryException $e) {
            // Re-activating a cancelled booking whose slot was taken in the meantime
            // trips the no-overlap constraint.
            if ($e->getCode() === '23P01') {
                return response()->json(['message' => 'That time was booked by someone else.'], 409);
            }
            throw $e;
        }

        return response()->json(['data' => $appointment]);
    }

    /** Staff may only touch their own appointments (owners: any in the salon). */
    protected function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        $user = $request->user();
        if ($user->isStaff() && $appointment->staff_id !== $user->id) {
            abort(404);
        }
    }
}
