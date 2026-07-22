<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the WhatsApp message to the log instead of
 * sending it. Active until Cloud API credentials are set in .env.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function send(string $to, string $message): bool
    {
        Log::channel('single')->info("[WhatsApp→{$to}] {$message}");

        return true;
    }
}
