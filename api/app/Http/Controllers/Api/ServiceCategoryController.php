<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ServiceCategory::withCount('services')
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $category = ServiceCategory::create($this->validated($request));

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $serviceCategory->update($this->validated($request, updating: true));

        return response()->json(['data' => $serviceCategory]);
    }

    public function destroy(ServiceCategory $serviceCategory): JsonResponse
    {
        // Services keep existing; their category_id is set null (nullOnDelete).
        $serviceCategory->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
