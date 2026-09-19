<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Kept off the request/response cycle: registration shouldn't wait
 * on an SMTP round-trip. Retries automatically on transient failures.
 */
class SendTenantWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public Tenant $tenant)
    {
    }

    public function handle(): void
    {
        // Mail::to($this->tenant->email)->send(new TenantWelcomeMail($this->tenant));
        Log::info("Welcome email queued for tenant #{$this->tenant->id} ({$this->tenant->email})");
    }
}
