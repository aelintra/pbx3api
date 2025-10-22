<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Stancl\Tenancy\Tenancy;

trait TenantAware
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootTenantAware()
    {
        // Apply tenant scoping automatically for all operations
        if (tenancy()->initialized) {
            static::addGlobalScope('tenant', function (Builder $builder) {
                $builder->where('cluster', tenant('pkey'));
            });
            
            // Set the tenant ID automatically when creating a model
            static::creating(function ($model) {
                if (!$model->isDirty('cluster') && tenancy()->initialized) {
                    $model->cluster = tenant('pkey');
                }
            });
        }
    }
}