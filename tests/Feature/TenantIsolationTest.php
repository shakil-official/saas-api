<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_cannot_see_another_tenants_customers(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Plan::factory()->create(['slug' => 'free']);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->for($tenantA)->create();
        $userA->assignRole('admin');

        $userB = User::factory()->for($tenantB)->create();
        $userB->assignRole('admin');

        // NOTE: no app()->instance('currentTenantId', ...) here — the
        // factory's ->for($tenantX) already sets tenant_id explicitly on
        // each row, so BelongsToTenant's autofill (which only kicks in
        // when tenant_id is empty) never needs to run. Manually binding
        // 'currentTenantId' in a test and then generating a token for a
        // user of a DIFFERENT tenant is a trap: Sanctum resolves the
        // token's user via a query that's also subject to TenantScope, so
        // a stale binding would make that lookup fail with a false 401 —
        // this bit us in an earlier version of this test.
        $customerA = Customer::factory()->for($tenantA)->create(['name' => 'Tenant A Customer']);
        Customer::factory()->for($tenantB)->create(['name' => 'Tenant B Customer']);

        $token = $userA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/customers');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Tenant A Customer'));
        $this->assertFalse($names->contains('Tenant B Customer'));
    }

    public function test_a_tenant_cannot_fetch_another_tenants_customer_by_id(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Plan::factory()->create(['slug' => 'free']);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->for($tenantA)->create();
        $userA->assignRole('admin');

        $customerB = Customer::factory()->for($tenantB)->create();

        $token = $userA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/customers/{$customerB->id}");

        // Global scope makes this behave as "not found" for a foreign tenant.
        $response->assertStatus(404);
    }
}
