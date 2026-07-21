<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Salon;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSalon(): array
    {
        $salon = Salon::create(['name' => 'Glow', 'slug' => 'glow', 'trial_ends_at' => now()->addDays(10)]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        Tenancy::clear();

        return [$salon, $owner, $staff];
    }

    /** Decode a ZATCA TLV Base64 payload into [tag => value]. */
    protected function parseTlv(string $base64): array
    {
        $bytes = base64_decode($base64);
        $out = [];
        $i = 0;
        while ($i < strlen($bytes)) {
            $tag = ord($bytes[$i]);
            $len = ord($bytes[$i + 1]);
            $out[$tag] = substr($bytes, $i + 2, $len);
            $i += 2 + $len;
        }

        return $out;
    }

    public function test_plans_show_vat_inclusive_totals(): void
    {
        [, $owner] = $this->makeSalon();
        Sanctum::actingAs($owner);

        $res = $this->getJson('/api/billing/plans')->assertOk();
        $basic = collect($res->json('data'))->firstWhere('key', 'basic');

        $this->assertEquals(99, $basic['price']);
        $this->assertEquals(14.85, $basic['vat']);   // 99 * 0.15
        $this->assertEquals(113.85, $basic['total']);
    }

    public function test_owner_subscribes_and_gets_a_zatca_invoice(): void
    {
        [$salon, $owner] = $this->makeSalon();
        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/billing/subscribe', ['plan' => 'basic'])->assertCreated();

        $this->assertDatabaseHas('subscriptions', [
            'salon_id' => $salon->id, 'plan' => 'basic', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('invoices', [
            'salon_id' => $salon->id, 'subtotal' => 99, 'vat_amount' => 14.85, 'total' => 113.85,
        ]);

        // The invoice carries a ZATCA QR encoding the seller, total and VAT.
        $qr = $res->json('invoice.zatca_qr');
        $this->assertNotEmpty($qr);
        $tlv = $this->parseTlv($qr);
        $this->assertSame('Salooni', $tlv[1]);          // seller name
        $this->assertSame('113.85', $tlv[4]);           // total incl VAT
        $this->assertSame('14.85', $tlv[5]);            // VAT total
    }

    public function test_subscribe_requires_a_known_plan(): void
    {
        [, $owner] = $this->makeSalon();
        Sanctum::actingAs($owner);
        $this->postJson('/api/billing/subscribe', ['plan' => 'enterprise'])->assertStatus(422);
    }

    public function test_billing_is_owner_only(): void
    {
        [, , $staff] = $this->makeSalon();
        Sanctum::actingAs($staff);
        $this->getJson('/api/billing')->assertForbidden();
        $this->postJson('/api/billing/subscribe', ['plan' => 'basic'])->assertForbidden();
    }

    public function test_renewal_command_extends_the_period_and_invoices(): void
    {
        [$salon] = $this->makeSalon();
        Tenancy::set($salon);
        $sub = Subscription::create([
            'salon_id' => $salon->id, 'plan' => 'basic', 'status' => 'active', 'price' => 99,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(), // ended → due for renewal
            'gateway' => 'manual',
        ]);
        Tenancy::clear();

        $this->artisan('subscriptions:renew')->assertSuccessful();

        $sub->refresh();
        $this->assertTrue($sub->current_period_end->isFuture());
        $this->assertSame('active', $sub->status);
        Tenancy::set($salon);
        $this->assertSame(1, Invoice::count()); // renewal invoice issued
        Tenancy::clear();
    }

    public function test_widget_returns_an_embeddable_snippet(): void
    {
        [$salon] = $this->makeSalon();

        $res = $this->getJson('/api/book/glow/widget')->assertOk();
        $this->assertStringContainsString('/book/glow', $res->json('data.booking_url'));
        $this->assertStringContainsString('Book Now', $res->json('data.embed_html'));
    }
}
