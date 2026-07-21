<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Moyasar — KSA payment gateway (mada + cards). The client tokenizes the card
 * with Moyasar.js and sends the token as `source`; we create the payment
 * server-side with the secret key. Configure via config/payments.php.
 */
class MoyasarPaymentGateway implements PaymentGateway
{
    public function __construct(
        protected string $secretKey,
        protected string $baseUrl = 'https://api.moyasar.com/v1',
    ) {}

    public function charge(int $amountMinor, string $currency, ?string $source, array $meta = []): ChargeResult
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->asForm()
            ->post("{$this->baseUrl}/payments", [
                'amount' => $amountMinor,
                'currency' => $currency,
                'description' => $meta['description'] ?? 'Subscription',
                'source[type]' => 'token',
                'source[token]' => $source,
            ]);

        if ($response->failed()) {
            Log::error('Moyasar charge HTTP error', ['status' => $response->status(), 'body' => $response->body()]);

            return ChargeResult::failed('Payment could not be processed.');
        }

        $payment = $response->json();
        if (($payment['status'] ?? null) !== 'paid') {
            return ChargeResult::failed($payment['source']['message'] ?? 'Payment was declined.');
        }

        return ChargeResult::paid(
            $payment['id'],
            $payment['source']['company'] ?? null,
            $payment['source']['number'] ? substr($payment['source']['number'], -4) : null,
        );
    }
}
