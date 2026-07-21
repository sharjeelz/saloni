<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToSalon;

    protected $fillable = [
        'salon_id', 'name', 'phone', 'email', 'notes', 'last_visit_at',
    ];

    protected function casts(): array
    {
        return ['last_visit_at' => 'datetime'];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
