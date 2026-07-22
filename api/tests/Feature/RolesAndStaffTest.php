<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolesAndStaffTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSalonWithOwner(string $slug = 'glow'): array
    {
        $salon = Salon::create(['name' => ucfirst($slug), 'slug' => $slug]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        Tenancy::clear();

        return [$salon, $owner, $staff];
    }

    public function test_owner_can_invite_staff_and_list_them(): void
    {
        [$salon, $owner] = $this->makeSalonWithOwner();
        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/staff/invite', [
            'name' => 'Lina',
            'phone' => '+966500000009',
            'title' => 'Stylist',
        ]);

        $res->assertCreated()->assertJsonPath('staff.role', 'staff');
        $this->assertDatabaseHas('users', [
            'phone' => '+966500000009', 'role' => 'staff', 'salon_id' => $salon->id,
        ]);

        $this->getJson('/api/staff')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'staff');
    }

    public function test_staff_cannot_invite_staff(): void
    {
        [, , $staff] = $this->makeSalonWithOwner();
        Sanctum::actingAs($staff);

        $this->postJson('/api/staff/invite', [
            'name' => 'X', 'phone' => '+966500000010',
        ])->assertForbidden(); // 403 via role:owner
    }

    public function test_invite_rejects_a_non_numeric_phone(): void
    {
        [, $owner] = $this->makeSalonWithOwner();
        Sanctum::actingAs($owner);

        $this->postJson('/api/staff/invite', ['name' => 'Lina', 'phone' => 'abc'])
            ->assertStatus(422);
    }

    public function test_phone_with_spaces_and_dashes_is_normalized(): void
    {
        [$salon, $owner] = $this->makeSalonWithOwner();
        Sanctum::actingAs($owner);

        $this->postJson('/api/staff/invite', ['name' => 'Lina', 'phone' => '+966 50 111 2222'])
            ->assertCreated();
        $this->assertDatabaseHas('users', ['salon_id' => $salon->id, 'phone' => '+966501112222']);
    }

    public function test_local_and_international_phone_forms_canonicalize_to_one_e164(): void
    {
        // Every way a KSA number gets typed must land on the same +9665… string,
        // or customer identity / dedupe / the booking limit silently break.
        foreach ([
            '0509998877' => '+966509998877',       // local trunk
            '966509998877' => '+966509998877',     // country code, no +
            '00966509998877' => '+966509998877',   // 00 international prefix
            '509998877' => '+966509998877',        // bare national
            '+966 50 999 8877' => '+966509998877', // spaced international
        ] as $input => $expected) {
            $input = (string) $input; // PHP casts numeric string keys to int
            [$salon, $owner] = $this->makeSalonWithOwner('salon-' . md5($input));
            Sanctum::actingAs($owner);
            $this->postJson('/api/staff/invite', ['name' => 'Test', 'phone' => $input])->assertCreated();
            $this->assertDatabaseHas('users', ['salon_id' => $salon->id, 'phone' => $expected]);
        }
    }

    public function test_owner_can_edit_and_reactivate_staff(): void
    {
        [, $owner, $staff] = $this->makeSalonWithOwner();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/staff/{$staff->id}", ['title' => 'Lead Stylist'])
            ->assertOk()->assertJsonPath('staff.title', 'Lead Stylist');

        $this->patchJson("/api/staff/{$staff->id}/deactivate")->assertOk();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => false]);

        $this->patchJson("/api/staff/{$staff->id}/activate")->assertOk();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => true]);
    }

    public function test_owner_cannot_reach_super_admin_endpoints(): void
    {
        [, $owner] = $this->makeSalonWithOwner();
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/salons')->assertForbidden();
    }

    public function test_super_admin_sees_all_salons_across_tenants(): void
    {
        $this->makeSalonWithOwner('glow');
        $this->makeSalonWithOwner('lush');

        $admin = User::factory()->create(['salon_id' => null, 'role' => 'super_admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/salons')
            ->assertOk()
            ->assertJsonPath('total', 2); // both tenants visible
    }

    public function test_staff_invite_is_scoped_to_the_owners_salon(): void
    {
        [$salonA, $ownerA] = $this->makeSalonWithOwner('glow');
        [$salonB] = $this->makeSalonWithOwner('lush');

        Sanctum::actingAs($ownerA);
        $this->postJson('/api/staff/invite', [
            'name' => 'Mine', 'phone' => '+966511112222',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+966511112222', 'salon_id' => $salonA->id]);
        $this->assertDatabaseMissing('users', ['phone' => '+966511112222', 'salon_id' => $salonB->id]);
    }
}
