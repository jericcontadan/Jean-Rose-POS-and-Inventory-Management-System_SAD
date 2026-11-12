<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Checks the void form (POST /sales/{sale}/void). OWNER only.
// Only the reason is read from the browser. Spaces around it are removed first (TrimStrings middleware),
// so a reason of only spaces counts as empty.
// Max 247: the audit row saves "Reason: " (8 characters) + the reason in audit_log.details, which holds 255.
class VoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already in the can:owner group; this is a second check on the server.
        return $this->user()?->can('owner') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:247'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Enter a reason.',
            'reason.min' => 'The reason must be at least 5 characters.',
            'reason.max' => 'The reason can have at most 247 characters.',
        ];
    }
}
