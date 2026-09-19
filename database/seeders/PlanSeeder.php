<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0,
                'billing_cycle' => 'monthly',
                'feature_limits' => ['max_users' => 3, 'max_customers' => 50, 'api_rate_limit' => 30],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 29,
                'billing_cycle' => 'monthly',
                'feature_limits' => ['max_users' => 15, 'max_customers' => 1000, 'api_rate_limit' => 120],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 99,
                'billing_cycle' => 'monthly',
                'feature_limits' => ['max_users' => null, 'max_customers' => null, 'api_rate_limit' => 600],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
