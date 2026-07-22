<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept human-typed phone formats: strip spaces, dashes, parentheses etc. so
 * "+966 50 123 4567" or "050-123-4567" pass the E.164 validation rule.
 * Keeps a single leading '+'.
 */
class NormalizePhoneNumbers
{
    protected array $fields = ['phone', 'customer_phone'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->fields as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $plus = str_starts_with(trim($value), '+') ? '+' : '';
                $request->merge([$field => $plus . preg_replace('/\D/', '', $value)]);
            }
        }

        return $next($request);
    }
}
