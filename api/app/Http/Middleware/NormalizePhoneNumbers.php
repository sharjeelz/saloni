<?php

namespace App\Http\Middleware;

use App\Support\Phone;
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

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->fields as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $request->merge([$field => Phone::canonicalize($value)]);
            }
        }

        return $next($request);
    }
}
