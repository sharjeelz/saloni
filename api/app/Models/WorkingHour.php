<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingHour extends Model
{
    use BelongsToSalon;

    protected $fillable = [
        'salon_id', 'branch_id', 'user_id', 'weekday', 'start_time', 'end_time',
    ];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
