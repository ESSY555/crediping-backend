<?php

namespace App\Http\Requests\Invoice;

use App\Tenancy\TenantManager;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function rules(): array
    {
        $businessId = TenantManager::businessId();
        return [
            'customer_id' => ['sometimes', 'required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'invoice_number' => ['sometimes', 'string', 'max:50', Rule::unique('invoices', 'invoice_number')->where('business_id', $businessId)->ignore($this->route('invoice'))],
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['draft', 'sent'])],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
