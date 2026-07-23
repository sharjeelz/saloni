<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use BelongsToSalon;

    protected $fillable = [
        'salon_id', 'image_path', 'caption', 'link_url', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
