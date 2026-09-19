<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('metric'); // e.g. api_calls, users_created, storage_mb
            $table->unsignedBigInteger('value')->default(0);
            $table->date('logged_date');
            $table->timestamps();

            $table->unique(['tenant_id', 'metric', 'logged_date']);
            $table->index(['tenant_id', 'logged_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
