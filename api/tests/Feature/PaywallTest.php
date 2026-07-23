<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaywallTest extends TestCase
{
    use RefreshDatabase;

    protected function salon(array $attrs): array
    {
        $salon = Salon::create(array_merge(['name' => 'Glow', 'slug' => 'glow'], $attrs));
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        Tenancy::clear();

        return [$salon, $owner];
    }

    public function test_active_trial_salon_can_write(): void
    {
        [, $owner] = $this->salon(['trial_ends_at' => now()->addDays(5)]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('salon.locked', false);
        $this->postJson('/api/service-categories', ['name' => 'Hair'])->assertCreated();
    }

    public function test_expired_trial_locks_writes_but_keeps_read_and_billing(): void
    {
        [, $owner] = $this->salon(['trial_ends_at' => now()->subDay()]);
        Sanctum::actingAs($owner);

        // The console knows it's locked.
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('salon.locked', true);

        // Reads still work.
        $this->getJson('/api/services')->assertOk();

        // Writes are blocked with 402 (grace paywall).
        $this->postJson('/api/service-categories', ['name' => 'Hair'])->assertStatus(402);

        // Billing stays reachable so they can pay…
        $this->getJson('/api/billing')->assertOk();
        $this->postJson('/api/billing/subscribe', ['plan' => 'basic'])->assertCreated();

        // …and once subscribed, writes work again.
        $this->getJson('/api/auth/me')->assertJsonPath('salon.locked', false);
        $this->postJson('/api/service-categories', ['name' => 'Hair'])->assertCreated();
    }
}
