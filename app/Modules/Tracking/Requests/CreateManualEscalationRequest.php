<?php

namespace App\Modules\Tracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateManualEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'string', 'uuid'],
            'stage_instance_id' => ['nullable', 'string', 'uuid'],
            'sub_stage_instance_id' => ['nullable', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:5000'],
            'escalated_to_position_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
