<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin, cross-tenant view. Salon is the tenant root (no BelongsToSalon
 * scope), and Gate::before lets super-admins through everything.
 */
class SalonAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $salons = Salon::query()
            ->withCount(['users', 'branches', 'appointments'])
            ->when($request->query('q'), fn ($q, $term) =>
                $q->where('name', 'ilike', "%{$term}%")->orWhere('slug', 'ilike', "%{$term}%"))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($salons);
    }

    public function show(Salon $salon): JsonResponse
    {
        $salon->loadCount(['users', 'branches', 'appointments']);

        return response()->json(['data' => $salon]);
    }

    /** Suspend or reactivate a tenant. */
    public function setActive(Request $request, Salon $salon): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $salon->update($data);

        return response()->json([
            'message' => $data['is_active'] ? 'Salon reactivated.' : 'Salon suspended.',
            'data' => $salon->only(['id', 'name', 'slug', 'is_active']),
        ]);
    }
}
