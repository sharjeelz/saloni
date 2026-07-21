<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sms\SmsSender;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function __construct(protected SmsSender $sms) {}

    /** List the salon's staff (tenant-scoped). */
    public function index(): JsonResponse
    {
        $staff = User::where('role', 'staff')
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email', 'title', 'role', 'is_active']);

        return response()->json(['data' => $staff]);
    }

    /**
     * Invite a staff member. Creates their account under the current salon
     * (no password — they sign in with phone OTP) and texts them an invite.
     */
    public function invite(Request $request): JsonResponse
    {
        $salonId = Tenancy::id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required', 'string', 'max:20',
                // Unique per salon so one number maps to one staff record here.
                Rule::unique('users', 'phone')->where(fn ($q) => $q->where('salon_id', $salonId)),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $staff = User::create([
            'salon_id' => $salonId,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'title' => $data['title'] ?? null,
            'role' => 'staff',
            'is_active' => true,
            // Random password placeholder; login is OTP-based.
            'password' => bin2hex(random_bytes(16)),
        ]);

        $salonName = optional($request->user()->salon)->name ?? 'the salon';
        $this->sms->send(
            $data['phone'],
            "You've been added to {$salonName} on Salon. "
            . 'Sign in with your phone number to get started.'
        );

        return response()->json([
            'message' => 'Staff invited.',
            'staff' => $staff->only(['id', 'name', 'phone', 'email', 'title', 'role', 'is_active']),
        ], 201);
    }

    /** Deactivate a staff member (soft — keeps history). Owner only. */
    public function deactivate(User $staff): JsonResponse
    {
        abort_unless($staff->role === 'staff', 404);

        $staff->update(['is_active' => false]);

        return response()->json(['message' => 'Staff deactivated.']);
    }
}
