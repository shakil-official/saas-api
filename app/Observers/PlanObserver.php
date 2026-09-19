<?php

namespace App\Observers;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

class PlanObserver
{
    public function saved(Plan $plan): void
    {
        Cache::forget('plans:active');
    }

    public function deleted(Plan $plan): void
    {
        Cache::forget('plans:active');
    }
}
