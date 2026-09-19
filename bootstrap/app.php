<?php

use App\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'identify.tenant' => IdentifyTenant::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);

        // Laravel's "api" route group auto-adds SubstituteBindings, which
        // resolves route-model-bound parameters (e.g. the {customer} in
        // GET /customers/{customer}) — including running the TenantScope
        // global scope query. Without an explicit priority, Laravel's
        // default ordering runs SubstituteBindings BEFORE our custom
        // IdentifyTenant middleware, so the tenant-scoped lookup would
        // execute before 'currentTenantId' is bound, silently returning
        // unscoped (cross-tenant!) results. This priority list forces
        // Authenticate -> IdentifyTenant -> ThrottleRequests -> SubstituteBindings,
        // so tenant scoping is guaranteed to be active before any
        // model-binding query runs.
        $middleware->priority([
            \Illuminate\Auth\Middleware\Authenticate::class,
            IdentifyTenant::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
