<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Onboarding\MenuScanner;
use App\Support\ServiceCategoryPresets;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = Service::with(['category:id,name,name_en', 'staff:id,name,title'])
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
            'menu' => ['required', 'array', 'min:1', 'max:5'],
            'menu.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $images = [];
        foreach ($request->file('menu') as $file) {
            $images[] = [
                'data' => base64_encode((string) file_get_contents($file->getRealPath())),
                'media_type' => $file->getMimeType(),
            ];
        }

        try {
            $services = $scanner->scan($images);
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
        $salonId = Tenancy::id();

        $data = $request->validate([
            'services' => ['required', 'array', 'min:1', 'max:200'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.name_en' => ['nullable', 'string', 'max:255'],
            'services.*.duration_min' => ['required', 'integer', 'min:5', 'max:600'],
            'services.*.price' => ['required', 'numeric', 'min:0'],
            'services.*.category' => ['nullable', 'string', 'max:255'],
            'services.*.category_key' => ['nullable', 'string', Rule::in(ServiceCategoryPresets::keys())],
            // Optionally assign every imported service to these staff up front.
            'staff_ids' => ['sometimes', 'array'],
            'staff_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('salon_id', $salonId)->where('role', 'staff')),
            ],
        ]);

        $staffIds = $data['staff_ids'] ?? [];

        $cache = [];                                                // cache-key => category id
        $nextSort = (int) (ServiceCategory::max('sort_order') ?? -1) + 1;
        $created = 0;

        foreach ($data['services'] as $row) {
            $categoryId = $this->resolveCategory($row, $cache, $nextSort);

            $service = Service::create([
                'name' => $row['name'],
                'name_en' => $row['name_en'] ?? null,
                'duration_min' => $row['duration_min'],
                'price' => $row['price'],
                'service_category_id' => $categoryId,
                'is_active' => true,
            ]);

            if ($staffIds) {
                $service->staff()->attach($staffIds);
            }

            $created++;
        }

        return response()->json(['created' => $created], 201);
    }

    /**
     * Resolve a menu row to a category id. Prefers the canonical preset key
     * (creating a bilingual category, or reusing one that already represents
     * it in either language); otherwise falls back to the raw heading text.
     * `$cache` and `$nextSort` are threaded across rows to dedupe + order.
     *
     * @param  array<string, int>  $cache
     */
    protected function resolveCategory(array $row, array &$cache, int &$nextSort): ?int
    {
        $key = trim((string) ($row['category_key'] ?? ''));
        $preset = $key !== '' ? ServiceCategoryPresets::find($key) : null;

        if ($preset) {
            $ck = 'key:' . $preset['key'];
            if (! isset($cache[$ck])) {
                $names = [$preset['en'], $preset['ar']];
                $existing = ServiceCategory::where(fn ($q) => $q
                    ->whereIn('name', $names)->orWhereIn('name_en', $names))->first();

                $cache[$ck] = $existing
                    ? $existing->id
                    : ServiceCategory::create([
                        'name' => $preset['ar'], 'name_en' => $preset['en'], 'sort_order' => $nextSort++,
                    ])->id;
            }

            return $cache[$ck];
        }

        $label = trim((string) ($row['category'] ?? ''));
        if ($label === '') {
            return null;
        }

        $ck = 'text:' . mb_strtolower($label);
        if (! isset($cache[$ck])) {
            $cat = ServiceCategory::firstOrCreate(['name' => $label], ['sort_order' => $nextSort]);
            if ($cat->wasRecentlyCreated) {
                $nextSort++;
            }
            $cache[$ck] = $cat->id;
        }

        return $cache[$ck];
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
            'name_en' => ['nullable', 'string', 'max:255'],
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
