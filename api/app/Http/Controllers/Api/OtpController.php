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
        ]);

        if (! $this->otp->verify('phone', $data['phone'], $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::withoutGlobalScope('salon')
            ->where('phone', $data['phone'])
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'No account found for this number.'], 404);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'salon_id']),
        ]);
    }
}
