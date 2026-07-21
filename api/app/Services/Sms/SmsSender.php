<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * Send an SMS. Returns true on success.
     * Implementations should throw on hard configuration errors and
     * return false on delivery failures they want the caller to retry.
     */
    public function send(string $to, string $message): bool;
}
