<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user()->tenant);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $tenant = $request->user()->tenant;
        $tenant->update($data);

        return response()->json($tenant->fresh());
    }
}
