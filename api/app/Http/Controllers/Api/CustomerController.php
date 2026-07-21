<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /** Directory + search (by name or phone). Tenant-scoped. */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::withCount('appointments')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) =>
                $w->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                    ->orWhere('phone', 'like', "%{$q}%")))
            ->orderByDesc('last_visit_at')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'phone', 'email', 'last_visit_at']);

        return response()->json(['data' => $customers]);
    }

    /** A customer profile with their booking history. */
    public function show(Customer $customer): JsonResponse
    {
        $customer->loadCount('appointments');
        $history = $customer->appointments()
            ->with(['service:id,name', 'staff:id,name', 'branch:id,name'])
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get(['id', 'branch_id', 'service_id', 'staff_id', 'starts_at', 'status', 'price']);

        return response()->json([
            'data' => $customer,
            'history' => $history,
        ]);
    }

    /** Edit a customer's profile (name, email, notes). */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer->update($data);

        return response()->json(['data' => $customer]);
    }
}
