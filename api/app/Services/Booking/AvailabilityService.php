<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes bookable time slots for a service at a branch on a given date.
 *
 * A slot is offered only when a qualified, on-shift staff member is free for
 * the whole service duration — respecting working hours, time-off, branch
 * closures, and existing appointments (no double-booking). All reasoning is
 * done in the salon's timezone.
 */
class AvailabilityService
{
    /** Granularity of offered start times, in minutes. */
    public const SLOT_STEP_MINUTES = 15;

    /**
     * @param  int|null  $ignoreAppointmentId  exclude this appointment when checking
     *                                          conflicts (used when rescheduling it)
     * @return array<int, array{time: string, staff: array<int, array{id:int, name:string}>}>
     */
    public function slots(Branch $branch, Service $service, ?int $staffId, string $date, ?int $ignoreAppointmentId = null): array
    {
        $tz = $branch->salon->timezone ?? 'Asia/Riyadh';
        $day = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        $weekday = (int) $day->dayOfWeek; // 0 = Sunday .. 6 = Saturday
        $now = Carbon::now($tz);
        $duration = (int) $service->duration_min;

        $staff = $this->eligibleStaff($branch, $service, $staffId);
        if ($staff->isEmpty()) {
            return [];
        }

        // Whole-branch closures apply to everyone.
        $branchClosures = $this->timeOffIntervals($branch->id, null, $day, $tz);

        /** @var array<string, array<int, array{id:int, name:string}>> $byTime */
        $byTime = [];

        foreach ($staff as $member) {
            $windows = $this->workingWindows($branch->id, $member->id, $weekday, $day, $tz);
            if (empty($windows)) {
                continue; // off today
            }

            $busy = array_merge(
                $branchClosures,
                $this->timeOffIntervals($branch->id, $member->id, $day, $tz),
                $this->appointmentIntervals($member->id, $day, $tz, $ignoreAppointmentId),
            );

            foreach ($windows as [$wStart, $wEnd]) {
                $cursor = $wStart->copy();
                while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($wEnd)) {
                    $slotEnd = $cursor->copy()->addMinutes($duration);

                    if ($cursor->greaterThanOrEqualTo($now) && ! $this->overlapsAny($cursor, $slotEnd, $busy)) {
                        $key = $cursor->format('H:i');
                        $byTime[$key] ??= [];
                        $byTime[$key][] = ['id' => $member->id, 'name' => $member->name];
                    }

                    $cursor->addMinutes(self::SLOT_STEP_MINUTES);
                }
            }
        }

        ksort($byTime);

        return array_values(array_map(
            fn (string $time, array $members) => ['time' => $time, 'staff' => $members],
            array_keys($byTime),
            $byTime,
        ));
    }

    /**
     * When slots() returns nothing, explain why so the booking page can show a
     * meaningful message instead of a generic "no times available":
     *   'no_staff' — no specialist performs this service at this branch
     *   'closed'   — the branch isn't open that day (not scheduled, or a
     *                whole-branch closure blankets its hours)
     *   'off'      — the branch is open but the specialist(s) are on time-off
     *   'full'     — open and staffed, but every slot is booked or in the past
     */
    public function reasonForNoSlots(Branch $branch, Service $service, ?int $staffId, string $date): string
    {
        $tz = $branch->salon->timezone ?? 'Asia/Riyadh';
        $day = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        $weekday = (int) $day->dayOfWeek;

        $staff = $this->eligibleStaff($branch, $service, $staffId);
        if ($staff->isEmpty()) {
            return 'no_staff';
        }

        $closures = $this->timeOffIntervals($branch->id, null, $day, $tz);
        $now = Carbon::now($tz);

        $anyWindow = false;      // a shift is scheduled today at all
        $anyOpen = false;        // a shift window survives the branch closure
        $anyFree = false;        // a shift window survives closure + that staff's own time-off
        $anyFutureFree = false;  // ...and part of it is still ahead of now

        foreach ($staff as $member) {
            $windows = $this->workingWindows($branch->id, $member->id, $weekday, $day, $tz);
            if (empty($windows)) {
                continue;
            }
            $anyWindow = true;
            $off = $this->timeOffIntervals($branch->id, $member->id, $day, $tz);

            foreach ($windows as [$wStart, $wEnd]) {
                if (! $this->windowCovered($wStart, $wEnd, $closures)) {
                    $anyOpen = true;
                }
                if (! $this->windowCovered($wStart, $wEnd, array_merge($closures, $off))) {
                    $anyFree = true;
                    if ($wEnd->greaterThan($now)) {
                        $anyFutureFree = true;
                    }
                }
            }
        }

        if (! $anyWindow || ! $anyOpen) {
            return 'closed';
        }
        if (! $anyFree) {
            return 'off';
        }

        // Open & staffed but every free window already elapsed today.
        return $anyFutureFree ? 'full' : 'past';
    }

    /** True when [start, end) is fully contained in a single busy interval. */
    protected function windowCovered(Carbon $start, Carbon $end, array $intervals): bool
    {
        foreach ($intervals as [$bStart, $bEnd]) {
            if ($bStart->lessThanOrEqualTo($start) && $bEnd->greaterThanOrEqualTo($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Staff who can perform this service at this branch. If nobody is explicitly
     * assigned to the service, any of the branch's workers can do it ("simple
     * booking" — the salon assigns internally). Assign staff to a service to
     * restrict it to specialists.
     */
    protected function eligibleStaff(Branch $branch, Service $service, ?int $staffId): Collection
    {
        $restrictToSpecialists = $service->staff()->exists();

        return User::query()
            ->where('role', 'staff')
            ->where('is_active', true)
            ->when($restrictToSpecialists, fn ($q) => $q->whereHas('services', fn ($s) => $s->where('services.id', $service->id)))
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id))
            ->when($staffId, fn ($q) => $q->where('id', $staffId))
            ->get(['id', 'name']);
    }

    /**
     * Recurring shift windows for the day: staff-specific rows if any exist,
     * otherwise the branch's default opening hours.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected function workingWindows(int $branchId, int $staffId, int $weekday, Carbon $day, string $tz): array
    {
        $staffRows = WorkingHour::where('branch_id', $branchId)
            ->where('user_id', $staffId)
            ->where('weekday', $weekday)
            ->get();

        $rows = $staffRows->isNotEmpty()
            ? $staffRows
            : WorkingHour::where('branch_id', $branchId)
                ->whereNull('user_id')
                ->where('weekday', $weekday)
                ->get();

        return $rows->map(fn (WorkingHour $wh) => [
            $day->copy()->setTimeFromTimeString($wh->start_time),
            $day->copy()->setTimeFromTimeString($wh->end_time),
        ])->all();
    }

    /** @return array<int, array{0: Carbon, 1: Carbon}> */
    protected function timeOffIntervals(int $branchId, ?int $userId, Carbon $day, string $tz): array
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();

        return TimeOff::where('branch_id', $branchId)
            ->when($userId === null, fn ($q) => $q->whereNull('user_id'))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where('starts_at', '<=', $dayEnd)
            ->where('ends_at', '>=', $dayStart)
            ->get()
            ->map(fn (TimeOff $t) => [
                $t->starts_at->copy()->setTimezone($tz),
                $t->ends_at->copy()->setTimezone($tz),
            ])->all();
    }

    /** Existing (non-cancelled) appointments for a staff member on the day. */
    protected function appointmentIntervals(int $staffId, Carbon $day, string $tz, ?int $ignoreId = null): array
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();

        return Appointment::where('staff_id', $staffId)
            ->where('status', '!=', 'cancelled')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get()
            ->map(fn (Appointment $a) => [
                $a->starts_at->copy()->setTimezone($tz),
                $a->ends_at->copy()->setTimezone($tz),
            ])->all();
    }

    /**
     * Half-open overlap: [start, end) intersects any busy interval.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $intervals
     */
    protected function overlapsAny(Carbon $start, Carbon $end, array $intervals): bool
    {
        foreach ($intervals as [$bStart, $bEnd]) {
            if ($start->lessThan($bEnd) && $bStart->lessThan($end)) {
                return true;
            }
        }

        return false;
    }
}
