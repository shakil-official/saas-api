<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'slug' => fake()->unique()->slug(2),
            'price' => fake()->randomFloat(2, 0, 100),
            'billing_cycle' => 'monthly',
            'feature_limits' => ['max_users' => 10, 'max_customers' => 100, 'api_rate_limit' => 60],
            'is_active' => true,
        ];
    }
}
