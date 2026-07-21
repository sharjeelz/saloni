<?php

namespace App\Support;

use App\Models\Salon;

/**
 * Holds the current tenant (salon) for the request lifecycle.
 * Set from the authenticated user, a subdomain, or the public
 * booking route, then read by the BelongsToSalon global scope.
 */
class Tenancy
{
    protected static ?int $salonId = null;

    public static function set(Salon|int|null $salon): void
    {
        static::$salonId = $salon instanceof Salon ? $salon->id : $salon;
    }

    public static function id(): ?int
    {
        return static::$salonId;
    }

    public static function check(): bool
    {
        return static::$salonId !== null;
    }

    public static function clear(): void
    {
        static::$salonId = null;
    }
}
