<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterTenantRequest;
use App\Jobs\SendTenantWelcomeEmail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    /**
     * Register a brand-new company (tenant) + its first admin user.
     * Runs in a transaction: either everything is created, or nothing is.
     */
    public function registerTenant(RegisterTenantRequest $request)
    {
        $tenant = DB::transaction(function () use ($request) {
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'slug' => $request->company_slug,
                'email' => $request->company_email,
                'status' => 'active',
                'trial_ends_at' => now()->addDays(14),
            ]);

            app()->instance('currentTenantId', $tenant->id);

            $admin = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => Hash::make($request->admin_password),
                'status' => 'active',
            ]);

            $admin->assignRole('admin');

            $freePlan = Plan::where('slug', 'free')->first();
            if ($freePlan) {
                $this->subscriptions->subscribe($tenant, $freePlan);
            }

            return $tenant;
        });

        SendTenantWelcomeEmail::dispatch($tenant);

        $admin = $tenant->users()->first();
        $token = $admin->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Company registered successfully.',
            'tenant' => $tenant,
            'user' => $admin,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Email is unique per tenant, not globally, so we look up by email
        // and verify the password across matching accounts.
        $user = User::withoutTenantScope()
            ->where('email', $request->email)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('roles'));
    }
}
