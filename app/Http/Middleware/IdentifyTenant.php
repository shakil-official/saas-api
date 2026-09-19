<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves "who is the current tenant" for this request and binds it
 * into the container as 'currentTenantId'. TenantScope (see app/Scopes)
 * reads this binding to filter every tenant-owned model automatically.
 *
 * Resolution order:
 *   1. Authenticated user's tenant_id (normal case, via Sanctum)
 *   2. X-Tenant-Slug header (useful for platform-admin / testing tools)
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = null;

        if ($request->user()) {
            $tenantId = $request->user()->tenant_id;
        } elseif ($slug = $request->header('X-Tenant-Slug')) {
            $tenantId = \App\Models\Tenant::where('slug', $slug)->value('id');
        }

        if (! $tenantId) {
            return response()->json([
                'message' => 'Unable to resolve tenant for this request.',
            ], 400);
        }

        app()->instance('currentTenantId', $tenantId);

        return $next($request);
    }
}
