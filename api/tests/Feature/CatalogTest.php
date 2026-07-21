<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Salon;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function salon(string $slug = 'glow'): array
    {
        $salon = Salon::create(['name' => ucfirst($slug), 'slug' => $slug]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        Tenancy::clear();

        return [$salon, $owner, $staff];
    }

    public function test_owner_creates_branch_and_sets_hours(): void
    {
        [$salon, $owner] = $this->salon();
        Sanctum::actingAs($owner);

        $branch = $this->postJson('/api/branches', [
            'name' => 'Olaya', 'city' => 'Riyadh', 'address' => 'Olaya St',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('branches', ['id' => $branch['id'], 'salon_id' => $salon->id]);

        // Set weekly hours (Sun+Mon, 10:00–22:00).
        $this->putJson("/api/branches/{$branch['id']}/hours", [
            'hours' => [
                ['weekday' => 0, 'start_time' => '10:00', 'end_time' => '22:00'],
                ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '22:00'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('working_hours', 2);
    }

    public function test_end_of_day_before_start_is_rejected(): void
    {
        [, $owner] = $this->salon();
        Sanctum::actingAs($owner);
        $branch = Branch::create(['name' => 'B', 'salon_id' => $owner->salon_id]);

        $this->putJson("/api/branches/{$branch->id}/hours", [
            'hours' => [['weekday' => 0, 'start_time' => '22:00', 'end_time' => '10:00']],
        ])->assertStatus(422);
    }

    public function test_owner_creates_category_service_and_assigns_staff(): void
    {
        [$salon, $owner, $staff] = $this->salon();
        Sanctum::actingAs($owner);

        $cat = $this->postJson('/api/service-categories', ['name' => 'Hair'])
            ->assertCreated()->json('data');

        $service = $this->postJson('/api/services', [
            'name' => 'Haircut', 'duration_min' => 45, 'price' => 120,
            'service_category_id' => $cat['id'],
        ])->assertCreated()->assertJsonPath('data.name', 'Haircut')->json('data');

        // Assign the staff member to the service (E4-2).
        $this->putJson("/api/services/{$service['id']}/staff", [
            'staff_ids' => [$staff->id],
        ])->assertOk()->assertJsonPath('data.staff.0.id', $staff->id);

        $this->assertDatabaseHas('service_staff', [
            'service_id' => $service['id'], 'user_id' => $staff->id,
        ]);
    }

    public function test_service_cannot_use_another_salons_category(): void
    {
        [, $ownerA] = $this->salon('glow');
        [$salonB] = $this->salon('lush');
        Tenancy::set($salonB);
        $foreignCat = ServiceCategory::create(['name' => 'Nails', 'salon_id' => $salonB->id]);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        $this->postJson('/api/services', [
            'name' => 'X', 'duration_min' => 30, 'price' => 50,
            'service_category_id' => $foreignCat->id,
        ])->assertStatus(422); // category not in owner A's salon
    }

    public function test_staff_can_list_but_not_manage_services(): void
    {
        [, , $staff] = $this->salon();
        Sanctum::actingAs($staff);

        $this->getJson('/api/services')->assertOk();
        $this->postJson('/api/services', [
            'name' => 'X', 'duration_min' => 30, 'price' => 50,
        ])->assertForbidden();
    }

    public function test_branch_from_another_salon_is_not_reachable(): void
    {
        [, $ownerA] = $this->salon('glow');
        [$salonB] = $this->salon('lush');
        Tenancy::set($salonB);
        $foreignBranch = Branch::create(['name' => 'Lush Jeddah', 'salon_id' => $salonB->id]);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        $this->getJson("/api/branches/{$foreignBranch->id}")->assertNotFound();
        $this->patchJson("/api/branches/{$foreignBranch->id}", ['name' => 'Hacked'])->assertNotFound();
    }

    public function test_owner_assigns_staff_to_a_branch(): void
    {
        [$salon, $owner, $staff] = $this->salon();
        Tenancy::set($salon);
        $branch = Branch::create(['name' => 'Olaya', 'salon_id' => $salon->id]);
        Tenancy::clear();

        Sanctum::actingAs($owner);
        $this->putJson("/api/branches/{$branch->id}/staff", ['staff_ids' => [$staff->id]])
            ->assertOk()
            ->assertJsonPath('data.staff.0.id', $staff->id);

        $this->assertDatabaseHas('branch_staff', ['branch_id' => $branch->id, 'user_id' => $staff->id]);
    }

    public function test_branch_staff_rejects_foreign_staff(): void
    {
        [$salonA, $ownerA] = $this->salon('glow');
        [$salonB, , $staffB] = $this->salon('lush');
        Tenancy::set($salonA);
        $branchA = Branch::create(['name' => 'A', 'salon_id' => $salonA->id]);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        $this->putJson("/api/branches/{$branchA->id}/staff", ['staff_ids' => [$staffB->id]])
            ->assertStatus(422);
    }
}
