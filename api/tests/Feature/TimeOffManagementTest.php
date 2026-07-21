<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimeOffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSalon(): array
    {
        $salon = Salon::create(['name' => 'Glow', 'slug' => 'glow']);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        $branch = Branch::create(['name' => 'Olaya', 'salon_id' => $salon->id]);
        Tenancy::clear();

        return [$salon, $owner, $staff, $branch];
    }

    public function test_owner_can_block_a_branch_wide_closure(): void
    {
        [, $owner, , $branch] = $this->makeSalon();
        Sanctum::actingAs($owner);

        $this->postJson('/api/time-off', [
            'branch_id' => $branch->id,
            'user_id' => null, // whole-branch closure
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHours(3)->toDateTimeString(),
            'reason' => 'National holiday',
        ])->assertCreated();

        $this->assertDatabaseHas('time_off', ['branch_id' => $branch->id, 'user_id' => null]);
    }

    public function test_staff_block_is_forced_to_their_own_id(): void
    {
        [, $owner, $staff, $branch] = $this->makeSalon();
        Sanctum::actingAs($staff);

        // Staff tries to close the whole branch (user_id null) — gets pinned to self.
        $this->postJson('/api/time-off', [
            'branch_id' => $branch->id,
            'user_id' => null,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertCreated();

        $this->assertDatabaseHas('time_off', ['branch_id' => $branch->id, 'user_id' => $staff->id]);
        $this->assertDatabaseMissing('time_off', ['branch_id' => $branch->id, 'user_id' => null]);
    }

    public function test_staff_cannot_delete_another_staffs_time_off(): void
    {
        [$salon, $owner, $staff, $branch] = $this->makeSalon();
        Tenancy::set($salon);
        $other = TimeOff::create([
            'salon_id' => $salon->id, 'branch_id' => $branch->id, 'user_id' => $owner->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        ]);
        Tenancy::clear();

        Sanctum::actingAs($staff);
        $this->deleteJson("/api/time-off/{$other->id}")->assertForbidden();
    }
}
