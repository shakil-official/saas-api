<?php

namespace App\Providers;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Eloquent\EloquentCustomerRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Central place to swap implementations, e.g. for testing
     * with an in-memory repository, without touching controllers.
     */
    public array $bindings = [
        CustomerRepositoryInterface::class => EloquentCustomerRepository::class,
    ];

    public function register(): void
    {
        //
    }
}
