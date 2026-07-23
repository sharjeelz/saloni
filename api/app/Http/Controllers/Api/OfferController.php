<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Salon promo banners shown at the top of the public booking page. Owner-only
 * management; the public list (active only) is served by PublicBookingController.
 */
class OfferController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Offer::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'string', 'url', 'max:2048'],
        ]);

        $path = $request->file('image')->store('offers', 'public');

        $offer = Offer::create([
            'image_path' => Storage::disk('public')->url($path),
            'caption' => $data['caption'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'sort_order' => (int) (Offer::max('sort_order') ?? -1) + 1,
            'is_active' => true,
        ]);

        return response()->json(['data' => $offer], 201);
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        $data = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'link_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $offer->update($data);

        return response()->json(['data' => $offer]);
    }

    /** Persist a new banner order — ids in the desired order. */
    public function reorder(Request $request): JsonResponse
    {
        $salonId = \App\Support\Tenancy::id();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'integer',
                Rule::exists('offers', 'id')->where(fn ($q) => $q->where('salon_id', $salonId)),
            ],
        ]);

        foreach ($data['ids'] as $i => $id) {
            Offer::where('id', $id)->update(['sort_order' => $i]);
        }

        return response()->json(['message' => 'Order saved.']);
    }

    public function destroy(Offer $offer): JsonResponse
    {
        if ($offer->image_path && str_contains($offer->image_path, '/storage/offers/')) {
            Storage::disk('public')->delete('offers/' . basename($offer->image_path));
        }

        $offer->delete();

        return response()->json(['message' => 'Offer deleted.']);
    }
}
