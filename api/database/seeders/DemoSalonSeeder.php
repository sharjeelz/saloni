<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSalonSeeder extends Seeder
{
    public function run(): void
    {
        // --- Tenant 1: Glow Ladies Salon -------------------------------------
        $glow = Salon::create([
            'name' => 'Glow Ladies Salon',
            'slug' => 'glow',
            'phone' => '+966500000001',
            'plan' => 'trial',
            'trial_ends_at' => now()->addDays(14),
        ]);

        // Everything below is created *inside* the Glow tenant context, so
        // BelongsToSalon auto-stamps salon_id on each record.
        Tenancy::set($glow);

        $owner = User::create([
            'name' => 'Reem Al-Otaibi', 'email' => 'owner@glow.sa',
            'phone' => '+966500000001', 'role' => 'owner',
            'password' => Hash::make('password'), 'salon_id' => $glow->id,
        ]);

        $stylist = User::create([
            'name' => 'Lina Hassan', 'email' => 'lina@glow.sa',
            'phone' => '+966500000002', 'role' => 'staff', 'title' => 'Senior Stylist',
            'password' => Hash::make('password'), 'salon_id' => $glow->id,
        ]);

        $branch = Branch::create([
            'name' => 'Glow — Olaya', 'city' => 'Riyadh',
            'address' => 'Olaya St, Riyadh', 'phone' => '+966112223344',
        ]);
        $branch->staff()->attach([$owner->id, $stylist->id]);

        $hairCat = ServiceCategory::create(['name' => 'Hair', 'sort_order' => 1]);
        ServiceCategory::create(['name' => 'Nails', 'sort_order' => 2]);

        $haircut = Service::create([
            'service_category_id' => $hairCat->id, 'name' => 'Haircut & Style',
            'duration_min' => 45, 'price' => 120,
        ]);
        $haircut->staff()->attach($stylist->id);

        $customer = Customer::create([
            'name' => 'Sara Q.', 'phone' => '+966555555555',
        ]);

        Appointment::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'service_id' => $haircut->id,
            'staff_id' => $stylist->id,
            'starts_at' => now()->addDay()->setTime(14, 0),
            'ends_at' => now()->addDay()->setTime(14, 45),
            'status' => 'confirmed',
            'source' => 'online',
            'price' => 120,
        ]);

        // --- Tenant 2: a second salon, to prove isolation --------------------
        $lush = Salon::create(['name' => 'Lush Spa', 'slug' => 'lush']);
        Tenancy::set($lush);
        User::create([
            'name' => 'Owner Two', 'email' => 'owner@lush.sa', 'role' => 'owner',
            'password' => Hash::make('password'), 'salon_id' => $lush->id,
        ]);
        Branch::create(['name' => 'Lush — Jeddah', 'city' => 'Jeddah']);

        Tenancy::clear();

        $this->command->info('Seeded 2 salons. Glow branches (scoped): '
            . Branch::where('salon_id', $glow->id)->count()
            . ' | total branches (unscoped): '
            . Branch::withoutTenancy()->count());
    }
}
