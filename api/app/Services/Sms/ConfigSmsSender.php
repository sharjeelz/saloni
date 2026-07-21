<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generic, config-driven SMS gateway (config/sms.php). The active gateway is
 * chosen by `sms.default`; its request is assembled from the gateway's
 * `params` map. We own the OTP — the gateway only delivers the message body.
 */
class ConfigSmsSender implements SmsSender
{
    public function __construct(
        protected array $gateway,
        protected string $countryCode = '966',
    ) {}

    public function send(string $to, string $message): bool
    {
        $number = $this->normalizeNumber($to);

        $params = $this->gateway['params'];
        $body = array_merge(
            [
                $params['send_to_param_name'] => $number,
                $params['msg_param_name'] => $message,
            ],
            $params['others'] ?? [],
        );

        $method = strtoupper($this->gateway['method'] ?? 'POST');
        $client = ($this->gateway['json'] ?? false) ? Http::asJson() : Http::asForm();

        $response = $client->send($method, $this->gateway['url'], ['body' => null] + [
            ($this->gateway['json'] ?? false) ? 'json' : 'form_params' => $body,
        ]);

        // HTTP-level failure.
        if ($response->failed()) {
            Log::error('SMS gateway HTTP error', [
                'to' => $to, 'status' => $response->status(), 'body' => $response->body(),
            ]);

            return false;
        }

        // Msegat-style application code check when present ("1" / "M0000" = OK).
        $code = $response->json('code');
        if ($code !== null && ! in_array((string) $code, ['1', 'M0000'], true)) {
            Log::error('SMS gateway rejected message', [
                'to' => $to, 'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Ensure an international MSISDN without '+'. Optionally prepends the
     * country code (strips a local leading zero) when the gateway asks for it.
     */
    protected function normalizeNumber(string $to): string
    {
        $n = ltrim(trim($to), '+');

        if ($this->gateway['add_code'] ?? false) {
            $n = ltrim($n, '0');
            if (! str_starts_with($n, $this->countryCode)) {
                $n = $this->countryCode . $n;
            }
        }

        return $n;
    }
}
