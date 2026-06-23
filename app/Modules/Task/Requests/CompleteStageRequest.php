<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'completion_note' => ['nullable', 'string', 'max:5000'],
            'target_stage_id' => ['nullable', 'string', 'exists:blueprint_stages,public_id'],
        ];
    }
}
