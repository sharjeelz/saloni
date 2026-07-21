<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Salon;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Otp\OtpService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackendHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_is_bound_to_purpose_and_salon(): void
    {
        $otp = app(OtpService::class);
        $phone = '+966500000001';

        // A code minted for booking on salon 10.
        $code = $otp->request('phone', $phone, 'booking', 10)['debug_code'];

        // It must NOT verify for login, nor for another salon.
        $this->assertFalse($otp->verify('phone', $phone, $code, 'login', null));
        $this->assertFalse($otp->verify('phone', $phone, $code, 'booking', 20));

        // Only its own purpose + salon works.
        $this->assertTrue($otp->verify('phone', $phone, $code, 'booking', 10));
    }

    public function test_saving_branch_hours_preserves_per_staff_hours(): void
    {
        $salon = Salon::create(['name' => 'Glow', 'slug' => 'glow']);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        $branch = Branch::create(['name' => 'Olaya', 'salon_id' => $salon->id]);

        // A custom per-staff hour row.
        $staffHour = WorkingHour::create([
            'salon_id' => $salon->id, 'branch_id' => $branch->id, 'user_id' => $staff->id,
            'weekday' => 1, 'start_time' => '12:00', 'end_time' => '16:00',
        ]);
        Tenancy::clear();

        // Owner saves branch-level hours (no user_id).
        Sanctum::actingAs($owner);
        $this->putJson("/api/branches/{$branch->id}/hours", [
            'hours' => [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '18:00']],
        ])->assertOk();

        // The staff-specific row survives.
        $this->assertDatabaseHas('working_hours', ['id' => $staffHour->id, 'user_id' => $staff->id]);
        $this->assertDatabaseHas('working_hours', ['branch_id' => $branch->id, 'user_id' => null, 'weekday' => 2]);
    }
}
