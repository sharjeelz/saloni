<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Support\Tenancy;
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
        ]);

        $appointments = Appointment::with(['customer:id,name,phone', 'service:id,name,duration_min', 'staff:id,name'])
            ->when($user->isStaff(), fn ($q) => $q->where('staff_id', $user->id))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('starts_at', '<=', $d))
            ->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($data['staff_id'] ?? null, fn ($q, $id) => $q->where('staff_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('starts_at')
            ->get();

        return response()->json(['data' => $appointments]);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeAppointment($request, $appointment);

        return response()->json([
            'data' => $appointment->load(['customer', 'service:id,name,duration_min,price', 'staff:id,name', 'branch:id,name']),
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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],
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

        return response()->json(['data' => $appointment->load('customer:id,name,phone', 'service:id,name')], 201);
    }

    /** Mark done / no-show / cancelled / confirmed (E7-3). */
    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeAppointment($request, $appointment);

        $data = $request->validate([
            'status' => ['required', Rule::in(Appointment::STATUSES)],
        ]);

        $appointment->status = $data['status'];
        if ($data['status'] === 'cancelled') {
            $appointment->cancelled_at = now();
        }
        if ($data['status'] === 'done') {
            $appointment->customer?->update(['last_visit_at' => $appointment->ends_at]);
        }
        $appointment->save();

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
