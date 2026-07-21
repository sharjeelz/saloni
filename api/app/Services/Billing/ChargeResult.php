<?php

namespace App\Services\Billing;

/** Outcome of a gateway charge. */
class ChargeResult
{
    public function __construct(
        public bool $paid,
        public ?string $reference = null,   // gateway charge/payment id
        public ?string $cardBrand = null,   // mada | visa | mastercard
        public ?string $cardLast4 = null,
        public ?string $failureMessage = null,
    ) {}

    public static function paid(string $reference, ?string $brand = null, ?string $last4 = null): self
    {
        return new self(true, $reference, $brand, $last4);
    }

    public static function failed(string $message): self
    {
        return new self(false, failureMessage: $message);
    }
}
