<?php

namespace App\Services\Messaging;

/**
 * A one-way text delivery channel (SMS, WhatsApp, …). Booking notifications
 * are routed to whichever channel the salon prefers; OTP stays on SMS.
 */
interface MessageChannel
{
    /**
     * Deliver a plain-text message. Returns true on success, false on a
     * delivery failure the caller may want to retry; throws on hard
     * configuration errors.
     */
    public function send(string $to, string $message): bool;
}
