<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToSalon;

    protected $fillable = [
        'salon_id', 'subscription_id', 'number', 'status', 'description',
        'subtotal', 'vat_rate', 'vat_amount', 'total', 'currency',
        'seller_name', 'seller_vat_number', 'buyer_name', 'gateway_reference',
        'zatca_qr', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
