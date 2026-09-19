<?php

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs on a schedule (see routes/console.php). Uses the
 * (status, ends_at) index on subscriptions so this stays cheap
 * even with a large number of tenants.
 */
class ExpireSubscriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $expired = Subscription::withoutTenantScope()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->get();

        foreach ($expired as $subscription) {
            $subscription->update(['status' => 'expired']);
            Cache::forget("tenant:{$subscription->tenant_id}:active_plan");
            Cache::forget("tenant:{$subscription->tenant_id}:dashboard:summary");
        }

        Log::info('Expired subscriptions processed: ' . $expired->count());
    }
}
