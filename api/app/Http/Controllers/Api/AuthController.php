<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Owner signup — provisions a new salon (tenant) + its owner account
     * in a single transaction. Returns a Sanctum token.
     */
    public function signup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'salon_name' => ['required', 'string', 'min:2', 'max:255'],
            'owner_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', \App\Support\ValidationRules::PHONE],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $salon = Salon::create([
                'name' => $data['salon_name'],
                'slug' => $this->uniqueSlug($data['salon_name']),
                'phone' => $data['phone'],
                'plan' => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]);

            Tenancy::set($salon);

            $owner = User::create([
                'salon_id' => $salon->id,
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'role' => 'owner',
                'password' => Hash::make($data['password']),
            ]);

            return [$salon, $owner];
        });

        [$salon, $owner] = $result;
        $token = $owner->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $owner->only(['id', 'name', 'email', 'phone', 'role', 'salon_id']),
            'salon' => $salon->only(['id', 'name', 'slug', 'plan', 'trial_ends_at']),
        ], 201);
    }

    /**
     * Password login by email (owner/staff console). OTP login is handled
     * by OtpController for the phone-first flow.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScope('salon')->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'salon_id']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'salon_id', 'title']),
            'salon' => $user->salon
                ? array_merge(
                    $user->salon->only(['id', 'name', 'slug', 'plan', 'trial_ends_at', 'timezone', 'locale']),
                    ['locked' => $user->salon->isTrialLocked()],
                )
                : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'salon';
        $slug = $base;
        $i = 1;

        while (Salon::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }
}
