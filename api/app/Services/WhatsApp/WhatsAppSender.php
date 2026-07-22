<?php

namespace App\Services\WhatsApp;

use App\Services\Messaging\MessageChannel;

interface WhatsAppSender extends MessageChannel
{
    /**
     * Send a WhatsApp text message. Returns true on success, false on a
     * delivery failure; throws on hard configuration errors.
     */
    public function send(string $to, string $message): bool;
}
