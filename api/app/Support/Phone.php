<?php

namespace App\Support;

class Phone
{
    /**
     * Canonicalize a human-typed number to E.164 (KSA default). "0501234567",
     * "966501234567", "00966501234567", "+966 50 123 4567" and the bare
     * national "501234567" all become "+966501234567".
     */
    public static function canonicalize(string $raw, string $countryCode = '966'): string
    {
        $hadPlus = str_starts_with(ltrim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw); // digits only

        if ($digits === '') {
            return trim($raw);
        }

        if (str_starts_with($digits, '00')) {
            return '+' . ltrim(substr($digits, 2), '0');
        }

        if ($hadPlus) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, $countryCode)) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+' . $countryCode . substr($digits, 1);
        }

        return '+' . $countryCode . $digits;
    }

    /** A canonicalized number that passes our E.164-ish validation rule. */
    public static function isValid(string $canonical): bool
    {
        return (bool) preg_match('/^\+?[0-9]{9,15}$/', $canonical);
    }
}
