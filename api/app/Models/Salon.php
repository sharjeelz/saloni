<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Salon extends Model
{
    protected $fillable = [
        'name', 'slug', 'phone', 'vat_number', 'logo_path', 'brand_color',
        'instagram', 'facebook', 'tiktok', 'youtube',
        'timezone', 'locale', 'notification_channel', 'plan', 'trial_ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every new salon starts on a 14-day free trial unless one is set.
        static::creating(function (Salon $salon) {
            if ($salon->trial_ends_at === null) {
                $salon->trial_ends_at = now()->addDays(14);
            }
        });
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Whether the salon may use the console: a subscription still within its
     * paid period (active or cancelled-but-not-expired), or a live trial.
     */
    public function hasBillingAccess(): bool
    {
        // Fresh query (not the cached relation) so a just-created subscription counts.
        $sub = $this->subscription()->first();
        if ($sub && $sub->current_period_end && $sub->current_period_end->isFuture()) {
            return true;
        }

        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /** Trial ended with no paying subscription — the console is behind the paywall. */
    public function isTrialLocked(): bool
    {
        return ! $this->hasBillingAccess();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
