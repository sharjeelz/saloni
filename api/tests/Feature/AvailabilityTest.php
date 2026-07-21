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

    public function test_staff_not_assigned_to_service_are_excluded(): void
    {
        $this->service->staff()->detach($this->staff->id);
        $this->assertSame([], $this->times());
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
}
