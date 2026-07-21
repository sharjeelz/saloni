<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Salon;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the tenant-isolation breaches QA found: the User
 * model was missing the tenant global scope, leaking/mutating staff across
 * salons. These exercise TWO tenants' staff on every user-touching path.
 */
class TenantStaffIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function salon(string $slug): array
    {
        $salon = Salon::create(['name' => ucfirst($slug), 'slug' => $slug]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        $branch = Branch::create(['name' => ucfirst($slug) . ' Br', 'salon_id' => $salon->id]);
        Tenancy::clear();

        return [$salon, $owner, $staff, $branch];
    }

    public function test_staff_list_excludes_other_salons_staff(): void
    {
        [, $ownerA, $staffA] = $this->salon('glow');
        [, , $staffB] = $this->salon('lush');

        Sanctum::actingAs($ownerA);
        $res = $this->getJson('/api/staff')->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($staffA->id, $ids);
        $this->assertNotContains($staffB->id, $ids); // BUG-1: was leaking B's PII
    }

    public function test_owner_cannot_deactivate_another_salons_staff(): void
    {
        [, $ownerA] = $this->salon('glow');
        [, , $staffB] = $this->salon('lush');

        Sanctum::actingAs($ownerA);
        // BUG-2: was 200 and disabled B's staff.
        $this->patchJson("/api/staff/{$staffB->id}/deactivate")->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $staffB->id, 'is_active' => true]);
    }

    public function test_working_hours_reject_foreign_staff_user_id(): void
    {
        [, $ownerA, , $branchA] = $this->salon('glow');
        [, , $staffB] = $this->salon('lush');

        Sanctum::actingAs($ownerA);
        // BUG-3: was 200 and wrote a row referencing B's staff.
        $this->putJson("/api/branches/{$branchA->id}/hours", [
            'hours' => [[
                'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00',
                'user_id' => $staffB->id,
            ]],
        ])->assertStatus(422);
    }

    public function test_suspended_salon_blocks_member_access(): void
    {
        [$salon, $owner] = $this->salon('glow');
        $salon->update(['is_active' => false]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/branches')->assertForbidden(); // 403 suspended
    }

    public function test_staff_otp_login_still_works_after_scope_added(): void
    {
        // Guard against over-scoping: cross-tenant OTP lookup must still resolve.
        [$salon] = $this->salon('glow');
        Tenancy::set($salon);
        $staff = User::factory()->create([
            'salon_id' => $salon->id, 'role' => 'staff', 'phone' => '+966500009999',
        ]);
        Tenancy::clear();

        $code = $this->postJson('/api/auth/otp/request', ['phone' => '+966500009999'])
            ->assertOk()->json('debug_code');

        $this->postJson('/api/auth/otp/verify', ['phone' => '+966500009999', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $staff->id);
    }
}
