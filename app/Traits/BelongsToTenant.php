<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // Auto-fill tenant_id on create so callers never have to remember it.
        static::creating(function ($model) {
            if (empty($model->tenant_id) && app()->bound('currentTenantId')) {
                $model->tenant_id = app('currentTenantId');
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Explicit escape hatch for admin/cross-tenant queries
     * (e.g. platform-level analytics jobs). Use deliberately.
     */
    public function scopeWithoutTenantScope($query)
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Route-model-binding safety net.
     *
     * Laravel's "api" middleware group auto-injects SubstituteBindings,
     * which resolves route parameters like {customer} into models — and
     * Laravel's default middleware priority can run that BEFORE our
     * IdentifyTenant middleware has bound 'currentTenantId' into the
     * container. If that happens, TenantScope silently has nothing to
     * filter by, and a route like GET /customers/{customer} could resolve
     * a customer belonging to a DIFFERENT tenant.
     *
     * This override closes that gap independently of middleware ordering:
     * it derives the tenant directly from the already-authenticated
     * Sanctum user (guaranteed available at this point, since Laravel's
     * built-in Authenticate middleware is unconditionally prioritized
     * before SubstituteBindings), and rejects the binding outright if the
     * resolved model belongs to someone else. Returning null here causes
     * Laravel to throw its normal "model not found" 404 — the same
     * response a truly missing record would produce, so no information
     * about the other tenant's data is leaked.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $model = static::withoutGlobalScope(TenantScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();

        if (! $model) {
            return null;
        }

        $tenantId = auth('sanctum')->user()?->tenant_id
            ?? (app()->bound('currentTenantId') ? app('currentTenantId') : null);

        if ($tenantId !== null && (int) $model->tenant_id !== (int) $tenantId) {
            return null;
        }

        return $model;
    }
}
