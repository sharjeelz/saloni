<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        $salon->update($data);

        return response()->json(['data' => $salon]);
    }
}
