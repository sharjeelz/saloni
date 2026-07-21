<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = Service::with('category:id,name')
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->when($request->query('category'), fn ($q, $id) => $q->where('service_category_id', $id))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $services]);
    }

    public function store(Request $request): JsonResponse
    {
        $service = Service::create($this->validated($request));

        return response()->json(['data' => $service->load('category:id,name')], 201);
    }

    public function show(Service $service): JsonResponse
    {
        return response()->json([
            'data' => $service->load('category:id,name', 'staff:id,name,title'),
        ]);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $service->update($this->validated($request, updating: true));

        return response()->json(['data' => $service->load('category:id,name')]);
    }

    public function destroy(Service $service): JsonResponse
    {
        $service->delete();

        return response()->json(['message' => 'Service deleted.']);
    }

    /**
     * Set which staff can perform this service (E4-2). Replaces the full set.
     */
    public function syncStaff(Request $request, Service $service): JsonResponse
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

        $service->staff()->sync($data['staff_ids']);

        return response()->json([
            'data' => $service->load('staff:id,name,title'),
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        $salonId = Tenancy::id();

        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_min' => [$updating ? 'sometimes' : 'required', 'integer', 'min:5', 'max:600'],
            'price' => [$updating ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['boolean'],
            'service_category_id' => [
                'nullable',
                Rule::exists('service_categories', 'id')->where(fn ($q) => $q->where('salon_id', $salonId)),
            ],
        ]);
    }
}
