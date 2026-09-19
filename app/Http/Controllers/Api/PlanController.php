<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

class PlanController extends Controller
{
    /**
     * Plans are global (not tenant-scoped) and rarely change, so they're
     * cached platform-wide with a long TTL. Invalidated by PlanObserver.
     */
    public function index()
    {
        $plans = Cache::remember('plans:active', now()->addHours(6), function () {
            return Plan::where('is_active', true)->orderBy('price')->get();
        });

        return response()->json(['data' => $plans]);
    }

    public function show(Plan $plan)
    {
        return response()->json(['data' => $plan]);
    }
}
