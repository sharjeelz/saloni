<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\Subscription;
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
        config(['payments.self_serve' => true]); // self-serve checkout (live-gateway mode)
        $this->getJson('/api/billing')->assertOk();
        $this->postJson('/api/billing/subscribe', ['plan' => 'basic'])->assertCreated();

        // …and once subscribed, writes work again.
        $this->getJson('/api/auth/me')->assertJsonPath('salon.locked', false);
        $this->postJson('/api/service-categories', ['name' => 'Hair'])->assertCreated();
    }

    protected function subscription(Salon $salon, array $attrs): void
    {
        Tenancy::set($salon);
        Subscription::create(array_merge([
            'plan' => 'basic', 'status' => 'canceled', 'price' => 0, 'currency' => 'SAR',
            'current_period_start' => now()->subDays(3),
        ], $attrs));
        Tenancy::clear();
    }

    public function test_cancelled_subscription_keeps_access_until_its_period_ends(): void
    {
        // Trial is long gone, but the paid (now-cancelled) period still has time.
        [$salon, $owner] = $this->salon(['trial_ends_at' => now()->subMonth()]);
        $this->subscription($salon, ['current_period_end' => now()->addDays(20), 'canceled_at' => now()]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('salon.locked', false);
        $this->postJson('/api/service-categories', ['name' => 'Hair'])->assertCreated();
    }

    public function test_subscribing_consumes_the_trial_so_a_lapsed_plan_does_not_fall_back(): void
    {
        // Trial still has days left, but they subscribed and then let it lapse.
        [$salon, $owner] = $this->salon(['trial_ends_at' => now()->addDays(10)]);
        $this->subscription($salon, ['current_period_end' => now()->subDay(), 'canceled_at' => now()->subDays(5)]);
        Sanctum::actingAs($owner);

        // No fallback to the leftover trial — the console is locked.
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('salon.locked', true);
        $this->postJson('/api/service-categories', ['name' => 'Hair'])->assertStatus(402);
    }

    public function test_billing_status_stops_reporting_on_trial_once_subscribed(): void
    {
        [$salon, $owner] = $this->salon(['trial_ends_at' => now()->addDays(10)]);
        $this->subscription($salon, ['current_period_end' => now()->addMonth(), 'status' => 'active']);
        Sanctum::actingAs($owner);

        // A subscription supersedes the trial, so on_trial is false even though
        // trial_ends_at is still in the future.
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('on_trial', false);
    }
}
