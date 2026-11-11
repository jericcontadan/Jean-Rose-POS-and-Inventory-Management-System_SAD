<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the payment form (POST /pos/pay). Any login user (owner or cashier) may pay.
// Only these three fields are used. Totals, prices or discounts sent by the browser are NOT read:
// SaleService works them out again from the database.
class PayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route is already inside the 'auth' group
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'in:CASH'], // Cash only
            'cash_received' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'pay_token' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'cash_received.required' => 'Enter the cash received.',
            'cash_received.numeric' => 'Cash received must be a number.',
            'cash_received.decimal' => 'Cash received can have at most 2 decimals.',
        ];
    }
}
