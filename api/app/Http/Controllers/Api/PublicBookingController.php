<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Salon;
use App\Models\Service;
use App\Services\Booking\AvailabilityService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated booking surface for a salon's hosted page
 * (book.app/{slug}). The salon is resolved by slug and pinned as the tenant
 * so every query stays scoped to it.
 */
class PublicBookingController extends Controller
{
    public function __construct(protected AvailabilityService $availability) {}

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
                ->get(['id', 'name', 'address', 'city']),
        ]);
    }

    public function services(Salon $salon): JsonResponse
    {
        $this->pin($salon);

        return response()->json([
            'data' => Service::with(['category:id,name', 'staff:id,name,title'])
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

    /** Resolve + pin the tenant; 404 for inactive/unknown salons. */
    protected function pin(Salon $salon): void
    {
        abort_unless($salon->is_active, 404);
        Tenancy::set($salon);
    }
}
