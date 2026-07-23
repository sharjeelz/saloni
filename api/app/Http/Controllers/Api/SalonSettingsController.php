<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SalonSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Salon::findOrFail(Tenancy::id())]);
    }

    /** Update the salon's profile & branding (E3-3). */
    public function update(Request $request): JsonResponse
    {
        $salon = Salon::findOrFail(Tenancy::id());

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'brand_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
            'notification_channel' => ['sometimes', 'string', 'in:sms,whatsapp'],
            // Social profiles — a handle or a full URL; the booking page normalizes it.
            'instagram' => ['nullable', 'string', 'max:255'],
            'facebook' => ['nullable', 'string', 'max:255'],
            'tiktok' => ['nullable', 'string', 'max:255'],
            'youtube' => ['nullable', 'string', 'max:255'],
        ]);

        $salon->update($data);

        return response()->json(['data' => $salon]);
    }

    /** Upload the salon logo (shown on the public booking page). */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            // Raster only — SVG can carry embedded <script> (stored XSS on the API origin).
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $salon = Salon::findOrFail(Tenancy::id());

        // Replace any previous upload.
        if ($salon->logo_path && str_contains($salon->logo_path, '/storage/logos/')) {
            $old = 'logos/' . basename($salon->logo_path);
            Storage::disk('public')->delete($old);
        }

        $path = $request->file('logo')->store('logos', 'public');
        $url = Storage::disk('public')->url($path);
        $salon->update(['logo_path' => $url]);

        return response()->json(['logo_path' => $url]);
    }
}

