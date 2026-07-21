<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a chosen slot was taken between browsing and booking. */
class SlotUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'That time is no longer available.')
    {
        parent::__construct($message);
    }
}
