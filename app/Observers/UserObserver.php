<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UserObserver
{
    public function created(User $user): void
    {
        $this->bustDashboardCache($user);
    }

    public function updated(User $user): void
    {
        $this->bustDashboardCache($user);
    }

    public function deleted(User $user): void
    {
        $this->bustDashboardCache($user);
    }

    protected function bustDashboardCache(User $user): void
    {
        if ($user->tenant_id) {
            Cache::forget("tenant:{$user->tenant_id}:dashboard:summary");
        }
    }
}
