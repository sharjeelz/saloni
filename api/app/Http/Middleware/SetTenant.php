<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the current salon for the request from the authenticated user, so the
 * BelongsToSalon global scope isolates all queries. Super-admins have no salon
 * and therefore see across tenants (guarded separately by role).
 */
class SetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->salon_id) {
            Tenancy::set($user->salon_id);

            // A super-admin can suspend a tenant; block its members' access.
            if ($user->salon && ! $user->salon->is_active) {
                abort(403, 'This salon is suspended.');
            }
        }

        return $next($request);
    }
}
