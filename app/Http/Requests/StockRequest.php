<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the Update stock form (PUT /inventory/{inventory}/stock). OWNER only.
// Only the quantity to add is read from the browser: product, batch and current stock come from the database.
class StockRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already in the can:owner group; this is a second check on the server.
        return $this->user()?->can('owner') ?? false;
    }

    public function rules(): array
    {
        return [
            // A whole number of pieces, 1 to 100000.
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Enter the quantity to add.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.max' => 'The quantity can be at most 100000.',
        ];
    }
}
