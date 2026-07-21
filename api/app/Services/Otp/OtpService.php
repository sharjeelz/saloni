<?php

namespace App\Services\Otp;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * We own the whole OTP lifecycle: generate a code, hash & store it in our
 * `otp_codes` table, deliver it via the SMS gateway (or email), and verify
 * it later. No third-party managed-OTP service is used.
 */
class OtpService
{
    public const TTL_MINUTES = 5;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_THROTTLE_SECONDS = 60;

    public function __construct(protected SmsSender $sms) {}

    /**
     * Create & send a code. Returns the OtpCode row (without the plain code).
     * In non-production the plain code is returned so it can be surfaced for testing.
     */
    public function request(string $channel, string $destination, string $purpose = 'login', ?int $salonId = null): array
    {
        $scope = fn ($q) => $q->where('channel', $channel)
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('salon_id', $salonId);

        // Throttle: block a new code if one was just issued (same scope).
        $recent = OtpCode::query()->tap($scope)
            ->whereNull('consumed_at')
            ->where('created_at', '>', now()->subSeconds(self::RESEND_THROTTLE_SECONDS))
            ->exists();

        if ($recent) {
            return ['throttled' => true];
        }

        // Invalidate prior live codes for this exact scope only.
        OtpCode::query()->tap($scope)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);

        $otp = OtpCode::create([
            'channel' => $channel,
            'purpose' => $purpose,
            'salon_id' => $salonId,
            'destination' => $destination,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $message = "Your Salon verification code is {$code}. It expires in "
            . self::TTL_MINUTES . ' minutes.';

        if ($channel === 'phone') {
            $this->sms->send($destination, $message);
        } else {
            Mail::raw($message, fn ($m) => $m->to($destination)->subject('Your verification code'));
        }

        return [
            'throttled' => false,
            'otp_id' => $otp->id,
            'expires_at' => $otp->expires_at,
            // Only exposed outside production to ease testing.
            'debug_code' => app()->environment('production') ? null : $code,
        ];
    }

    /**
     * Verify a submitted code. Returns true on success (and consumes the code).
     */
    public function verify(string $channel, string $destination, string $code, string $purpose = 'login', ?int $salonId = null): bool
    {
        $otp = OtpCode::where('channel', $channel)
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('salon_id', $salonId)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->isExpired() || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
