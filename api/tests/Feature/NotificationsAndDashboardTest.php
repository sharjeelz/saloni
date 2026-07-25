<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Sms\SmsSender;
use App\Services\WhatsApp\WhatsAppSender;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected string $tz = 'Asia/Riyadh';
    /** @var array<int, array{to:string, message:string}> */
    protected object $sms;
    /** @var array<int, array{to:string, message:string}> */
    protected object $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        // Capture every message per channel so tests can assert routing.
        $this->sms = new class implements SmsSender {
            public array $sent = [];

            public function send(string $to, string $message): bool
            {
                $this->sent[] = ['to' => $to, 'message' => $message];

                return true;
            }
        };
        $this->whatsapp = new class implements WhatsAppSender {
            public array $sent = [];

            public function send(string $to, string $message): bool
            {
                $this->sent[] = ['to' => $to, 'message' => $message];

                return true;
            }
        };

        $this->app->instance(SmsSender::class, $this->sms);
        $this->app->instance(WhatsAppSender::class, $this->whatsapp);
    }

    protected function makeSalon(): array
    {
        $salon = Salon::create(['name' => 'Glow', 'slug' => 'glow', 'timezone' => $this->tz]);
        Tenancy::set($salon);
        $owner = User::factory()->create(['salon_id' => $salon->id, 'role' => 'owner', 'phone' => '+966500000001']);
        $staff = User::factory()->create(['salon_id' => $salon->id, 'role' => 'staff', 'name' => 'Lina']);
        $branch = Branch::create(['name' => 'Olaya', 'salon_id' => $salon->id]);
        $service = Service::create(['salon_id' => $salon->id, 'name' => 'Haircut', 'duration_min' => 60, 'price' => 100]);
        $branch->staff()->attach($staff->id);
        $service->staff()->attach($staff->id);
        $date = Carbon::today($this->tz)->addDays(3);
        WorkingHour::create([
            'salon_id' => $salon->id, 'branch_id' => $branch->id, 'user_id' => null,
            'weekday' => (int) $date->dayOfWeek, 'start_time' => '09:00', 'end_time' => '21:00',
        ]);
        Tenancy::clear();

        return compact('salon', 'owner', 'staff', 'branch', 'service', 'date');
    }

    protected function appointment(array $ctx, string $status, Carbon $start, ?Carbon $reminderSent = null): Appointment
    {
        Tenancy::set($ctx['salon']);
        $customer = Customer::create(['salon_id' => $ctx['salon']->id, 'name' => 'Sara', 'phone' => '+966555000' . rand(100, 999)]);
        $appt = Appointment::create([
            'salon_id' => $ctx['salon']->id, 'branch_id' => $ctx['branch']->id, 'customer_id' => $customer->id,
            'service_id' => $ctx['service']->id, 'staff_id' => $ctx['staff']->id,
            'starts_at' => $start->clone()->utc(), 'ends_at' => $start->clone()->addMinutes(60)->utc(),
            'status' => $status, 'source' => 'online', 'price' => 100, 'reminder_sent_at' => $reminderSent,
            'public_token' => (string) \Illuminate\Support\Str::uuid(), 'reference' => strtoupper(\Illuminate\Support\Str::random(6)),
        ]);
        Tenancy::clear();

        return $appt;
    }

    // ---- E8-2: reminders ------------------------------------------------------

    public function test_reminder_command_texts_due_appointments_once(): void
    {
        $ctx = $this->makeSalon();
        // Due: starts in 1 hour (within the 120-min lead).
        $due = $this->appointment($ctx, 'confirmed', now()->addHour());
        // Not due: starts in 5 hours.
        $far = $this->appointment($ctx, 'confirmed', now()->addHours(5));

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertNotNull($due->fresh()->reminder_sent_at);
        $this->assertNull($far->fresh()->reminder_sent_at);
        $reminders = array_filter($this->sms->sent, fn ($m) => str_contains($m['message'], 'تذكير'));
        $this->assertCount(1, $reminders);

        // Running again does not re-send.
        $this->sms->sent = [];
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertCount(0, array_filter($this->sms->sent, fn ($m) => str_contains($m['message'], 'تذكير')));
    }

    public function test_cancelled_appointments_get_no_reminder(): void
    {
        $ctx = $this->makeSalon();
        $cancelled = $this->appointment($ctx, 'cancelled', now()->addHour());

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertNull($cancelled->fresh()->reminder_sent_at);
    }

    // ---- E8-3: owner notified on new booking ----------------------------------

    public function test_owner_is_notified_on_a_new_online_booking(): void
    {
        $ctx = $this->makeSalon();
        $phone = '+966501234567';
        $code = $this->postJson('/api/book/glow/otp', ['phone' => $phone])->json('debug_code');

        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $ctx['branch']->id, 'service_id' => $ctx['service']->id, 'staff_id' => $ctx['staff']->id,
            'date' => $ctx['date']->format('Y-m-d'), 'time' => '10:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $code,
        ])->assertCreated();

        $toOwner = array_filter($this->sms->sent, fn ($m) => $m['to'] === '+966500000001' && str_contains($m['message'], 'حجز جديد'));
        $this->assertCount(1, $toOwner);
        // Customer also got their confirmation.
        $this->assertNotEmpty(array_filter($this->sms->sent, fn ($m) => $m['to'] === $phone));
    }

    public function test_whatsapp_channel_routes_booking_notifications(): void
    {
        $ctx = $this->makeSalon();
        $ctx['salon']->update(['notification_channel' => 'whatsapp']);

        $phone = '+966501234567';
        $code = $this->postJson('/api/book/glow/otp', ['phone' => $phone])->json('debug_code');

        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $ctx['branch']->id, 'service_id' => $ctx['service']->id, 'staff_id' => $ctx['staff']->id,
            'date' => $ctx['date']->format('Y-m-d'), 'time' => '10:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $code,
        ])->assertCreated();

        // Confirmation + owner alert went out over WhatsApp.
        $this->assertNotEmpty(array_filter($this->whatsapp->sent, fn ($m) => $m['to'] === $phone));
        $this->assertNotEmpty(array_filter($this->whatsapp->sent, fn ($m) => str_contains($m['message'], 'حجز جديد')));

        // The OTP still went over SMS; booking notifications did not.
        $this->assertEmpty(array_filter($this->sms->sent, fn ($m) => str_contains($m['message'], 'حجز جديد')));
        $this->assertEmpty(array_filter($this->sms->sent, fn ($m) => str_contains($m['message'], 'تم تأكيد')));
    }

    // ---- E11: dashboard -------------------------------------------------------

    public function test_dashboard_reports_todays_bookings_and_revenue(): void
    {
        $ctx = $this->makeSalon();
        $today = Carbon::today($this->tz)->setTime(12, 0);
        $this->appointment($ctx, 'done', $today);            // collected revenue 100
        $this->appointment($ctx, 'confirmed', $today->clone()->addHours(2)); // expected only
        $this->appointment($ctx, 'cancelled', $today->clone()->addHours(3)); // neither

        Sanctum::actingAs($ctx['owner']);
        $res = $this->getJson('/api/dashboard')->assertOk();

        $res->assertJsonPath('totals.bookings', 3)
            ->assertJsonPath('totals.by_status.done', 1)
            ->assertJsonPath('totals.by_status.cancelled', 1)
            ->assertJsonPath('revenue.collected', 100)
            ->assertJsonPath('revenue.expected', 200);

        $this->assertSame('Lina', $res->json('by_staff.0.staff'));
        $this->assertSame(3, $res->json('by_staff.0.bookings'));
    }

    public function test_dashboard_is_owner_only(): void
    {
        $ctx = $this->makeSalon();
        Sanctum::actingAs($ctx['staff']);
        $this->getJson('/api/dashboard')->assertForbidden();
    }

    // ---- notifications on later booking changes -------------------------------

    public function test_walk_in_texts_the_customer_a_confirmation(): void
    {
        $ctx = $this->makeSalon();
        Sanctum::actingAs($ctx['owner']);

        $phone = '+966509876543';
        $this->postJson('/api/appointments', [
            'branch_id' => $ctx['branch']->id, 'service_id' => $ctx['service']->id, 'staff_id' => $ctx['staff']->id,
            'customer_name' => 'Mona', 'customer_phone' => $phone,
            'date' => $ctx['date']->format('Y-m-d'), 'time' => '11:00',
        ])->assertCreated();

        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $phone && str_contains($m['message'], 'تم تأكيد'),
        ));
    }

    public function test_customer_cancel_alerts_the_owner(): void
    {
        $ctx = $this->makeSalon();
        $appt = $this->appointment($ctx, 'confirmed', $ctx['date']->clone()->setTime(10, 0));

        $this->postJson("/api/book/manage/{$appt->public_token}/cancel")->assertOk();

        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $ctx['owner']->phone && str_contains($m['message'], 'ألغى العميل'),
        ));
    }

    public function test_customer_reschedule_texts_customer_and_owner(): void
    {
        $ctx = $this->makeSalon();
        $appt = $this->appointment($ctx, 'confirmed', $ctx['date']->clone()->setTime(10, 0));

        $this->postJson("/api/book/manage/{$appt->public_token}/reschedule", [
            'date' => $ctx['date']->format('Y-m-d'), 'time' => '12:00',
        ])->assertOk();

        $customerPhone = $appt->customer->phone;
        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $customerPhone && str_contains($m['message'], 'تم تغيير موعدك'),
        ));
        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $ctx['owner']->phone && str_contains($m['message'], 'غيّر العميل'),
        ));
    }

    public function test_salon_cancel_texts_the_customer(): void
    {
        $ctx = $this->makeSalon();
        $appt = $this->appointment($ctx, 'confirmed', $ctx['date']->clone()->setTime(10, 0));

        Sanctum::actingAs($ctx['owner']);
        $this->patchJson("/api/appointments/{$appt->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $appt->customer->phone && str_contains($m['message'], 'تم إلغاء موعدك'),
        ));
    }

    public function test_customer_note_is_stored_and_sent_to_owners(): void
    {
        $ctx = $this->makeSalon();
        $phone = '+966508887766';
        $code = $this->postJson('/api/book/glow/otp', ['phone' => $phone])->json('debug_code');

        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $ctx['branch']->id, 'service_id' => $ctx['service']->id, 'staff_id' => $ctx['staff']->id,
            'date' => $ctx['date']->format('Y-m-d'), 'time' => '10:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $code,
            'note' => 'أعاني من حساسية، يرجى استخدام منتجات خالية من العطور',
        ])->assertCreated();

        Tenancy::set($ctx['salon']);
        $this->assertSame(
            'أعاني من حساسية، يرجى استخدام منتجات خالية من العطور',
            Appointment::where('customer_id', Customer::where('phone', $phone)->first()->id)->first()->customer_note,
        );
        Tenancy::clear();

        // The owner alert carries the note so the salon can prepare.
        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $ctx['owner']->phone && str_contains($m['message'], 'ملاحظة:'),
        ));
    }

    public function test_messages_follow_the_salon_language_setting(): void
    {
        $ctx = $this->makeSalon();
        $ctx['salon']->update(['locale' => 'en']); // switch this salon to English

        $phone = '+966501112233';
        $code = $this->postJson('/api/book/glow/otp', ['phone' => $phone])->json('debug_code');
        $this->postJson('/api/book/glow/appointments', [
            'branch_id' => $ctx['branch']->id, 'service_id' => $ctx['service']->id, 'staff_id' => $ctx['staff']->id,
            'date' => $ctx['date']->format('Y-m-d'), 'time' => '10:00', 'name' => 'Sara', 'phone' => $phone, 'code' => $code,
        ])->assertCreated();

        // Customer confirmation + owner alert are now in English.
        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $phone && str_contains($m['message'], 'is confirmed for'),
        ));
        $this->assertNotEmpty(array_filter(
            $this->sms->sent,
            fn ($m) => $m['to'] === $ctx['owner']->phone && str_contains($m['message'], 'New booking'),
        ));
    }
}
