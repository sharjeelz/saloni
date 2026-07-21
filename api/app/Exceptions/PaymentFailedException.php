<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a gateway charge is declined or errors. */
class PaymentFailedException extends RuntimeException
{
}
