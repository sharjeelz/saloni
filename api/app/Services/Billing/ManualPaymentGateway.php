<?php

namespace App\Services\Billing;

use Illuminate\Support\Str;

/**
 * Dev/testing gateway: approves every charge without contacting a provider.
 * Active until real Moyasar credentials are configured.
 */
class ManualPaymentGateway implements PaymentGateway
{
    public function charge(int $amountMinor, string $currency, ?string $source, array $meta = []): ChargeResult
    {
        return ChargeResult::paid('manual_' . Str::random(20), 'mada', '0000');
    }
}
