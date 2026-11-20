<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('currency')) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }
}
