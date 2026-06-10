<?php

namespace App\Modules\Iam\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'exists:positions,public_id'],
            'started_at' => ['nullable', 'date'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
