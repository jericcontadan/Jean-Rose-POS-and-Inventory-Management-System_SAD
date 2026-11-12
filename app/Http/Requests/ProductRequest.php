<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Checks the Add product form (POST /products) and the Edit product form (PUT /products/{product}). OWNER only.
// Add product also sends the first batch (batch number, expiry date, quantity received, reorder level);
// Edit product sends the product fields only.
// Max 9999999999.99: the biggest value a DECIMAL(12,2) column can hold.
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already in the can:owner group; this is a second check on the server.
        return $this->user()?->can('owner') ?? false;
    }

    public function rules(): array
    {
        $product = $this->route('product'); // null on Add product

        $rules = [
            // Unique in the product table. On Edit, the product's own number is allowed.
            'product_number' => ['required', 'string', 'max:60',
                Rule::unique('product', 'product_number')->ignore($product?->product_id, 'product_id')],
            'product_name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:category,category_id'],
            'supplier_id' => ['required', 'integer', 'exists:supplier,supplier_id'],
            'cost_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
            // Optional picture: jpg or png, at most 2 MB (2048 KB).
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];

        // Add product only: the first batch.
        if ($product === null) {
            $rules += BatchRequest::batchRules();
        }

        return $rules;
    }

    // Remove spaces around the typed text before checking it.
    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_number' => trim((string) $this->input('product_number')),
            'product_name' => trim((string) $this->input('product_name')),
            'batch_number' => trim((string) $this->input('batch_number')),
        ]);
    }

    public function messages(): array
    {
        return [
            'product_number.required' => 'Enter the product number.',
            'product_number.unique' => 'This product number already exists.',
            'product_number.max' => 'The product number can have at most 60 characters.',
            'product_name.required' => 'Enter the product name.',
            'product_name.max' => 'The product name can have at most 150 characters.',
            'category_id.*' => 'Choose a category.',
            'supplier_id.*' => 'Choose a supplier.',
            'cost_price.required' => 'Enter the cost price.',
            'cost_price.numeric' => 'The cost price must be a number, like 80.00.',
            'cost_price.min' => 'The cost price cannot be negative.',
            'cost_price.decimal' => 'The cost price can have at most 2 decimals.',
            'cost_price.max' => 'The cost price is too big.',
            'unit_price.required' => 'Enter the unit price.',
            'unit_price.numeric' => 'The unit price must be a number, like 120.00.',
            'unit_price.gt' => 'The unit price must be greater than 0.',
            'unit_price.decimal' => 'The unit price can have at most 2 decimals.',
            'unit_price.max' => 'The unit price is too big.',
            'image.file' => 'The image could not be uploaded. Please try again.',
            'image.uploaded' => 'The image could not be uploaded. Please try again.',
            'image.mimes' => 'The image must be a JPG or PNG file.',
            'image.max' => 'The image can be at most 2 MB.',
        ] + BatchRequest::batchMessages();
    }
}
