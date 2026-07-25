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

    /** Confirmation for a walk-in / manually created booking (E7-2). */
    public function walkInConfirmation(Appointment $appointment): void
    {
        // Same message as an online confirmation — the customer still gets their
        // time and reference to keep.
        $this->confirmation($appointment);
    }

    /** The booking moved to a new time — tell the customer its new slot (E6-4). */
    public function rescheduled(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');
        $salon = $appointment->salon;

        $this->channel($salon)->send(
            $appointment->customer->phone,
            "{$salon->name}: your {$appointment->service->name} with {$appointment->staff->name} "
            . "is now {$this->when($appointment)}. Ref {$appointment->reference}.",
        );
    }

    /** The salon cancelled the customer's booking — let the customer know (E7-3). */
    public function cancelledBySalon(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');
        $salon = $appointment->salon;

        $this->channel($salon)->send(
            $appointment->customer->phone,
            "{$salon->name}: your {$appointment->service->name} on {$this->when($appointment)} "
            . 'has been cancelled. Please contact us to rebook.',
        );
    }

    /** Alert the salon's owners when a new online booking lands (E8-3). */
    public function notifyOwners(Appointment $appointment): void
    {
        $this->sendToOwners(
            $appointment,
            "New booking: {$appointment->customer->name} — {$appointment->service->name} "
            . "with {$appointment->staff->name} on {$this->when($appointment)}.",
        );
    }

    /** Alert owners that a customer cancelled their own booking (E6-4). */
    public function ownerBookingCancelled(Appointment $appointment): void
    {
        $this->sendToOwners(
            $appointment,
            "Cancelled by customer: {$appointment->customer->name} — {$appointment->service->name} "
            . "with {$appointment->staff->name} on {$this->when($appointment)}.",
        );
    }

    /** Alert owners that a customer moved their own booking (E6-4). */
    public function ownerBookingRescheduled(Appointment $appointment): void
    {
        $this->sendToOwners(
            $appointment,
            "Rescheduled by customer: {$appointment->customer->name} — {$appointment->service->name} "
            . "with {$appointment->staff->name}, now {$this->when($appointment)}.",
        );
    }

    /** Send one message to every active owner of the appointment's salon. */
    protected function sendToOwners(Appointment $appointment, string $message): void
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
            $channel->send($owner->phone, $message);
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
