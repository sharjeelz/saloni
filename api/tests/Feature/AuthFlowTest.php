<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_sign_up_and_gets_a_scoped_account(): void
    {
        $res = $this->postJson('/api/auth/signup', [
            'salon_name' => 'Glow Ladies',
            'owner_name' => 'Reem',
            'email' => 'reem@glow.sa',
            'phone' => '+966500000001',
            'password' => 'secret123',
        ]);

        $res->assertCreated()
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonStructure(['token', 'user' => ['id', 'salon_id'], 'salon' => ['slug']]);

        $this->assertDatabaseHas('salons', ['slug' => 'glow-ladies']);
        $this->assertDatabaseHas('users', ['email' => 'reem@glow.sa', 'role' => 'owner']);
    }

    public function test_phone_otp_login_issues_a_token(): void
    {
        $user = User::factory()->create([
            'phone' => '+966555555555',
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Request the code (log driver — code returned as debug_code in tests).
        $req = $this->postJson('/api/auth/otp/request', ['phone' => '+966555555555']);
        $req->assertOk()->assertJsonStructure(['debug_code']);
        $code = $req->json('debug_code');

        // Verify → token.
        $verify = $this->postJson('/api/auth/otp/verify', [
            'phone' => '+966555555555',
            'code' => $code,
        ]);
        $verify->assertOk()->assertJsonPath('user.id', $user->id);

        // Authenticated /me works with the issued token.
        $this->withToken($verify->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.phone', '+966555555555');
    }

    public function test_wrong_otp_is_rejected(): void
    {
        User::factory()->create(['phone' => '+966511111111', 'is_active' => true]);
        $this->postJson('/api/auth/otp/request', ['phone' => '+966511111111']);

        $this->postJson('/api/auth/otp/verify', [
            'phone' => '+966511111111',
            'code' => '000000',
        ])->assertStatus(422);
    }

    public function test_otp_debug_code_is_withheld_unless_explicitly_enabled(): void
    {
        // Default posture (production): the plaintext code must never be returned.
        config(['otp.expose_debug_code' => false]);

        $res = $this->postJson('/api/auth/otp/request', ['phone' => '+966500000000'])->assertOk();
        $this->assertNull($res->json('debug_code'));
    }

    public function test_otp_request_is_rate_limited_per_ip(): void
    {
        // Route throttle is 8/min; the 9th request from the same IP is blocked
        // even with distinct phone numbers (SMS-bombing / cost protection).
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/auth/otp/request', ['phone' => "+9665000000{$i}0"])->assertOk();
        }

        $this->postJson('/api/auth/otp/request', ['phone' => '+966500000099'])->assertStatus(429);
    }
}
