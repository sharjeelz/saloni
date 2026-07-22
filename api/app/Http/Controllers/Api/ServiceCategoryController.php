<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Support\ServiceCategoryPresets;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ServiceCategory::withCount('services')
                ->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    /** Canonical bilingual categories offered as quick-picks when adding one. */
    public function presets(): JsonResponse
    {
        return response()->json(['data' => ServiceCategoryPresets::all()]);
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

    /**
     * Persist a new category order (E4-4). `ids` is the full list of the salon's
     * category ids in the desired order; sort_order is set to each id's index.
     * This order drives the grouping/pills on the public booking page.
     */
    public function reorder(Request $request): JsonResponse
    {
        $salonId = Tenancy::id();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'integer',
                Rule::exists('service_categories', 'id')->where(fn ($q) => $q->where('salon_id', $salonId)),
            ],
        ]);

        foreach ($data['ids'] as $i => $id) {
            ServiceCategory::where('id', $id)->update(['sort_order' => $i]);
        }

        return response()->json(['message' => 'Order saved.']);
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
            'name_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
