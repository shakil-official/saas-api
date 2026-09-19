<?php

namespace Tests\Unit;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_throws_when_limit_is_reached(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'feature_limits' => ['max_customers' => 2],
        ]);

        $service = app(SubscriptionService::class);
        $service->subscribe($tenant, $plan);

        $this->expectException(PlanLimitExceededException::class);

        $service->assertWithinLimit($tenant, 'max_customers', fn () => 2);
    }

    public function test_it_allows_when_under_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'feature_limits' => ['max_customers' => 5],
        ]);

        $service = app(SubscriptionService::class);
        $service->subscribe($tenant, $plan);

        $service->assertWithinLimit($tenant, 'max_customers', fn () => 3);

        $this->assertTrue(true); // no exception thrown = pass
    }

    public function test_null_limit_means_unlimited(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create([
            'feature_limits' => ['max_customers' => null],
        ]);

        $service = app(SubscriptionService::class);
        $service->subscribe($tenant, $plan);

        $service->assertWithinLimit($tenant, 'max_customers', fn () => 999999);

        $this->assertTrue(true);
    }
}
