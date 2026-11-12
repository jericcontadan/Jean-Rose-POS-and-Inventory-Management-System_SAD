<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the Update price form (PUT /products/{product}/price). OWNER only.
// Only the new unit price is read from the browser.
// Max 9999999999.99: the biggest value product.unit_price (DECIMAL(12,2)) can hold.
class PriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already in the can:owner group; this is a second check on the server.
        return $this->user()?->can('owner') ?? false;
    }

    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'unit_price.required' => 'Enter the new unit price.',
            'unit_price.numeric' => 'The price must be a number, like 125.00.',
            'unit_price.gt' => 'The price must be greater than 0.',
            'unit_price.decimal' => 'The price can have at most 2 decimals.',
            'unit_price.max' => 'The price is too big.',
        ];
    }
}
