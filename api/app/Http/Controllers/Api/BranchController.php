<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    /** List the salon's branches (tenant-scoped). */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Branch::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = Branch::create($this->validated($request));

        return response()->json(['data' => $branch], 201);
    }

    /** Route-model binding is tenant-scoped, so cross-salon ids 404 here. */
    public function show(Branch $branch): JsonResponse
    {
        return response()->json([
            'data' => $branch->load('workingHours', 'staff:id,name,title'),
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $branch->update($this->validated($request, updating: true));

        return response()->json(['data' => $branch]);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $branch->delete();

        return response()->json(['message' => 'Branch deleted.']);
    }

    /** Set which staff work at this branch (needed for availability). */
    public function syncStaff(Request $request, Branch $branch): JsonResponse
    {
        $salonId = Tenancy::id();

        $data = $request->validate([
            'staff_ids' => ['present', 'array'],
            'staff_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) =>
                    $q->where('salon_id', $salonId)->where('role', 'staff')),
            ],
        ]);

        $branch->staff()->sync($data['staff_ids']);

        return response()->json(['data' => $branch->load('staff:id,name,title')]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'maps_url' => ['nullable', 'string', 'url', 'max:2048'],
            'phone' => ['nullable', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ]);
    }
}
