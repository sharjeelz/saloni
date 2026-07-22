<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    use BelongsToSalon;

    protected $fillable = ['salon_id', 'name', 'name_en', 'sort_order'];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
