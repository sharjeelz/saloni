<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Onboarding\MenuScanner;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = Service::with(['category:id,name', 'staff:id,name,title'])
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
     * Read a photo of the salon's price list and return a *preview* list of
     * services (E13-1). Nothing is saved — the owner reviews it, then calls
     * import(). Keeps setup to a 5-minute favour instead of a long form.
     */
    public function scanMenu(Request $request, MenuScanner $scanner): JsonResponse
    {
        $request->validate([
            'menu' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $file = $request->file('menu');

        try {
            $services = $scanner->scan(
                base64_encode((string) file_get_contents($file->getRealPath())),
                $file->getMimeType(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['services' => $services]);
    }

    /**
     * Create services (and any missing categories) from a reviewed menu import
     * (E13-1). Categories are matched by name within the salon and created in
     * menu order; services are added active.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'services' => ['required', 'array', 'min:1', 'max:200'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.duration_min' => ['required', 'integer', 'min:5', 'max:600'],
            'services.*.price' => ['required', 'numeric', 'min:0'],
            'services.*.category' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryIds = [];                                          // lowercased name => id
        $nextSort = (int) (ServiceCategory::max('sort_order') ?? -1) + 1;
        $created = 0;

        foreach ($data['services'] as $row) {
            $categoryId = null;
            $catName = trim((string) ($row['category'] ?? ''));

            if ($catName !== '') {
                $key = mb_strtolower($catName);
                if (! isset($categoryIds[$key])) {
                    $cat = ServiceCategory::firstOrCreate(['name' => $catName], ['sort_order' => $nextSort]);
                    if ($cat->wasRecentlyCreated) {
                        $nextSort++;
                    }
                    $categoryIds[$key] = $cat->id;
                }
                $categoryId = $categoryIds[$key];
            }

            Service::create([
                'name' => $row['name'],
                'duration_min' => $row['duration_min'],
                'price' => $row['price'],
                'service_category_id' => $categoryId,
                'is_active' => true,
            ]);
            $created++;
        }

        return response()->json(['created' => $created], 201);
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
