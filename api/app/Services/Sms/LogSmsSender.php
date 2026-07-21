<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the SMS to the log instead of sending it.
 * Active until real provider credentials are set in .env.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        Log::channel('single')->info("[SMS→{$to}] {$message}");

        return true;
    }
}
