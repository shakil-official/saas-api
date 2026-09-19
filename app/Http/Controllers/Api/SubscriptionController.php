<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    public function current(Request $request)
    {
        $tenant = $request->user()->tenant;
        $plan = $this->subscriptions->currentPlan($tenant);

        return response()->json([
            'plan' => $plan,
            'usage' => [
                'users' => $tenant->users()->count(),
                'customers' => $tenant->customers()->count(),
            ],
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
        ]);

        $tenant = $request->user()->tenant;
        $plan = Plan::findOrFail($request->plan_id);

        $subscription = $this->subscriptions->subscribe($tenant, $plan);

        return response()->json([
            'message' => 'Subscription updated.',
            'subscription' => $subscription->load('plan'),
        ], 201);
    }
}
