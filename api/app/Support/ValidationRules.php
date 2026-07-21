<?php

namespace App\Support;

class ValidationRules
{
    /**
     * Phone: optional leading '+', then 9–15 digits (E.164-ish). Rejects
     * letters/symbols. KSA mobiles are +9665XXXXXXXX.
     */
    public const PHONE = 'regex:/^\+?[0-9]{9,15}$/';
}
