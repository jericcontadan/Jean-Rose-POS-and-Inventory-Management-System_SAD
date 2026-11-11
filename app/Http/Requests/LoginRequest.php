<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the login form before the controller tries to log in.
class LoginRequest extends FormRequest
{
    // Anyone may submit the login form.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ];
    }
}
