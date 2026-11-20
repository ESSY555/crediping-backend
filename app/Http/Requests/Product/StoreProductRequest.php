<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Tenancy\TenantManager;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'sku')->where('business_id', TenantManager::businessId()),
            ],
            'description' => ['nullable', 'string'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
