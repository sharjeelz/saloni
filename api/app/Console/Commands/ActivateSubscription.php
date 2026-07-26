<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Services\Billing\BillingService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Manually activate a salon's plan after payment is collected offline (bank
 * transfer, cash, etc.). Runs the normal billing path — so it issues a proper
 * ZATCA invoice — but for an arbitrary number of months. Use while self-serve
 * billing is off (see config/payments.php).
 */
class ActivateSubscription extends Command
{
    protected $signature = 'billing:activate {salon : Salon id or slug} {plan : Plan key} {--months=1 : Paid months to grant}';

    protected $description = 'Activate a salon subscription manually after an offline payment';

    public function handle(BillingService $billing): int
    {
        $key = $this->argument('salon');
        $salon = Salon::query()
            ->where('id', is_numeric($key) ? (int) $key : 0)
            ->orWhere('slug', $key)
            ->first();

        if (! $salon) {
            $this->error("No salon matches \"{$key}\".");

            return self::FAILURE;
        }

        $plan = $this->argument('plan');
        if (! config("plans.plans.$plan")) {
            $known = implode(', ', array_keys(config('plans.plans')));
            $this->error("Unknown plan \"{$plan}\". Available: {$known}.");

            return self::FAILURE;
        }

        $months = max(1, (int) $this->option('months'));

        // Pin the tenant so BelongsToSalon scopes the subscription/invoice writes.
        Tenancy::set($salon);
        try {
            [$subscription, $invoice] = $billing->subscribe($salon, $plan, null, $months);
        } finally {
            Tenancy::clear();
        }

        $this->info("Activated {$plan} for {$salon->name} until {$subscription->current_period_end->toDateString()}.");
        $this->line("Invoice {$invoice->number} — {$invoice->total} {$invoice->currency}.");

        return self::SUCCESS;
    }
}
