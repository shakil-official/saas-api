<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'email' => fake()->unique()->companyEmail(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ];
    }
}
