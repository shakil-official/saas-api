<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status')->default('active'); // active, past_due, cancelled, expired
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // A tenant can have subscription history, but we frequently query "current" one.
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'ends_at']); // used by the expiry background job
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
