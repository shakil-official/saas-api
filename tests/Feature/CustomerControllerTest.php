<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Plan::factory()->create([
            'slug' => 'free',
            'feature_limits' => ['max_users' => 5, 'max_customers' => 2],
        ]);

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->for($this->tenant)->create();
        $this->admin->assignRole('admin');

        app()->instance('currentTenantId', $this->tenant->id);
        app(\App\Services\SubscriptionService::class)->subscribe(
            $this->tenant,
            Plan::where('slug', 'free')->first()
        );
    }

    protected function authHeaders(): array
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_it_creates_a_customer(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/customers', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'John Doe')
            ->assertJsonPath('email', 'john@example.com');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'email' => 'john@example.com',
        ]);
    }

    public function test_it_rejects_duplicate_email_within_the_same_tenant(): void
    {
        Customer::factory()->for($this->tenant)->create(['email' => 'dup@example.com']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/customers', [
                'name' => 'Someone Else',
                'email' => 'dup@example.com',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_it_allows_the_same_email_across_different_tenants(): void
    {
        $otherTenant = Tenant::factory()->create();
        Customer::factory()->for($otherTenant)->create(['email' => 'shared@example.com']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/customers', [
                'name' => 'My Customer',
                'email' => 'shared@example.com',
            ]);

        $response->assertStatus(201);
    }

    public function test_it_enforces_the_plan_customer_limit(): void
    {
        Customer::factory()->for($this->tenant)->count(2)->create(); // matches max_customers=2

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/customers', [
                'name' => 'One Too Many',
                'email' => 'overflow@example.com',
            ]);

        $response->assertStatus(403)->assertJsonPath('error', 'plan_limit_exceeded');
    }

    public function test_it_lists_customers_with_pagination_and_search(): void
    {
        Customer::factory()->for($this->tenant)->create(['name' => 'Alice Johnson']);
        Customer::factory()->for($this->tenant)->create(['name' => 'Bob Smith']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/customers?search=Alice&per_page=10');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Alice Johnson'));
        $this->assertFalse($names->contains('Bob Smith'));
    }

    public function test_it_updates_a_customer(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['name' => 'Old Name']);

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson("/api/customers/{$customer->id}", ['name' => 'New Name']);

        $response->assertStatus(200)->assertJsonPath('name', 'New Name');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'New Name']);
    }

    public function test_it_soft_deletes_a_customer(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create();

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/customers', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }
}
