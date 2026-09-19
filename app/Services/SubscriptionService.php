<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates all subscription/feature-limit business rules so that
 * controllers stay thin and this logic is unit-testable in isolation.
 */
class SubscriptionService
{
    public function subscribe(Tenant $tenant, Plan $plan): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan) {
            // Cancel any existing active subscription first.
            Subscription::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => $plan->billing_cycle === 'yearly'
                    ? now()->addYear()
                    : now()->addMonth(),
            ]);

            Cache::forget("tenant:{$tenant->id}:active_plan");

            return $subscription;
        });
    }

    /** Cached lookup of the tenant's current plan, since it's read on almost every request. */
    public function currentPlan(Tenant $tenant): ?Plan
    {
        return Cache::remember(
            "tenant:{$tenant->id}:active_plan",
            now()->addHour(),
            function () use ($tenant) {
                $subscription = Subscription::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->latest('starts_at')
                    ->with('plan')
                    ->first();

                return $subscription?->plan;
            }
        );
    }

    /**
     * The core "can this tenant do X" check, e.g. before creating a user:
     *   $service->assertWithinLimit($tenant, 'max_users', fn () => $tenant->users()->count());
     */
    public function assertWithinLimit(Tenant $tenant, string $limitKey, callable $currentCountResolver): void
    {
        $plan = $this->currentPlan($tenant);
        $limit = $plan?->limit($limitKey);

        // null/unset limit means "unlimited" for that feature.
        if ($limit === null) {
            return;
        }

        if ($currentCountResolver() >= $limit) {
            throw new \App\Exceptions\PlanLimitExceededException(
                "The '{$limitKey}' limit ({$limit}) for your current plan has been reached."
            );
        }
    }
}
