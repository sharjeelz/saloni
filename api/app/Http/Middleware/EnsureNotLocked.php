<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grace paywall: once the trial ends without a paying subscription, the console
 * goes read-only — write actions return 402 so the UI can prompt to subscribe.
 * Reads stay open, and billing + logout are always allowed so the salon can pay
 * (and get out). Runs after SetTenant, which already 403s a suspended salon.
 */
class EnsureNotLocked
{
    /** Endpoints that must stay reachable even when locked. */
    protected array $allow = ['api/billing', 'api/billing/*', 'api/auth/logout', 'api/auth/me'];

    public function handle(Request $request, Closure $next): Response
    {
        $salon = $request->user()?->salon;

        if ($salon
            && $salon->isTrialLocked()
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && ! $request->is(...$this->allow)
        ) {
            abort(402, 'Your free trial has ended. Please subscribe to continue.');
        }

        return $next($request);
    }
}
