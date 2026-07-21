<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\Booking\BookingNotifier;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Send SMS reminders for upcoming confirmed appointments (E8-2)';

    public function handle(BookingNotifier $notifier): int
    {
        $lead = (int) config('booking.reminder_lead_minutes', 120);
        $now = now();
        $cutoff = $now->copy()->addMinutes($lead);

        // No tenant is pinned in the console, so this spans all salons.
        $due = Appointment::query()
            ->where('status', 'confirmed')
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $cutoff)
            ->get();

        foreach ($due as $appointment) {
            $notifier->reminder($appointment);
            $appointment->update(['reminder_sent_at' => now()]);
        }

        $this->info("Sent {$due->count()} reminder(s).");

        return self::SUCCESS;
    }
}
