<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected string $tz = 'Asia/Riyadh';
    protected Salon $salon;
    protected Branch $branch;
    protected User $owner;
    protected User $staff;
    protected Service $service;
    protected Carbon $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salon = Salon::create(['name' => 'Glow', 'slug' => 'glow', 'timezone' => $this->tz]);
        Tenancy::set($this->salon);

        $this->branch = Branch::create(['name' => 'Olaya', 'salon_id' => $this->salon->id]);
        $this->owner = User::factory()->create(['salon_id' => $this->salon->id, 'role' => 'owner']);
        $this->staff = User::factory()->create(['salon_id' => $this->salon->id, 'role' => 'staff', 'name' => 'Lina']);
        $this->service = Service::create([
            'salon_id' => $this->salon->id, 'name' => 'Haircut', 'duration_min' => 60, 'price' => 100,
        ]);
        $this->branch->staff()->attach($this->staff->id);
        $this->service->staff()->attach($this->staff->id);

        $this->date = Carbon::today($this->tz)->addDays(7);
        WorkingHour::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id, 'user_id' => null,
            'weekday' => (int) $this->date->dayOfWeek, 'start_time' => '10:00', 'end_time' => '18:00',
        ]);

        Tenancy::clear();
    }

    protected function otpFor(string $phone): string
    {
        return $this->postJson('/api/book/glow/otp', ['phone' => $phone])
            ->assertOk()->json('debug_code');
    }

    // ---- E6-2 / E6-3: customer confirms with OTP ------------------------------

    public function test_customer_books_with_phone_otp(): void
    {
        $phone = '+966500000123';
        $code = $this->otpFor($phone);

        $res = $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id,
            'service_id' => $this->service->id,
            'staff_id' => $this->staff->id,
            'date' => $this->date->format('Y-m-d'),
            'time' => '11:00',
            'name' => 'Sara',
            'phone' => $phone,
            'code' => $code,
        ]);

        $res->assertCreated()->assertJsonPath('data.staff', 'Lina');

        $this->assertDatabaseHas('customers', ['phone' => $phone, 'salon_id' => $this->salon->id]);
        $this->assertDatabaseHas('appointments', [
            'salon_id' => $this->salon->id, 'status' => 'confirmed', 'source' => 'online',
        ]);
    }

    public function test_customer_limited_to_one_active_booking_until_cancelled(): void
    {
        $phone = '+966500000123';
        $book = fn (string $time) => $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => $time, 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ]);

        // First booking is fine.
        $token = $book('11:00')->assertCreated()->json('data.manage_token');

        // A second, with the same number, is blocked — and points at the first.
        $book('13:00')->assertStatus(409)->assertJsonPath('existing.manage_token', $token);

        // After cancelling the first, they can book again.
        $this->postJson("/api/book/manage/{$token}/cancel")->assertOk();
        $book('13:00')->assertCreated();
    }

    public function test_customer_can_look_up_their_bookings_by_phone_otp(): void
    {
        $phone = '+966500000123';
        $token = $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->json('data.manage_token');

        // Wrong code → no leak.
        $this->postJson('/api/book/glow/lookup', ['phone' => $phone, 'code' => '000000'])->assertStatus(422);

        // Correct OTP → returns the upcoming booking with its manage token.
        $this->postJson('/api/book/glow/lookup', ['phone' => $phone, 'code' => $this->otpFor($phone)])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.manage_token', $token);
    }

    public function test_booking_requires_a_valid_otp(): void
    {
        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => '+966500000123', 'code' => '000000',
        ])->assertStatus(422);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_double_booking_the_same_slot_is_rejected(): void
    {
        $phone = '+966500000123';

        // First booking succeeds.
        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->assertCreated();

        // Same staff, overlapping time → 409.
        $phone2 = '+966500000999';
        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:30', 'name' => 'Mona', 'phone' => $phone2, 'code' => $this->otpFor($phone2),
        ])->assertStatus(409);

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_booked_slot_disappears_from_availability(): void
    {
        $phone = '+966500000123';
        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->assertCreated();

        $slots = $this->getJson(
            "/api/book/glow/availability?branch_id={$this->branch->id}"
            . "&service_id={$this->service->id}&date={$this->date->format('Y-m-d')}"
        )->json('data');

        $this->assertNotContains('11:00', array_column($slots, 'time'));
    }

    // ---- E6-4: manage link ----------------------------------------------------

    public function test_customer_can_cancel_via_manage_link(): void
    {
        $phone = '+966500000123';
        $ref = $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->json('data.manage_token');

        $this->getJson("/api/book/manage/{$ref}")->assertOk()->assertJsonPath('data.staff.name', 'Lina');
        $this->postJson("/api/book/manage/{$ref}/cancel")->assertOk();

        $this->assertDatabaseHas('appointments', ['public_token' => $ref, 'status' => 'cancelled']);
    }

    // ---- E7: admin calendar, walk-in, status ----------------------------------

    public function test_owner_creates_walk_in_and_marks_done(): void
    {
        Sanctum::actingAs($this->owner);

        $appt = $this->postJson('/api/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'customer_name' => 'Walk In',
            'customer_phone' => '+966512345678', 'date' => $this->date->format('Y-m-d'), 'time' => '12:00',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('appointments', ['id' => $appt['id'], 'source' => 'walk_in']);

        $this->patchJson("/api/appointments/{$appt['id']}/status", ['status' => 'done'])
            ->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_cancellation_records_who_and_why(): void
    {
        $phone = '+966500000123';
        // Customer cancel via the manage link → cancelled_by = customer.
        $token = $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->json('data.manage_token');
        $this->postJson("/api/book/manage/{$token}/cancel")->assertOk();
        $this->assertDatabaseHas('appointments', ['public_token' => $token, 'cancelled_by' => 'customer']);

        // Admin cancel with a reason → cancelled_by = owner + reason.
        Tenancy::set($this->salon);
        $customer = Customer::create(['salon_id' => $this->salon->id, 'name' => 'Mona', 'phone' => '+966500009999']);
        $appt = Appointment::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'service_id' => $this->service->id, 'staff_id' => $this->staff->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'status' => 'confirmed', 'source' => 'walk_in',
        ]);
        Tenancy::clear();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/appointments/{$appt->id}/status", ['status' => 'cancelled', 'reason' => 'Double booked'])
            ->assertOk();
        $this->assertDatabaseHas('appointments', [
            'id' => $appt->id, 'cancelled_by' => 'owner', 'cancellation_reason' => 'Double booked',
        ]);
    }

    public function test_owner_can_find_a_booking_by_reference(): void
    {
        Sanctum::actingAs($this->owner);
        $phone = '+966500000123';
        $ref = $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->json('data.reference');

        // Reference search spans all dates (no from/to needed) and is case-insensitive.
        $this->getJson('/api/appointments?reference=' . strtolower($ref))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.name', 'Sara');

        $this->getJson('/api/appointments?reference=ZZZZZZ')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_calendar_lists_appointments_in_range(): void
    {
        Sanctum::actingAs($this->owner);
        $phone = '+966500000123';
        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->assertCreated();

        $this->getJson('/api/appointments?from=' . $this->date->format('Y-m-d'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.name', 'Sara');
    }

    public function test_staff_calendar_shows_only_their_own(): void
    {
        // A second staffer with an appointment; the first staffer must not see it.
        Tenancy::set($this->salon);
        $other = User::factory()->create(['salon_id' => $this->salon->id, 'role' => 'staff']);
        $this->branch->staff()->attach($other->id);
        $this->service->staff()->attach($other->id);
        $customer = Customer::create(['salon_id' => $this->salon->id, 'name' => 'X', 'phone' => '+966599999999']);
        Appointment::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'service_id' => $this->service->id, 'staff_id' => $other->id,
            'starts_at' => Carbon::parse($this->date->format('Y-m-d') . ' 11:00', $this->tz)->utc(),
            'ends_at' => Carbon::parse($this->date->format('Y-m-d') . ' 12:00', $this->tz)->utc(),
            'status' => 'confirmed', 'source' => 'walk_in',
        ]);
        Tenancy::clear();

        Sanctum::actingAs($this->staff); // has no appointments of their own
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- QA hardening (Sprints 3-4 review) ------------------------------------

    public function test_cannot_book_outside_working_hours(): void
    {
        // Branch is open 10:00–18:00; 23:00 is not a real slot (BUG-2).
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'customer_name' => 'Late', 'customer_phone' => '+966512345678',
            'date' => $this->date->format('Y-m-d'), 'time' => '23:00',
        ])->assertStatus(409);

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_reschedule_preserves_the_manage_token(): void
    {
        $phone = '+966500000123';
        $ref = $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $this->branch->id, 'service_id' => $this->service->id,
            'staff_id' => $this->staff->id, 'date' => $this->date->format('Y-m-d'),
            'time' => '11:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $this->otpFor($phone),
        ])->json('data.manage_token');

        // Reschedule to 13:00 — same manage token comes back (BUG-3).
        $this->postJson("/api/book/manage/{$ref}/reschedule", [
            'date' => $this->date->format('Y-m-d'), 'time' => '13:00',
        ])->assertOk()->assertJsonPath('data.manage_token', $ref);

        // The original manage link still resolves to the live (confirmed) booking.
        $this->getJson("/api/book/manage/{$ref}")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_staff_cannot_change_a_colleagues_appointment(): void
    {
        // Appointment belongs to $this->staff.
        Tenancy::set($this->salon);
        $other = User::factory()->create(['salon_id' => $this->salon->id, 'role' => 'staff']);
        $customer = Customer::create(['salon_id' => $this->salon->id, 'name' => 'X', 'phone' => '+966599990000']);
        $appt = Appointment::create([
            'salon_id' => $this->salon->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'service_id' => $this->service->id, 'staff_id' => $this->staff->id,
            'starts_at' => Carbon::parse($this->date->format('Y-m-d') . ' 11:00', $this->tz)->utc(),
            'ends_at' => Carbon::parse($this->date->format('Y-m-d') . ' 12:00', $this->tz)->utc(),
            'status' => 'confirmed', 'source' => 'online',
        ]);
        Tenancy::clear();

        Sanctum::actingAs($other); // a different staff member
        $this->patchJson("/api/appointments/{$appt->id}/status", ['status' => 'done'])->assertNotFound();
        $this->getJson("/api/appointments/{$appt->id}")->assertNotFound();
    }
}
