<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 15), 100);

        // eager-load roles to avoid N+1 when serializing role names
        $users = User::with('roles')->paginate($perPage);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $tenant = $request->user()->tenant;

        $this->subscriptions->assertWithinLimit(
            $tenant,
            'max_users',
            fn () => User::count()
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $tenant->id),
            ],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'manager', 'staff'])],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        $user->assignRole($data['role']);

        return response()->json($user->load('roles'), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'staff'])],
        ]);

        $user->update(collect($data)->except('role')->toArray());

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($user->fresh()->load('roles'));
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'User removed.']);
    }
}
