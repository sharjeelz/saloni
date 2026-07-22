<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Salon;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

    public function test_branch_keeps_map_link_separate_from_address(): void
    {
        [, $owner] = $this->salon();
        Sanctum::actingAs($owner);

        $b = $this->postJson('/api/branches', [
            'name' => 'Olaya', 'city' => 'Riyadh', 'address' => '1234 Qurtubah, Riyadh',
            'maps_url' => 'https://maps.google.com/?q=24.76,46.66',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('branches', [
            'id' => $b['id'], 'address' => '1234 Qurtubah, Riyadh', 'maps_url' => 'https://maps.google.com/?q=24.76,46.66',
        ]);

        // A non-URL in the map field is rejected (address stays free text).
        $this->postJson('/api/branches', ['name' => 'B', 'maps_url' => 'not a link'])->assertStatus(422);
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

    // ---- E13-1: AI menu onboarding ------------------------------------------

    public function test_owner_scans_a_menu_photo_into_a_service_preview(): void
    {
        [, $owner] = $this->salon();
        Sanctum::actingAs($owner);
        config(['services.anthropic.key' => 'test-key']);

        Http::fake([
            '*/v1/messages' => Http::response([
                'content' => [[
                    'type' => 'tool_use',
                    'name' => 'record_services',
                    'input' => ['services' => [
                        // Bilingual entry: Arabic primary + English secondary, ONE service.
                        ['name' => 'قص شعر', 'name_en' => 'Haircut', 'duration_min' => 30, 'price' => 40, 'category_key' => 'hair'],
                        ['name' => 'Manicure', 'price' => 60, 'category_key' => 'nails'], // no duration → default
                    ]],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/services/scan-menu', ['menu' => [UploadedFile::fake()->image('menu.jpg')]])
            ->assertOk()
            ->assertJsonCount(2, 'services')
            ->assertJsonPath('services.0.name', 'قص شعر')
            ->assertJsonPath('services.0.name_en', 'Haircut')     // merged, not duplicated
            ->assertJsonPath('services.0.category_key', 'hair')   // mapped to a canonical key
            ->assertJsonPath('services.1.name_en', null)          // single-language service
            ->assertJsonPath('services.1.duration_min', 30);      // sensibly defaulted

        // Nothing is saved by scanning — it's a preview only.
        $this->assertDatabaseCount('services', 0);
    }

    public function test_scan_returns_422_when_ai_is_not_configured(): void
    {
        [, $owner] = $this->salon();
        Sanctum::actingAs($owner);
        config(['services.anthropic.key' => null]);

        $this->postJson('/api/services/scan-menu', ['menu' => [UploadedFile::fake()->image('m.jpg')]])
            ->assertStatus(422);
    }

    public function test_owner_imports_reviewed_services_creating_categories_in_order(): void
    {
        [$salon, $owner, $staff] = $this->salon();
        Sanctum::actingAs($owner);

        $this->postJson('/api/services/import', [
            'services' => [
                ['name' => 'قص شعر', 'name_en' => 'Haircut', 'duration_min' => 30, 'price' => 40, 'category' => 'Hair'],
                ['name' => 'Beard trim', 'duration_min' => 15, 'price' => 20, 'category' => 'Hair'],
                ['name' => 'Manicure', 'duration_min' => 45, 'price' => 60, 'category' => 'Nails'],
                ['name' => 'Quick wash', 'duration_min' => 10, 'price' => 10, 'category' => null],
            ],
            'staff_ids' => [$staff->id],
        ])->assertCreated()->assertJsonPath('created', 4);

        // Two categories (Hair deduped), in menu order; four active services.
        $this->assertDatabaseCount('service_categories', 2);
        $this->assertDatabaseCount('services', 4);
        $this->assertDatabaseHas('services', ['salon_id' => $salon->id, 'name' => 'قص شعر', 'name_en' => 'Haircut']);
        $this->assertDatabaseHas('service_categories', ['salon_id' => $salon->id, 'name' => 'Hair', 'sort_order' => 0]);
        $this->assertDatabaseHas('service_categories', ['salon_id' => $salon->id, 'name' => 'Nails', 'sort_order' => 1]);

        // Every imported service was assigned to the chosen staff member.
        $this->assertDatabaseCount('service_staff', 4);
        $haircut = Service::where('name', 'قص شعر')->first();
        $this->assertTrue($haircut->staff->contains($staff->id));
    }

    public function test_import_maps_category_keys_to_bilingual_canonical_categories(): void
    {
        [$salon, $owner] = $this->salon();
        Sanctum::actingAs($owner);

        $this->postJson('/api/services/import', ['services' => [
            ['name' => 'قص شعر', 'duration_min' => 30, 'price' => 40, 'category_key' => 'hair'],
            ['name' => 'Nail art', 'duration_min' => 45, 'price' => 60, 'category_key' => 'nails'],
            ['name' => 'مانيكير', 'duration_min' => 45, 'price' => 55, 'category_key' => 'nails'], // same key
            ['name' => 'Piercing', 'duration_min' => 15, 'price' => 30, 'category' => 'Piercing'], // custom, free-text
        ]])->assertCreated()->assertJsonPath('created', 4);

        // 'nails' used twice → one category; canonical ones are bilingual; custom stays as-is.
        $this->assertDatabaseCount('service_categories', 3);
        $this->assertDatabaseHas('service_categories', ['salon_id' => $salon->id, 'name' => 'الشعر', 'name_en' => 'Hair']);
        $this->assertDatabaseHas('service_categories', ['salon_id' => $salon->id, 'name' => 'الأظافر', 'name_en' => 'Nails']);
        $this->assertDatabaseHas('service_categories', ['salon_id' => $salon->id, 'name' => 'Piercing', 'name_en' => null]);
    }

    public function test_import_reuses_an_existing_category_for_a_preset_key(): void
    {
        [$salon, $owner] = $this->salon();
        Tenancy::set($salon);
        ServiceCategory::create(['name' => 'Nails', 'sort_order' => 0]); // pre-existing single-language
        Tenancy::clear();
        Sanctum::actingAs($owner);

        $this->postJson('/api/services/import', ['services' => [
            ['name' => 'مانيكير', 'duration_min' => 45, 'price' => 55, 'category_key' => 'nails'],
        ]])->assertCreated();

        // Reused the existing "Nails" (matched via preset English name) — no duplicate.
        $this->assertDatabaseCount('service_categories', 1);
    }

    public function test_import_text_category_dedupes_against_english_name(): void
    {
        [$salon, $owner] = $this->salon();
        Tenancy::set($salon);
        // A bilingual category already exists (Arabic name + English name_en).
        ServiceCategory::create(['name' => 'الأظافر', 'name_en' => 'Nails', 'sort_order' => 0]);
        Tenancy::clear();
        Sanctum::actingAs($owner);

        // AI tags this row with the raw English heading (no preset key).
        $this->postJson('/api/services/import', ['services' => [
            ['name' => 'Gel polish', 'duration_min' => 40, 'price' => 70, 'category' => 'Nails'],
        ]])->assertCreated();

        // Reused the existing category via name_en — no duplicate.
        $this->assertDatabaseCount('service_categories', 1);
    }

    public function test_category_presets_are_listed(): void
    {
        [, $owner] = $this->salon();
        Sanctum::actingAs($owner);

        $this->getJson('/api/service-category-presets')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'hair')
            ->assertJsonPath('data.0.ar', 'الشعر');
    }

    public function test_import_rejects_staff_from_another_salon(): void
    {
        [, $ownerA] = $this->salon('glow');
        [, , $staffB] = $this->salon('lush');
        Sanctum::actingAs($ownerA);

        $this->postJson('/api/services/import', [
            'services' => [['name' => 'Cut', 'duration_min' => 30, 'price' => 40]],
            'staff_ids' => [$staffB->id],
        ])->assertStatus(422);

        $this->assertDatabaseCount('services', 0);
    }

    public function test_menu_import_is_owner_only(): void
    {
        [, , $staff] = $this->salon();
        Sanctum::actingAs($staff);

        $this->postJson('/api/services/import', ['services' => [
            ['name' => 'X', 'duration_min' => 30, 'price' => 40],
        ]])->assertForbidden();
    }

    // ---- E4-4: category reordering ------------------------------------------

    public function test_owner_reorders_categories(): void
    {
        [$salon, $owner] = $this->salon();
        Tenancy::set($salon);
        $a = ServiceCategory::create(['name' => 'Hair', 'sort_order' => 0]);
        $b = ServiceCategory::create(['name' => 'Nails', 'sort_order' => 1]);
        $c = ServiceCategory::create(['name' => 'Spa', 'sort_order' => 2]);
        Tenancy::clear();

        Sanctum::actingAs($owner);
        $this->putJson('/api/service-categories/reorder', ['ids' => [$c->id, $a->id, $b->id]])->assertOk();

        $this->assertDatabaseHas('service_categories', ['id' => $c->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('service_categories', ['id' => $a->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('service_categories', ['id' => $b->id, 'sort_order' => 2]);
    }

    public function test_reorder_rejects_a_foreign_category(): void
    {
        [, $ownerA] = $this->salon('glow');
        [$salonB] = $this->salon('lush');
        Tenancy::set($salonB);
        $foreign = ServiceCategory::create(['name' => 'X', 'sort_order' => 0]);
        Tenancy::clear();

        Sanctum::actingAs($ownerA);
        $this->putJson('/api/service-categories/reorder', ['ids' => [$foreign->id]])->assertStatus(422);
    }
}
