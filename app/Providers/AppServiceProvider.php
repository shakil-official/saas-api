<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Observers\CustomerObserver;
use App\Observers\PlanObserver;
use App\Observers\UserObserver;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Our API.md docs, Postman collection, and tests all assume a flat
        // JSON body (e.g. {"id":1,"name":"..."}) for single-resource
        // responses, not Laravel's default {"data": {...}} envelope.
        // Paginated collections (CustomerResource::collection() over a
        // paginator) are unaffected — they always keep "data"/"links"/"meta"
        // regardless of this setting, since that's a separate pagination
        // format contract.
        JsonResource::withoutWrapping();

        Customer::observe(CustomerObserver::class);
        User::observe(UserObserver::class);
        Plan::observe(PlanObserver::class);
    }
}
