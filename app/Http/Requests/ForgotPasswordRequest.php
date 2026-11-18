<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the Forgot password form (step 1).
class ForgotPasswordRequest extends FormRequest
{
    // Anyone (not logged in) may ask for a reset link.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:50'], // user_account.email is VARCHAR(50)
            // Only Gmail works for now. SMS is disabled on the page; this also stops a hand-made "sms" request.
            'by' => ['nullable', 'in:email'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please enter your email.',
            'email.email' => 'Please enter a valid email address.',
            'email.max' => 'The email may not be longer than 50 characters.',
            'by.in' => 'SMS reset is coming soon. Please use Email (Gmail).',
        ];
    }
}
