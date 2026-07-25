<?php

namespace App\Services\Booking;

use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single place an appointment is created — online or walk-in. Converts the
 * salon-local slot to UTC for storage and re-checks for conflicts inside a
 * transaction so two people can't grab the same slot.
 */
class BookingService
{
    public function __construct(protected AvailabilityService $availability) {}

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
        ?string $note = null,
    ): Appointment {
        $tz = $branch->salon->timezone ?? 'Asia/Riyadh';
        $localStart = Carbon::parse("$date $time", $tz);

        // Re-validate against the real availability rules — a client must not be
        // able to book outside working hours, during time-off, or in the past
        // just by posting an arbitrary date/time (server trusts nothing).
        $this->assertBookable($branch, $service, $staff, $date, $time);

        // Stored/compared in UTC (see AvailabilityService — same convention).
        $start = $localStart->clone()->utc();
        $end = $localStart->clone()->addMinutes((int) $service->duration_min)->utc();

        try {
            return DB::transaction(function () use ($branch, $service, $staff, $customer, $start, $end, $source, $note) {
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
                    'reference' => $this->uniqueReference(),
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                    'staff_id' => $staff->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => 'confirmed',
                    'source' => $source,
                    'price' => $service->price,
                    'customer_note' => $note,
                ]);
            });
        } catch (QueryException $e) {
            // The DB exclusion constraint is the real race guard — a concurrent
            // booking that slipped past the check surfaces here.
            if ($this->isOverlapViolation($e)) {
                throw new SlotUnavailableException();
            }
            throw $e;
        }
    }

    /**
     * Move an existing appointment to a new slot, preserving its identity
     * (same public_token / manage link). BUG-3 fix — reschedule no longer
     * cancels and re-creates.
     *
     * @throws SlotUnavailableException
     */
    public function reschedule(Appointment $appointment, string $date, string $time): Appointment
    {
        $appointment->loadMissing('branch.salon', 'service', 'staff');
        $branch = $appointment->branch;
        $service = $appointment->service;
        $staff = $appointment->staff;

        $tz = $branch->salon->timezone ?? 'Asia/Riyadh';
        $localStart = Carbon::parse("$date $time", $tz);

        // Validate the new slot, ignoring this appointment's own time.
        $this->assertBookable($branch, $service, $staff, $date, $time, ignoreAppointmentId: $appointment->id);

        $start = $localStart->clone()->utc();
        $end = $localStart->clone()->addMinutes((int) $service->duration_min)->utc();

        try {
            return DB::transaction(function () use ($appointment, $staff, $start, $end) {
                $conflict = Appointment::where('staff_id', $staff->id)
                    ->where('id', '!=', $appointment->id)
                    ->where('status', '!=', 'cancelled')
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->lockForUpdate()
                    ->exists();

                if ($conflict) {
                    throw new SlotUnavailableException();
                }

                $appointment->update([
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => 'confirmed',
                    'cancelled_at' => null,
                    'reminder_sent_at' => null, // re-remind for the new time
                ]);

                return $appointment;
            });
        } catch (QueryException $e) {
            if ($this->isOverlapViolation($e)) {
                throw new SlotUnavailableException();
            }
            throw $e;
        }
    }

    /** Postgres exclusion-constraint violation (SQLSTATE 23P01) from the no-overlap guard. */
    protected function isOverlapViolation(QueryException $e): bool
    {
        return $e->getCode() === '23P01';
    }

    /**
     * Guard: the requested slot must be offered by the availability engine —
     * within working hours, not during time-off/closure, not in the past, with
     * an eligible staff member.
     *
     * @throws SlotUnavailableException
     */
    protected function assertBookable(Branch $branch, Service $service, User $staff, string $date, string $time, ?int $ignoreAppointmentId = null): void
    {
        $offered = collect($this->availability->slots($branch, $service, $staff->id, $date, $ignoreAppointmentId))
            ->pluck('time');

        if (! $offered->contains($time)) {
            throw new SlotUnavailableException('That time is not available.');
        }
    }

    /**
     * A short, human-quotable, globally-unique booking reference. Uses an
     * unambiguous alphabet (no 0/O/1/I/L) so it's easy to read out loud.
     */
    protected function uniqueReference(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        do {
            $ref = '';
            for ($i = 0; $i < 6; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Appointment::withoutGlobalScope('salon')->where('reference', $ref)->exists());

        return $ref;
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
