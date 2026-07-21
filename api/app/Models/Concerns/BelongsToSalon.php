<?php

namespace App\Models\Concerns;

use App\Models\Salon;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-based multi-tenancy. Any model using this trait is automatically
 * scoped to the current salon (set via Tenancy::set()) on every query,
 * and has its salon_id filled on create. Super-admin / console context
 * has no current salon, so no scope is applied there.
 */
trait BelongsToSalon
{
    public static function bootBelongsToSalon(): void
    {
        static::addGlobalScope('salon', function (Builder $builder) {
            if ($salonId = Tenancy::id()) {
                $builder->where($builder->getModel()->getTable() . '.salon_id', $salonId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->salon_id) && ($salonId = Tenancy::id())) {
                $model->salon_id = $salonId;
            }
        });
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /** Escape hatch for cross-tenant queries (super-admin, jobs, reports). */
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope('salon');
    }
}
