<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    public function __construct(protected OtpService $otp) {}

    /**
     * Request a login OTP for a phone number. Always responds 200 (never
     * reveals whether the number exists) unless throttled.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
        ]);

        $result = $this->otp->request('phone', $data['phone']);

        if ($result['throttled'] ?? false) {
            return response()->json([
                'message' => 'A code was recently sent. Please wait before requesting another.',
            ], 429);
        }

        return response()->json(array_filter([
            'message' => 'If the number is registered, a verification code has been sent.',
            'expires_at' => $result['expires_at'] ?? null,
            'debug_code' => $result['debug_code'] ?? null, // null in production
        ], fn ($v) => $v !== null));
    }

    /**
     * Verify a phone OTP and, if it matches a known user, issue a token.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'code' => ['required', 'string', 'size:6'],
            'salon_id' => ['nullable', 'integer'],
        ]);

        // Validate the code first (don't consume yet).
        $otp = $this->otp->check('phone', $data['phone'], $data['code']);
        if (! $otp) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $candidates = User::withoutGlobalScope('salon')
            ->where('phone', $data['phone'])
            ->where('is_active', true)
            ->with('salon:id,name')
            ->get();

        if ($candidates->isEmpty()) {
            $this->otp->consume($otp);

            return response()->json(['message' => 'No account found for this number.'], 404);
        }

        // Same number at more than one salon: ask which, keeping the code valid.
        if ($candidates->count() > 1 && empty($data['salon_id'])) {
            return response()->json([
                'message' => 'This number is registered at more than one salon. Choose one.',
                'accounts' => $candidates->map(fn ($u) => [
                    'salon_id' => $u->salon_id,
                    'salon' => optional($u->salon)->name,
                ])->values(),
            ], 409);
        }

        $user = $candidates->count() > 1
            ? $candidates->firstWhere('salon_id', (int) $data['salon_id'])
            : $candidates->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $this->otp->consume($otp);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'salon_id']),
        ]);
    }
}
