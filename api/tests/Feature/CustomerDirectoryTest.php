<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function salon(string $slug): array
    {
        $salon = Salon::create(['name' => ucfirst($slug), 'slug' => $slug]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        Tenancy::clear();

        return [$salon, $owner];
    }

    public function test_repeated_bookings_by_phone_map_to_one_customer(): void
    {
        [$salon, $owner] = $this->salon('glow');
        Tenancy::set($salon);
        $branch = Branch::create(['name' => 'B', 'salon_id' => $salon->id]);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        $service = Service::create(['salon_id' => $salon->id, 'name' => 'Cut', 'duration_min' => 30, 'price' => 50]);
        $branch->staff()->attach($staff->id);
        $service->staff()->attach($staff->id);

        // Two "bookings" for the same phone resolve to one customer.
        $booking = app(\App\Services\Booking\BookingService::class);
        $c1 = $booking->resolveCustomer('Sara', '+966500001111');
        $c2 = $booking->resolveCustomer('Sara Q', '+966500001111');
        Tenancy::clear();

        $this->assertSame($c1->id, $c2->id);
        $this->assertSame('Sara Q', $c2->name); // name refreshed

        Sanctum::actingAs($owner);
        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.phone', '+966500001111');
    }

    public function test_owner_can_edit_a_customer_but_not_another_salons(): void
    {
        [$salonA, $ownerA] = $this->salon('glow');
        Tenancy::set($salonA);
        $mine = Customer::create(['salon_id' => $salonA->id, 'name' => 'Sara', 'phone' => '+966500004444']);
        Tenancy::clear();

        [$salonB] = $this->salon('lush');
        Tenancy::set($salonB);
        $foreign = Customer::create(['salon_id' => $salonB->id, 'name' => 'Other', 'phone' => '+966500005555']);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        $this->patchJson("/api/customers/{$mine->id}", ['name' => 'Sara Q.', 'notes' => 'Prefers mornings'])
            ->assertOk()->assertJsonPath('data.name', 'Sara Q.');
        $this->assertDatabaseHas('customers', ['id' => $mine->id, 'name' => 'Sara Q.', 'notes' => 'Prefers mornings']);

        // Cannot touch salon B's customer.
        $this->patchJson("/api/customers/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
    }

    public function test_search_and_tenant_isolation(): void
    {
        [$salonA, $ownerA] = $this->salon('glow');
        Tenancy::set($salonA);
        Customer::create(['salon_id' => $salonA->id, 'name' => 'Alice', 'phone' => '+966500002222']);
        Tenancy::clear();

        [$salonB] = $this->salon('lush');
        Tenancy::set($salonB);
        $bCustomer = Customer::create(['salon_id' => $salonB->id, 'name' => 'Bob', 'phone' => '+966500003333']);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        // Search by name finds own; never salon B's.
        $this->getJson('/api/customers?q=Ali')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/customers?q=Bob')->assertOk()->assertJsonCount(0, 'data');
        // Route-bound access to B's customer 404s.
        $this->getJson("/api/customers/{$bCustomer->id}")->assertNotFound();
    }
}
