<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use BelongsToSalon;

    protected $fillable = [
        'salon_id', 'plan', 'status', 'price', 'currency', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'gateway', 'gateway_reference',
        'card_brand', 'card_last4', 'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }
}
