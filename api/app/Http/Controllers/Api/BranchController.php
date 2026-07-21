<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ]);
    }
}
