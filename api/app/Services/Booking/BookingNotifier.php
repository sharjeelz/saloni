<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Salon;
use App\Models\User;
use App\Services\Messaging\MessageChannel;
use App\Services\Sms\SmsSender;
use App\Services\WhatsApp\WhatsAppSender;

/**
 * All booking-related messaging in one place: the customer's confirmation and
 * reminder, and the owner's new-booking alert. Messages render in the salon's
 * timezone and go out on the salon's preferred channel (SMS or WhatsApp);
 * the active gateway (or the log driver) does the delivery.
 */
class BookingNotifier
{
    public function __construct(
        protected SmsSender $sms,
        protected WhatsAppSender $whatsapp,
    ) {}

    /** Confirmation to the customer right after booking (E8-1). */
    public function confirmation(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');
        $salon = $appointment->salon;

        $this->channel($salon)->send(
            $appointment->customer->phone,
            "{$salon->name}: your {$appointment->service->name} with {$appointment->staff->name} "
            . "is confirmed for {$this->when($appointment)}. Ref {$appointment->reference}.",
        );
    }

    /** The channel this salon has chosen for booking notifications. */
    protected function channel(Salon $salon): MessageChannel
    {
        return $salon->notification_channel === 'whatsapp' ? $this->whatsapp : $this->sms;
    }

    /** Alert the salon's owners when a new online booking lands (E8-3). */
    public function notifyOwners(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');

        // Scope to this appointment's salon explicitly (not ambient tenant),
        // so an untenanted caller can't blast every salon's owners.
        $owners = User::withoutGlobalScope('salon')
            ->where('salon_id', $appointment->salon_id)
            ->where('role', 'owner')->where('is_active', true)
            ->whereNotNull('phone')->get();

        $channel = $this->channel($appointment->salon);

        foreach ($owners as $owner) {
            $channel->send(
                $owner->phone,
                "New booking: {$appointment->customer->name} — {$appointment->service->name} "
                . "with {$appointment->staff->name} on {$this->when($appointment)}.",
            );
        }
    }

    /** Reminder ahead of the appointment (E8-2). */
    public function reminder(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');

        $this->channel($appointment->salon)->send(
            $appointment->customer->phone,
            "Reminder — {$appointment->salon->name}: your {$appointment->service->name} "
            . "with {$appointment->staff->name} is at {$this->when($appointment)}.",
        );
    }

    /** Human-friendly local time, e.g. "Tue 28 Jul · 14:00". */
    protected function when(Appointment $appointment): string
    {
        $tz = $appointment->salon->timezone ?? 'Asia/Riyadh';

        return $appointment->starts_at->copy()->setTimezone($tz)->format('D d M · H:i');
    }
}
