<?php

namespace App\Services\Billing;

use Carbon\CarbonInterface;

/**
 * Builds the ZATCA (Phase-1, simplified tax invoice) QR payload: a Base64
 * string of TLV-encoded fields. This Base64 is exactly what gets rendered into
 * the invoice QR code (image rendering is a presentation concern).
 *
 * Tags: 1 seller name, 2 VAT registration number, 3 timestamp (ISO-8601),
 *       4 invoice total (incl VAT), 5 VAT total.
 */
class ZatcaQr
{
    public static function build(
        string $sellerName,
        string $vatNumber,
        CarbonInterface $timestamp,
        string $total,
        string $vatTotal,
    ): string {
        $tlv =
            self::tlv(1, $sellerName)
            . self::tlv(2, $vatNumber)
            . self::tlv(3, $timestamp->toIso8601String())
            . self::tlv(4, $total)
            . self::tlv(5, $vatTotal);

        return base64_encode($tlv);
    }

    /** Tag-Length-Value: 1 byte tag, 1 byte length, then the UTF-8 value. */
    protected static function tlv(int $tag, string $value): string
    {
        return chr($tag) . chr(strlen($value)) . $value;
    }
}
