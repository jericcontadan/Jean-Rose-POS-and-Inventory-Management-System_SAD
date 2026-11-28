<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the payment form (POST /pos/pay). Any login user (owner or cashier) may pay.
// Only these fields are used. Totals, prices or discount amounts sent by the browser are NOT read:
// SaleService works them out again from the database. The browser only says WHICH discount (None, Senior, PWD).
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
            // Senior Citizen / PWD: missing means None. For Senior or PWD the ID number and the customer name are required.
            'discount_type' => ['nullable', 'in:NONE,SENIOR,PWD'],
            'discount_id_number' => ['nullable', 'required_if:discount_type,SENIOR,PWD', 'string', 'max:30'],
            'discount_customer_name' => ['nullable', 'required_if:discount_type,SENIOR,PWD', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'cash_received.required' => 'Enter the cash received.',
            'cash_received.numeric' => 'Cash received must be a number.',
            'cash_received.decimal' => 'Cash received can have at most 2 decimals.',
            'discount_type.in' => 'Choose None, Senior Citizen or PWD.',
            'discount_id_number.required_if' => 'Enter the Senior Citizen / PWD ID number.',
            'discount_id_number.max' => 'The ID number can have at most 30 characters.',
            'discount_customer_name.required_if' => 'Enter the customer name on the ID.',
            'discount_customer_name.max' => 'The customer name can have at most 120 characters.',
        ];
    }

    // The discount chosen, cleaned up: ['type' => 'NONE'|'SENIOR'|'PWD', 'id_number' => ?string, 'customer_name' => ?string].
    // For None the ID number and name are always null (even if something was typed before switching back to None).
    public function discount(): array
    {
        $type = $this->validated('discount_type') ?? 'NONE';

        if ($type === 'NONE') {
            return ['type' => 'NONE', 'id_number' => null, 'customer_name' => null];
        }

        return [
            'type' => $type,
            'id_number' => trim((string) $this->validated('discount_id_number')),
            'customer_name' => trim((string) $this->validated('discount_customer_name')),
        ];
    }
}
