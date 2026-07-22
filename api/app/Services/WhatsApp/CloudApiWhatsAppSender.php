<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API driver (config/whatsapp.php → 'cloud'). Sends a
 * plain text message via the Graph API. Numbers are normalised to an
 * international MSISDN without '+', prepending the country code when local.
 */
class CloudApiWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        protected string $baseUrl,
        protected string $phoneNumberId,
        protected string $token,
        protected string $countryCode = '966',
    ) {}

    public function send(string $to, string $message): bool
    {
        $url = rtrim($this->baseUrl, '/') . '/' . $this->phoneNumberId . '/messages';

        $response = Http::withToken($this->token)->asJson()->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalizeNumber($to),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ]);

        if ($response->failed()) {
            Log::error('WhatsApp Cloud API error', [
                'to' => $to, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /** International MSISDN without '+', country code prepended when local. */
    protected function normalizeNumber(string $to): string
    {
        $n = ltrim(trim($to), '+');
        $n = ltrim($n, '0');

        if (! str_starts_with($n, $this->countryCode)) {
            $n = $this->countryCode . $n;
        }

        return $n;
    }
}
