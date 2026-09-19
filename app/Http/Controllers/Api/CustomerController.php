<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerRepositoryInterface $customers,
        protected SubscriptionService $subscriptions,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'search', 'from', 'to', 'sort_by', 'sort_dir']);
        $perPage = min((int) $request->get('per_page', 15), 100);

        $customers = $this->customers->paginate($filters, $perPage);

        return CustomerResource::collection($customers);
    }

    public function store(CustomerRequest $request)
    {
        $tenant = $request->user()->tenant;

        // Enforce plan-based feature limit before creating.
        $this->subscriptions->assertWithinLimit(
            $tenant,
            'max_customers',
            fn () => Customer::count()
        );

        $customer = $this->customers->create($request->validated());

        return new CustomerResource($customer);
    }

    public function show(Customer $customer)
    {
        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $customer = $this->customers->update($customer, $request->validated());

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer)
    {
        $this->customers->delete($customer);

        return response()->json(['message' => 'Customer deleted.']);
    }
}
