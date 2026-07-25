<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\Salon;
use App\Models\User;
use App\Services\Messaging\MessageChannel;
use App\Services\Sms\SmsSender;
use App\Services\WhatsApp\WhatsAppSender;

/**
 * All booking-related messaging in one place: the customer's confirmation,
 * reminder, reschedule and cancellation notices, plus the owner alerts.
 *
 * Copy lives in lang/{locale}/sms.php and is rendered in the salon's chosen
 * language (its `locale` setting — ar or en); the time is formatted in that
 * language too. Messages render in the salon's timezone and go out on the
 * salon's preferred channel (SMS or WhatsApp); the active gateway (or the log
 * driver) does the delivery.
 *
 * The service name is the salon's primary `name`, which the owner authored in
 * their own language, so it reads correctly inside the sentence.
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
            $this->line($salon, 'sms.confirmation', [
                'salon' => $salon->name,
                'service' => $appointment->service->name,
                'staff' => $appointment->staff->name,
                'when' => $this->when($appointment),
                'ref' => $appointment->reference,
            ]),
        );
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
            $this->line($salon, 'sms.reschedule', [
                'salon' => $salon->name,
                'service' => $appointment->service->name,
                'staff' => $appointment->staff->name,
                'when' => $this->when($appointment),
                'ref' => $appointment->reference,
            ]),
        );
    }

    /** The salon cancelled the customer's booking — let the customer know (E7-3). */
    public function cancelledBySalon(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');
        $salon = $appointment->salon;

        $this->channel($salon)->send(
            $appointment->customer->phone,
            $this->line($salon, 'sms.cancelled', [
                'salon' => $salon->name,
                'service' => $appointment->service->name,
                'when' => $this->when($appointment),
            ]),
        );
    }

    /** Reminder ahead of the appointment (E8-2). */
    public function reminder(Appointment $appointment): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');
        $salon = $appointment->salon;

        $this->channel($salon)->send(
            $appointment->customer->phone,
            $this->line($salon, 'sms.reminder', [
                'salon' => $salon->name,
                'service' => $appointment->service->name,
                'staff' => $appointment->staff->name,
                'when' => $this->when($appointment),
            ]),
        );
    }

    /** Alert the salon's owners when a new online booking lands (E8-3). */
    public function notifyOwners(Appointment $appointment): void
    {
        // Surface the customer's note so the salon can prepare before they arrive.
        $this->sendToOwners($appointment, 'sms.owner_new', withNote: true);
    }

    /** Alert owners that a customer cancelled their own booking (E6-4). */
    public function ownerBookingCancelled(Appointment $appointment): void
    {
        $this->sendToOwners($appointment, 'sms.owner_cancelled');
    }

    /** Alert owners that a customer moved their own booking (E6-4). */
    public function ownerBookingRescheduled(Appointment $appointment): void
    {
        $this->sendToOwners($appointment, 'sms.owner_reschedule');
    }

    /** Send one owner-facing line to every active owner of the salon. */
    protected function sendToOwners(Appointment $appointment, string $key, bool $withNote = false): void
    {
        $appointment->loadMissing('salon', 'service', 'staff', 'customer');
        $salon = $appointment->salon;

        $message = $this->line($salon, $key, [
            'customer' => $appointment->customer->name,
            'service' => $appointment->service->name,
            'staff' => $appointment->staff->name,
            'when' => $this->when($appointment),
        ]);

        if ($withNote && filled($appointment->customer_note)) {
            $message .= ' ' . $this->line($salon, 'sms.note', ['note' => $appointment->customer_note]);
        }

        // Scope to this appointment's salon explicitly (not ambient tenant),
        // so an untenanted caller can't blast every salon's owners.
        $owners = User::withoutGlobalScope('salon')
            ->where('salon_id', $appointment->salon_id)
            ->where('role', 'owner')->where('is_active', true)
            ->whereNotNull('phone')->get();

        $channel = $this->channel($salon);

        foreach ($owners as $owner) {
            $channel->send($owner->phone, $message);
        }
    }

    /** The channel this salon has chosen for booking notifications. */
    protected function channel(Salon $salon): MessageChannel
    {
        return $salon->notification_channel === 'whatsapp' ? $this->whatsapp : $this->sms;
    }

    /** Render a copy line in the salon's language. */
    protected function line(Salon $salon, string $key, array $replace): string
    {
        return trans($key, $replace, $this->localeOf($salon));
    }

    /** The salon's message language, falling back to Arabic. */
    protected function localeOf(Salon $salon): string
    {
        return in_array($salon->locale, ['ar', 'en'], true) ? $salon->locale : 'ar';
    }

    /** Human-friendly local time in the salon's language, e.g. "الثلاثاء 28 يوليو · 14:00". */
    protected function when(Appointment $appointment): string
    {
        $tz = $appointment->salon->timezone ?? 'Asia/Riyadh';

        return $appointment->starts_at->copy()->setTimezone($tz)
            ->locale($this->localeOf($appointment->salon))
            ->isoFormat('ddd D MMM · HH:mm');
    }
}
