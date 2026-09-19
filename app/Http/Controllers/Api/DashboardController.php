<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    /**
     * Aggregate counts are expensive (COUNT queries across tables) and don't
     * need to be real-time to the second, so we cache per-tenant for 5 minutes.
     * Cache key is tenant-scoped so tenants never see each other's numbers,
     * and it's invalidated proactively by the relevant Observers.
     */
    public function summary(Request $request)
    {
        $tenant = $request->user()->tenant;
        $cacheKey = "tenant:{$tenant->id}:dashboard:summary";

        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($tenant) {
            $plan = $this->subscriptions->currentPlan($tenant);

            return [
                'plan' => $plan?->name,
                'total_users' => User::count(),
                'total_customers' => Customer::count(),
                'active_customers' => Customer::where('status', 'active')->count(),
                'limits' => [
                    'max_users' => $plan?->limit('max_users'),
                    'max_customers' => $plan?->limit('max_customers'),
                ],
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }
}
