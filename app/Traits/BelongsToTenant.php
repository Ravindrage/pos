<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

/**
 * Automatic multi-tenant isolation.
 *
 * Any model that uses this trait:
 *  - auto-fills `uuid` and `business_id` on creation from the authenticated user
 *  - is automatically scoped to the logged-in user's business on every query
 *
 * This is what guarantees a user from Business A can never read or write
 * Business B's rows, even by guessing/changing an ID in a request — the
 * global scope filters it out before it ever reaches the controller.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->business_id) && Auth::check() && Auth::user()->business_id) {
                $model->business_id = Auth::user()->business_id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Auth::check() && Auth::user()->business_id) {
                $builder->where(
                    $builder->getModel()->getTable() . '.business_id',
                    Auth::user()->business_id
                );
            }
        });
    }

    /**
     * Escape hatch for trusted background jobs / console commands that run
     * outside a request (no authenticated user), e.g. queue workers, seeders.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->withoutGlobalScope('tenant')->where('business_id', $businessId);
    }
}
