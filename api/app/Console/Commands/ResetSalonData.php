<?php

namespace App\Console\Commands;

use App\Models\Salon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipe a salon's operational data (branches, staff, services, categories,
 * customers, bookings, hours, time-off) back to an empty account — keeping the
 * salon itself and its owner. Useful for testing the onboarding flow.
 */
class ResetSalonData extends Command
{
    protected $signature = 'salon:reset {salon : slug or id} {--force : skip confirmation}';

    protected $description = "Clear a salon's branches, staff, services, customers and bookings (keeps the owner)";

    public function handle(): int
    {
        $arg = (string) $this->argument('salon');
        $salon = Salon::where('slug', $arg)
            ->when(is_numeric($arg), fn ($q) => $q->orWhere('id', (int) $arg))
            ->first();

        if (! $salon) {
            $this->error("Salon '{$this->argument('salon')}' not found.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Wipe ALL branches/staff/services/customers/bookings for '{$salon->name}'? The owner stays.")) {
            return self::SUCCESS;
        }

        $sid = $salon->id;

        DB::transaction(function () use ($sid) {
            $serviceIds = DB::table('services')->where('salon_id', $sid)->pluck('id');
            $branchIds = DB::table('branches')->where('salon_id', $sid)->pluck('id');

            DB::table('appointments')->where('salon_id', $sid)->delete();
            DB::table('offers')->where('salon_id', $sid)->delete();
            DB::table('time_off')->where('salon_id', $sid)->delete();
            DB::table('working_hours')->where('salon_id', $sid)->delete();
            DB::table('service_staff')->whereIn('service_id', $serviceIds)->delete();
            DB::table('branch_staff')->whereIn('branch_id', $branchIds)->delete();
            DB::table('services')->where('salon_id', $sid)->delete();
            DB::table('service_categories')->where('salon_id', $sid)->delete();
            DB::table('customers')->where('salon_id', $sid)->delete();
            DB::table('branches')->where('salon_id', $sid)->delete();
            DB::table('users')->where('salon_id', $sid)->where('role', 'staff')->delete();
        });

        $this->info("Reset '{$salon->name}' — owner kept, everything else cleared.");

        return self::SUCCESS;
    }
}
