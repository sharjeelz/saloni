<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew';

    protected $description = 'Charge and extend subscriptions whose period has ended (E9-2)';

    public function handle(BillingService $billing): int
    {
        // Console: no tenant pinned, so this spans all salons.
        $due = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->get();

        $renewed = 0;
        $failed = 0;
        foreach ($due as $subscription) {
            $billing->renew($subscription) ? $renewed++ : $failed++;
        }

        $this->info("Renewed {$renewed}, past due {$failed}.");

        return self::SUCCESS;
    }
}
