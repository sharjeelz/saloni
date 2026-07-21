<?php

namespace App\Services\Booking;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single place an appointment is created — online or walk-in. Converts the
 * salon-local slot to UTC for storage and re-checks for conflicts inside a
 * transaction so two people can't grab the same slot.
 */
class BookingService
{
    /**
     * @param  string  $date  Y-m-d in the salon's timezone
     * @param  string  $time  H:i in the salon's timezone
     *
     * @throws SlotUnavailableException
     */
    public function book(
        Branch $branch,
        Service $service,
        User $staff,
        Customer $customer,
        string $date,
        string $time,
        string $source = 'online',
    ): Appointment {
        $tz = $branch->salon->timezone ?? 'Asia/Riyadh';
        $localStart = Carbon::parse("$date $time", $tz);

        // Stored/compared in UTC (see AvailabilityService — same convention).
        $start = $localStart->clone()->utc();
        $end = $localStart->clone()->addMinutes((int) $service->duration_min)->utc();

        return DB::transaction(function () use ($branch, $service, $staff, $customer, $start, $end, $source) {
            // Lock the staff's overlapping rows to serialize concurrent bookings.
            $conflict = Appointment::where('staff_id', $staff->id)
                ->where('status', '!=', 'cancelled')
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw new SlotUnavailableException();
            }

            return Appointment::create([
                'public_token' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => 'confirmed',
                'source' => $source,
                'price' => $service->price,
            ]);
        });
    }

    /**
     * Find or create the customer for this salon by phone, refreshing the name.
     */
    public function resolveCustomer(string $name, string $phone): Customer
    {
        $customer = Customer::firstOrNew(['phone' => $phone]);
        $customer->name = $name;
        $customer->save();

        return $customer;
    }
}
