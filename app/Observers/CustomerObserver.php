<?php

namespace App\Observers;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        $this->bustDashboardCache($customer);
    }

    public function updated(Customer $customer): void
    {
        $this->bustDashboardCache($customer);
    }

    public function deleted(Customer $customer): void
    {
        $this->bustDashboardCache($customer);
    }

    protected function bustDashboardCache(Customer $customer): void
    {
        Cache::forget("tenant:{$customer->tenant_id}:dashboard:summary");
    }
}
