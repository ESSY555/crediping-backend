<?php

namespace App\Http\Requests\Invoice;

use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $businessId = TenantManager::businessId();
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'invoice_number' => ['nullable', 'string', 'max:50', Rule::unique('invoices', 'invoice_number')->where('business_id', $businessId)],
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['draft', 'sent'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'items.*.description' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            // A product supplies the price. Unit price is accepted only for custom lines.
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
