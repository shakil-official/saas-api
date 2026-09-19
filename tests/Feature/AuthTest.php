<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Plan::factory()->create(['slug' => 'free', 'feature_limits' => ['max_users' => 3, 'max_customers' => 50]]);
    }

    public function test_a_new_company_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'company_name' => 'Acme Inc',
            'company_slug' => 'acme',
            'company_email' => 'hello@acme.com',
            'admin_name' => 'Alice Admin',
            'admin_email' => 'alice@acme.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['tenant', 'user', 'token']);

        $this->assertDatabaseHas('tenants', ['slug' => 'acme']);
        $this->assertDatabaseHas('users', ['email' => 'alice@acme.com']);
    }

    public function test_two_tenants_can_use_the_same_admin_email_independently(): void
    {
        // Proves email uniqueness is per-tenant, not global — a core
        // multi-tenancy design decision that must be covered by a test.
        $payload = fn (string $slug) => [
            'company_name' => "Company {$slug}",
            'company_slug' => $slug,
            'company_email' => "{$slug}@example.com",
            'admin_name' => 'Shared Name',
            'admin_email' => 'owner@shared-example.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ];

        $this->postJson('/api/auth/register', $payload('tenant-a'))->assertStatus(201);
        $this->postJson('/api/auth/register', $payload('tenant-b'))->assertStatus(201);

        $this->assertEquals(2, \App\Models\User::withoutTenantScope()
            ->where('email', 'owner@shared-example.com')->count());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/auth/register', [
            'company_name' => 'Acme Inc',
            'company_slug' => 'acme2',
            'company_email' => 'hello2@acme.com',
            'admin_name' => 'Alice Admin',
            'admin_email' => 'alice2@acme.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'alice2@acme.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }
}
