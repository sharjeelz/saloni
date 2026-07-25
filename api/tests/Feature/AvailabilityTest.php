<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\AvailabilityService;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected string $tz = 'Asia/Riyadh';
    protected Salon $salon;
    protected Branch $branch;
    protected User $staff;
    protected Service $service;
    protected Carbon $date;   // a fixed future date
    protected int $weekday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salon = Salon::create(['name' => 'Glow', 'slug' => 'glow', 'timezone' => $this->tz]);
        Tenancy::set($this->salon);

        $this->branch = Branch::create(['name' => 'Olaya', 'salon_id' => $this->salon->id]);
        $this->staff = User::factory()->create([
            'salon_id' => $this->salon->id, 'role' => 'staff', 'name' => 'Lina',
        ]);
        $this->service = Service::create([
            'salon_id' => $this->salon->id, 'name' => 'Haircut', 'duration_min' => 60, 'price' => 100,
        ]);

        $this->branch->staff()->attach($this->staff->id);
        $this->service->staff()->attach($this->staff->id);

        // A week out, so the "no slots in the past" guard never interferes.
        $this->date = Carbon::today($this->tz)->addDays(7);
        $this->weekday = (int) $this->date->dayOfWeek;

        // Branch default hours 10:00–13:00 on that weekday.
        WorkingHour::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id, 'user_id' => null,
            'weekday' => $this->weekday, 'start_time' => '10:00', 'end_time' => '13:00',
        ]);
    }

    protected function times(?int $staffId = null): array
    {
        $slots = app(AvailabilityService::class)
            ->slots($this->branch, $this->service, $staffId, $this->date->format('Y-m-d'));

        return array_column($slots, 'time');
    }

    protected function appointmentAt(string $start, string $end, string $status = 'confirmed'): void
    {
        $customer = Customer::create([
            'salon_id' => $this->salon->id, 'name' => 'C', 'phone' => '+96650' . rand(1000000, 9999999),
        ]);
        Appointment::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id,
            'customer_id' => $customer->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
            // Stored in UTC (app storage tz), as the real booking flow will.
            'starts_at' => Carbon::parse($this->date->format('Y-m-d') . " $start", $this->tz)->utc(),
            'ends_at' => Carbon::parse($this->date->format('Y-m-d') . " $end", $this->tz)->utc(),
            'status' => $status,
        ]);
    }

    public function test_generates_slots_across_the_working_window(): void
    {
        $times = $this->times();

        // 60-min service, 15-min step, 10:00–13:00 → 10:00 … 12:00 = 9 slots.
        $this->assertCount(9, $times);
        $this->assertSame('10:00', $times[0]);
        $this->assertSame('12:00', end($times));
        $this->assertNotContains('12:15', $times); // wouldn't finish by 13:00
    }

    public function test_existing_appointment_blocks_overlapping_slots(): void
    {
        $this->appointmentAt('11:00', '12:00');
        $times = $this->times();

        // 10:00 (ends 11:00) and 12:00 (starts 12:00) survive; the middle is gone.
        $this->assertContains('10:00', $times);
        $this->assertContains('12:00', $times);
        $this->assertNotContains('11:00', $times);
        $this->assertNotContains('10:15', $times); // 10:15–11:15 overlaps
        $this->assertNotContains('11:30', $times);
    }

    public function test_cancelled_appointments_do_not_block(): void
    {
        $this->appointmentAt('11:00', '12:00', status: 'cancelled');
        $this->assertContains('11:00', $this->times());
    }

    public function test_time_off_blocks_slots(): void
    {
        TimeOff::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->staff->id,
            'starts_at' => Carbon::parse($this->date->format('Y-m-d') . ' 10:00', $this->tz)->utc(),
            'ends_at' => Carbon::parse($this->date->format('Y-m-d') . ' 11:00', $this->tz)->utc(),
        ]);
        $times = $this->times();

        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('10:45', $times);
        $this->assertContains('11:00', $times); // 11:00–12:00 clears the 10–11 block
    }

    public function test_staff_off_that_day_yields_no_slots(): void
    {
        // Remove branch default hours and give the staff none → off.
        WorkingHour::query()->delete();
        $this->assertSame([], $this->times());
    }

    public function test_non_specialist_is_excluded_when_a_service_has_assigned_staff(): void
    {
        // A second branch worker who is NOT assigned to this service.
        Tenancy::set($this->salon);
        $other = User::factory()->create(['salon_id' => $this->salon->id, 'role' => 'staff', 'name' => 'Noor']);
        $this->branch->staff()->attach($other->id);
        Tenancy::clear();

        // The service still has Lina assigned → specialist mode → only Lina, not Noor.
        $slots = app(AvailabilityService::class)->slots($this->branch, $this->service, null, $this->date->format('Y-m-d'));
        $ids = collect($slots)->flatMap(fn ($s) => collect($s['staff'])->pluck('id'))->unique()->values()->all();

        $this->assertSame([$this->staff->id], $ids);
    }

    public function test_staff_specific_hours_override_branch_hours(): void
    {
        // Lina works a short shift 10:00–11:00, overriding the branch default.
        WorkingHour::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->staff->id, 'weekday' => $this->weekday,
            'start_time' => '10:00', 'end_time' => '11:00',
        ]);

        // Only a single 60-min slot fits: 10:00.
        $this->assertSame(['10:00'], $this->times());
    }

    public function test_public_availability_endpoint_returns_slots(): void
    {
        Tenancy::clear(); // hitting HTTP; controller pins the tenant itself

        $res = $this->getJson(
            "/api/book/glow/availability?branch_id={$this->branch->id}"
            . "&service_id={$this->service->id}&date={$this->date->format('Y-m-d')}"
        );

        $res->assertOk()
            ->assertJsonPath('meta.service.duration_min', 60)
            ->assertJsonPath('data.0.time', '10:00');
    }

    public function test_public_endpoints_reject_inactive_salon(): void
    {
        Tenancy::clear();
        $this->salon->update(['is_active' => false]);

        $this->getJson('/api/book/glow/services')->assertNotFound();
    }

    // ---- no-slot reasons -----------------------------------------------------

    protected function reason(?int $staffId = null, ?string $date = null): string
    {
        return app(AvailabilityService::class)
            ->reasonForNoSlots($this->branch, $this->service, $staffId, $date ?? $this->date->format('Y-m-d'));
    }

    protected function closeAllDay(?int $userId): void
    {
        TimeOff::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id, 'user_id' => $userId,
            'starts_at' => Carbon::parse($this->date->format('Y-m-d') . ' 00:00', $this->tz)->utc(),
            'ends_at' => Carbon::parse($this->date->format('Y-m-d') . ' 23:59', $this->tz)->utc(),
        ]);
    }

    public function test_reason_is_closed_for_a_whole_branch_closure(): void
    {
        $this->closeAllDay(null); // whole-branch closure
        $this->assertEmpty($this->times());
        $this->assertSame('closed', $this->reason());
    }

    public function test_reason_is_closed_when_branch_is_not_open_that_weekday(): void
    {
        $otherWeekday = $this->date->copy()->addDay()->format('Y-m-d'); // no hours set
        $this->assertSame('closed', $this->reason(null, $otherWeekday));
    }

    public function test_reason_is_off_when_the_specialist_is_on_time_off(): void
    {
        $this->closeAllDay($this->staff->id); // just this staff, branch still open
        $this->assertEmpty($this->times());
        $this->assertSame('off', $this->reason());
    }

    public function test_reason_is_full_when_booked_out(): void
    {
        $this->appointmentAt('10:00', '13:00'); // one long booking eats the whole window
        $this->assertEmpty($this->times());
        $this->assertSame('full', $this->reason());
    }

    public function test_service_with_no_assigned_staff_is_bookable_by_any_branch_worker(): void
    {
        // A service nobody is explicitly assigned to; the branch already has Lina.
        Tenancy::set($this->salon);
        $orphan = Service::create(['salon_id' => $this->salon->id, 'name' => 'Blowdry', 'duration_min' => 60, 'price' => 80]);
        Tenancy::clear();

        $slots = app(AvailabilityService::class)->slots($this->branch, $orphan, null, $this->date->format('Y-m-d'));

        $this->assertNotEmpty($slots); // simple booking — any branch worker can do it
        $this->assertSame($this->staff->id, $slots[0]['staff'][0]['id']);
    }

    public function test_reason_is_no_staff_only_when_the_branch_has_no_workers(): void
    {
        // Orphan service AND no worker attached to the branch → genuinely un-bookable.
        Tenancy::set($this->salon);
        $orphan = Service::create(['salon_id' => $this->salon->id, 'name' => 'Nails', 'duration_min' => 30, 'price' => 50]);
        $bareBranch = \App\Models\Branch::create(['name' => 'Empty', 'salon_id' => $this->salon->id]);
        Tenancy::clear();
        $svc = app(AvailabilityService::class);

        $this->assertSame('no_staff', $svc->reasonForNoSlots($bareBranch, $orphan, null, $this->date->format('Y-m-d')));
    }

    public function test_public_availability_returns_a_reason_when_empty(): void
    {
        Tenancy::clear();
        $this->closeAllDay(null);

        $this->getJson("/api/book/glow/availability?branch_id={$this->branch->id}&service_id={$this->service->id}&date={$this->date->format('Y-m-d')}")
            ->assertOk()
            ->assertJsonPath('meta.reason', 'closed')
            ->assertJsonCount(0, 'data');
    }
}
