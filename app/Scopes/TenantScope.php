<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applied automatically to every model that uses BelongsToTenant.
 * This is what makes multi-tenancy "safe by default": a developer
 * cannot forget to filter by tenant_id because Eloquent does it
 * for them at the query-builder level.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app()->bound('currentTenantId') ? app('currentTenantId') : null;

        if ($tenantId) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
        }
    }
}
