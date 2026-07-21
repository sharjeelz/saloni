<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Salon;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    /** Available plans with VAT-inclusive totals. */
    public function plans(): JsonResponse
    {
        $rate = (float) config('plans.vat_rate', 0.15);
        $currency = config('plans.currency', 'SAR');

        $plans = collect(config('plans.plans'))->map(function ($plan, $key) use ($rate, $currency) {
            $vat = round($plan['price'] * $rate, 2);

            return [
                'key' => $key,
                'name' => $plan['name'],
                'price' => (float) $plan['price'],
                'vat' => $vat,
                'total' => round($plan['price'] + $vat, 2),
                'currency' => $currency,
                'interval' => $plan['interval'],
                'features' => $plan['features'],
            ];
        })->values();

        return response()->json(['data' => $plans, 'vat_rate' => $rate]);
    }

    /** Current billing status (trial or subscription). */
    public function show(): JsonResponse
    {
        $salon = Salon::findOrFail(Tenancy::id());
        $subscription = Subscription::first();

        return response()->json([
            'plan' => $salon->plan,
            'trial_ends_at' => $salon->trial_ends_at,
            'on_trial' => $salon->trial_ends_at?->isFuture() && ! ($subscription?->isActive()),
            'subscription' => $subscription,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $salon = Salon::findOrFail(Tenancy::id());

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('plans.plans')))],
            'source' => ['nullable', 'string'], // gateway payment token
        ]);

        try {
            [$subscription, $invoice] = $this->billing->subscribe($salon, $data['plan'], $data['source'] ?? null);
        } catch (PaymentFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        return response()->json([
            'message' => 'Subscription active.',
            'subscription' => $subscription,
            'invoice' => $invoice->only(['id', 'number', 'total', 'currency', 'zatca_qr']),
        ], 201);
    }

    public function cancel(): JsonResponse
    {
        $subscription = Subscription::firstOrFail();
        $this->billing->cancel($subscription);

        return response()->json(['message' => 'Subscription canceled.', 'subscription' => $subscription]);
    }

    public function invoices(): JsonResponse
    {
        return response()->json([
            'data' => Invoice::orderByDesc('issued_at')->get(),
        ]);
    }

    public function invoice(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => $invoice]);
    }
}
