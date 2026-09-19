<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_cache_is_invalidated_when_a_customer_is_created(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Plan::factory()->create(['slug' => 'free']);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->for($tenant)->create();
        $admin->assignRole('admin');

        app()->instance('currentTenantId', $tenant->id);
        app(\App\Services\SubscriptionService::class)->subscribe(
            $tenant, Plan::where('slug', 'free')->first()
        );

        $token = $admin->createToken('test')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        // First hit populates the cache.
        $first = $this->withHeaders($headers)->getJson('/api/dashboard/summary');
        $first->assertStatus(200)->assertJsonPath('total_customers', 0);

        $this->assertNotNull(Cache::get("tenant:{$tenant->id}:dashboard:summary"));

        // Creating a customer must bust that cache key (CustomerObserver).
        $this->withHeaders($headers)->postJson('/api/customers', [
            'name' => 'New Customer', 'email' => 'new@example.com',
        ])->assertStatus(201);

        $this->assertNull(
            Cache::get("tenant:{$tenant->id}:dashboard:summary"),
            'Dashboard cache should be cleared by CustomerObserver after a customer is created.'
        );

        // Next read recomputes and reflects the new count.
        $second = $this->withHeaders($headers)->getJson('/api/dashboard/summary');
        $second->assertStatus(200)->assertJsonPath('total_customers', 1);
    }

    public function test_plans_cache_is_invalidated_when_a_plan_changes(): void
    {
        $plan = Plan::factory()->create(['name' => 'Original Name', 'is_active' => true]);

        $this->getJson('/api/plans', ['Accept' => 'application/json'])->assertStatus(200);
        $this->assertNotNull(Cache::get('plans:active'));

        $plan->update(['name' => 'Renamed Plan']);

        $this->assertNull(
            Cache::get('plans:active'),
            'plans:active cache should be cleared by PlanObserver after a plan is saved.'
        );

        $response = $this->getJson('/api/plans', ['Accept' => 'application/json']);
        $response->assertStatus(200);
        $this->assertTrue(collect($response->json('data'))->pluck('name')->contains('Renamed Plan'));
    }
}
