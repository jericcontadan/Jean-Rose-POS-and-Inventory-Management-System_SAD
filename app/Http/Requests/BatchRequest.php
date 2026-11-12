<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Checks the "Add new batch" form on the Edit product page (POST /products/{product}/batches). OWNER only.
// The same batch rules are used for the first batch on Add product (see ProductRequest).
class BatchRequest extends FormRequest
{
    // Errors go to their own bag, so they show under "Add new batch" and not under the product fields.
    protected $errorBag = 'batch';

    public function authorize(): bool
    {
        // The route is already in the can:owner group; this is a second check on the server.
        return $this->user()?->can('owner') ?? false;
    }

    public function rules(): array
    {
        $rules = self::batchRules();

        // The batch number must be new for THIS product (UNIQUE(product_id, batch_number) in the database).
        $rules['batch_number'][] = Rule::unique('product_batch', 'batch_number')
            ->where('product_id', $this->route('product')->product_id);

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['batch_number' => trim((string) $this->input('batch_number'))]);
    }

    public function messages(): array
    {
        return self::batchMessages();
    }

    // Batch fields, shared with ProductRequest (Add product's first batch).
    public static function batchRules(): array
    {
        return [
            'batch_number' => ['required', 'string', 'max:50'],
            'expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public static function batchMessages(): array
    {
        return [
            'batch_number.required' => 'Enter the batch number.',
            'batch_number.max' => 'The batch number can have at most 50 characters.',
            'batch_number.unique' => 'This batch number already exists for this product.',
            'expiry_date.required' => 'Enter the expiry date.',
            'expiry_date.date_format' => 'Enter a valid expiry date.',
            'expiry_date.after_or_equal' => 'The expiry date must be today or later.',
            'quantity.required' => 'Enter the quantity received.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.max' => 'The quantity can be at most 100000.',
            'reorder_level.required' => 'Enter the reorder level.',
            'reorder_level.integer' => 'The reorder level must be a whole number.',
            'reorder_level.min' => 'The reorder level cannot be negative.',
            'reorder_level.max' => 'The reorder level can be at most 100000.',
        ];
    }
}
