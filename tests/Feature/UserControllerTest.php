<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
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
            'feature_limits' => ['max_users' => 2, 'max_customers' => 100],
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

    protected function tokenFor(User $user): array
    {
        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_admin_can_create_a_user_with_a_role(): void
    {
        $response = $this->withHeaders($this->tokenFor($this->admin))
            ->postJson('/api/users', [
                'name' => 'Bob Manager',
                'email' => 'bob@example.com',
                'password' => 'password123',
                'role' => 'manager',
            ]);

        $response->assertStatus(201)->assertJsonPath('name', 'Bob Manager');

        $created = User::withoutTenantScope()->where('email', 'bob@example.com')->first();
        $this->assertTrue($created->hasRole('manager'));
    }

    public function test_it_enforces_the_plan_user_limit(): void
    {
        // admin already counts as 1 of max_users=2; create one more to hit the limit
        User::factory()->for($this->tenant)->create();

        $response = $this->withHeaders($this->tokenFor($this->admin))
            ->postJson('/api/users', [
                'name' => 'One Too Many',
                'email' => 'overflow@example.com',
                'password' => 'password123',
                'role' => 'staff',
            ]);

        $response->assertStatus(403)->assertJsonPath('error', 'plan_limit_exceeded');
    }

    public function test_non_admin_role_cannot_access_user_management(): void
    {
        $staff = User::factory()->for($this->tenant)->create();
        $staff->assignRole('staff');

        $response = $this->withHeaders($this->tokenFor($staff))
            ->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_admin_can_update_a_users_role_and_status(): void
    {
        $user = User::factory()->for($this->tenant)->create();
        $user->assignRole('staff');

        $response = $this->withHeaders($this->tokenFor($this->admin))
            ->patchJson("/api/users/{$user->id}", [
                'status' => 'disabled',
                'role' => 'manager',
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'disabled');
        $this->assertTrue($user->fresh()->hasRole('manager'));
        $this->assertFalse($user->fresh()->hasRole('staff'));
    }

    public function test_admin_can_delete_a_user(): void
    {
        $user = User::factory()->for($this->tenant)->create();

        $response = $this->withHeaders($this->tokenFor($this->admin))
            ->deleteJson("/api/users/{$user->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }
}
