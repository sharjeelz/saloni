<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonicalize human-typed phone numbers to a single E.164 form so the same
 * person is always one identity. KSA customers type the local "05…" form, but
 * "0501234567", "0555 123 456", "966501234567" and "+966501234567" must all
 * become "+966501234567" — otherwise customer lookup, dedupe, and the
 * one-active-booking limit all silently break.
 */
class NormalizePhoneNumbers
{
    protected array $fields = ['phone', 'customer_phone'];

    /** Default country for bare/local numbers (KSA). */
    protected string $countryCode = '966';

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->fields as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $request->merge([$field => $this->canonicalize($value)]);
            }
        }

        return $next($request);
    }

    protected function canonicalize(string $raw): string
    {
        $hadPlus = str_starts_with(ltrim($raw), '+');
        $digits = preg_replace('/\D/', '', $raw); // digits only

        if ($digits === '') {
            return trim($raw); // nothing usable — let validation reject it
        }

        // International "00" prefix → treat as already-international.
        if (str_starts_with($digits, '00')) {
            return '+' . ltrim(substr($digits, 2), '0');
        }

        // Explicit '+' → already international, just normalized to +digits.
        if ($hadPlus) {
            return '+' . $digits;
        }

        // Already carries the country code (e.g. 966501234567).
        if (str_starts_with($digits, $this->countryCode)) {
            return '+' . $digits;
        }

        // Local trunk form (0501234567) → drop the 0, add the country code.
        if (str_starts_with($digits, '0')) {
            return '+' . $this->countryCode . substr($digits, 1);
        }

        // Bare national number (501234567) → add the country code.
        return '+' . $this->countryCode . $digits;
    }
}
