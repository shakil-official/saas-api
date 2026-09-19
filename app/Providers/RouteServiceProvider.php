<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // General API traffic: limited per authenticated user (or IP as fallback),
        // and additionally scaled by the tenant's plan so higher tiers get more headroom.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $limit = 60;

            if ($user?->tenant) {
                $plan = app(\App\Services\SubscriptionService::class)->currentPlan($user->tenant);
                $limit = $plan?->limit('api_rate_limit', 60) ?? 60;
            }

            return Limit::perMinute($limit)->by($user?->id ?: $request->ip());
        });

        // Tighter limits on auth endpoints to slow down brute-force / abuse.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
    }
}
