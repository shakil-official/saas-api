<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;
        // PATCH/PUT = partial update: fields are optional, but must be
        // valid when present. POST = create: name/email are mandatory.
        $isUpdate = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [
                $isUpdate ? 'sometimes' : 'required',
                'email',
                'max:255',
                Rule::unique('customers', 'email')
                    ->where('tenant_id', app('currentTenantId'))
                    ->ignore($customerId),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
