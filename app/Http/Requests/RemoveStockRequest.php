<?php

namespace App\Http\Requests;

use App\Services\InventoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Checks the Remove stock form (PUT /inventory/{inventory}/remove). OWNER only.
// Only the quantity to remove and the reason are read from the browser. "Not more than on hand" is checked
// again in InventoryService::removeStock() with the row locked, so a sale at the same moment cannot break it.
class RemoveStockRequest extends FormRequest
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
            // Expired, Damaged or Other.
            'reason' => ['required', 'string', Rule::in(InventoryService::REMOVE_REASONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Enter the quantity to remove.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.max' => 'The quantity can be at most 100000.',
            'reason.required' => 'Choose a reason.',
            'reason.in' => 'Choose Expired, Damaged or Other.',
        ];
    }
}
