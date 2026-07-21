<?php

namespace App\Services\Billing;

use App\Exceptions\PaymentFailedException;
use App\Models\Invoice;
use App\Models\Salon;
use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Subscription lifecycle: subscribe (E9-1), recurring renewal (E9-2), and the
 * ZATCA tax invoice for each successful charge (E9-3). Money math is done in
 * SAR with 15% VAT; charges go to the gateway in halalas.
 */
class BillingService
{
    public function __construct(protected PaymentGateway $gateway) {}

    /** Subscribe (or switch plan). Charges the source, then activates. */
    public function subscribe(Salon $salon, string $planKey, ?string $source): array
    {
        [$plan, $price, $vat, $total, $currency] = $this->priceFor($planKey);

        $result = $this->gateway->charge(
            $this->toMinor($total), $currency, $source,
            ['description' => "Salooni {$plan['name']} plan"],
        );

        if (! $result->paid) {
            throw new PaymentFailedException($result->failureMessage ?? 'Payment declined.');
        }

        $subscription = Subscription::updateOrCreate(
            ['salon_id' => $salon->id],
            [
                'plan' => $planKey,
                'status' => 'active',
                'price' => $price,
                'currency' => $currency,
                'trial_ends_at' => $salon->trial_ends_at,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'gateway' => config('payments.gateway'),
                'gateway_reference' => $result->reference,
                'card_brand' => $result->cardBrand,
                'card_last4' => $result->cardLast4,
                'canceled_at' => null,
            ],
        );

        $salon->update(['plan' => $planKey]);

        $invoice = $this->issueInvoice($salon, $subscription, "{$plan['name']} plan — monthly", $price, $vat, $total, $currency, $result->reference);

        return [$subscription, $invoice];
    }

    /** Recurring renewal — charge the stored method and extend the period. */
    public function renew(Subscription $subscription): bool
    {
        $salon = $subscription->salon;
        [$plan, $price, $vat, $total, $currency] = $this->priceFor($subscription->plan);

        $result = $this->gateway->charge(
            $this->toMinor($total), $currency, null,
            ['description' => "Salooni {$plan['name']} renewal"],
        );

        if (! $result->paid) {
            $subscription->update(['status' => 'past_due']);

            return false;
        }

        $subscription->update([
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'gateway_reference' => $result->reference,
        ]);

        $this->issueInvoice($salon, $subscription, "{$plan['name']} plan — renewal", $price, $vat, $total, $currency, $result->reference);

        return true;
    }

    public function cancel(Subscription $subscription): void
    {
        $subscription->update(['status' => 'canceled', 'canceled_at' => now()]);
    }

    /** @return array{0: array, 1: float, 2: float, 3: float, 4: string} */
    protected function priceFor(string $planKey): array
    {
        $plan = config("plans.plans.$planKey");
        abort_if($plan === null, 422, 'Unknown plan.');

        $rate = (float) config('plans.vat_rate', 0.15);
        $currency = (string) config('plans.currency', 'SAR');
        $price = round((float) $plan['price'], 2);
        $vat = round($price * $rate, 2);
        $total = round($price + $vat, 2);

        return [$plan, $price, $vat, $total, $currency];
    }

    protected function issueInvoice(Salon $salon, Subscription $subscription, string $description, float $price, float $vat, float $total, string $currency, ?string $ref): Invoice
    {
        $issuedAt = now();
        $sellerName = (string) config('payments.seller.name');
        $sellerVat = (string) config('payments.seller.vat_number');

        $seq = Invoice::where('salon_id', $salon->id)->count() + 1;
        $prefix = Str::upper(Str::of($salon->slug)->replace('-', ''));
        $number = sprintf('%s-%06d', $prefix, $seq);

        $qr = ZatcaQr::build(
            $sellerName, $sellerVat, $issuedAt,
            number_format($total, 2, '.', ''),
            number_format($vat, 2, '.', ''),
        );

        return Invoice::create([
            'salon_id' => $salon->id,
            'subscription_id' => $subscription->id,
            'number' => $number,
            'status' => 'paid',
            'description' => $description,
            'subtotal' => $price,
            'vat_rate' => (float) config('plans.vat_rate', 0.15),
            'vat_amount' => $vat,
            'total' => $total,
            'currency' => $currency,
            'seller_name' => $sellerName,
            'seller_vat_number' => $sellerVat,
            'buyer_name' => $salon->name,
            'gateway_reference' => $ref,
            'zatca_qr' => $qr,
            'issued_at' => $issuedAt,
        ]);
    }

    protected function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
