<?php

namespace App\Modules\Tracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:5000'],
        ];
    }
}
