<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Salon;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OffersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Salon, 1: User, 2: User} */
    protected function salon(string $slug = 'glow'): array
    {
        $salon = Salon::create(['name' => ucfirst($slug), 'slug' => $slug]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff']);
        Tenancy::clear();

        return [$salon, $owner, $staff];
    }

    public function test_owner_uploads_an_offer_banner(): void
    {
        Storage::fake('public');
        [$salon, $owner] = $this->salon();
        Sanctum::actingAs($owner);

        $this->postJson('/api/offers', [
            'image' => UploadedFile::fake()->image('promo.jpg', 1200, 400),
            'caption' => 'Eid package — 199 SAR',
            'link_url' => 'wa.me/966500000000', // no scheme — should be normalized
        ])->assertCreated()
            ->assertJsonPath('data.caption', 'Eid package — 199 SAR')
            ->assertJsonPath('data.link_url', 'https://wa.me/966500000000')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('offers', ['salon_id' => $salon->id, 'caption' => 'Eid package — 199 SAR']);
    }

    public function test_public_offers_returns_active_only_in_order(): void
    {
        [$salon] = $this->salon();
        Tenancy::set($salon);
        Offer::create(['image_path' => 'a.jpg', 'caption' => 'Live', 'sort_order' => 1, 'is_active' => true]);
        Offer::create(['image_path' => 'b.jpg', 'caption' => 'First', 'sort_order' => 0, 'is_active' => true]);
        Offer::create(['image_path' => 'c.jpg', 'caption' => 'Hidden', 'sort_order' => 2, 'is_active' => false]);
        Tenancy::clear();

        $this->getJson('/api/book/glow/offers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.caption', 'First')  // sort_order 0 first
            ->assertJsonPath('data.1.caption', 'Live');
    }

    public function test_offers_are_owner_only(): void
    {
        Storage::fake('public');
        [, , $staff] = $this->salon();
        Sanctum::actingAs($staff);

        $this->postJson('/api/offers', ['image' => UploadedFile::fake()->image('x.jpg')])
            ->assertForbidden();
    }

    public function test_offer_from_another_salon_is_not_reachable(): void
    {
        [, $ownerA] = $this->salon('glow');
        [$salonB] = $this->salon('lush');
        Tenancy::set($salonB);
        $foreign = Offer::create(['image_path' => 'b.jpg', 'sort_order' => 0, 'is_active' => true]);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        $this->patchJson("/api/offers/{$foreign->id}", ['is_active' => false])->assertNotFound();
    }
}
