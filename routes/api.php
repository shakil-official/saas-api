<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ---- Public ----
Route::post('/auth/register', [AuthController::class, 'registerTenant'])
    ->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::get('/plans', [PlanController::class, 'index']);
Route::get('/plans/{plan}', [PlanController::class, 'show']);

// ---- Authenticated + tenant-scoped ----
Route::middleware(['auth:sanctum', 'identify.tenant', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/tenant', [TenantController::class, 'show']);
    Route::patch('/tenant', [TenantController::class, 'update'])->middleware('role:admin');

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('/subscription', [SubscriptionController::class, 'current']);
    Route::post('/subscription', [SubscriptionController::class, 'subscribe'])->middleware('role:admin');

    Route::apiResource('customers', CustomerController::class);

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
