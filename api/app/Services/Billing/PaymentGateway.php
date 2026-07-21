<?php

namespace App\Services\Billing;

interface PaymentGateway
{
    /**
     * Charge a payment source.
     *
     * @param  int  $amountMinor  amount in the currency's minor unit (halalas)
     * @param  string  $currency  ISO code, e.g. "SAR"
     * @param  string|null  $source  gateway payment token (from the client), or
     *                               null for a stored/recurring charge
     * @param  array<string, mixed>  $meta  description / customer reference
     */
    public function charge(int $amountMinor, string $currency, ?string $source, array $meta = []): ChargeResult;
}
