<?php

namespace App\Http\Requests\Product;

use App\Tenancy\TenantManager;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'sku')
                    ->where('business_id', TenantManager::businessId())
                    ->ignore($productId),
            ],
            'description' => ['nullable', 'string'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
