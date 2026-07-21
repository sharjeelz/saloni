<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route to one or more roles: ->middleware('role:owner') or
 * ->middleware('role:owner,super_admin'). super_admin always passes.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin() || in_array($user->role, $roles, true)) {
            return $next($request);
        }

        return response()->json(['message' => 'This action is not allowed for your role.'], 403);
    }
}
