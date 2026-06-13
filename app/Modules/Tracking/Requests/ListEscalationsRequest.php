<?php

namespace App\Modules\Tracking\Requests;

use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEscalationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(EscalationStatus::class)],
            'type' => ['nullable', Rule::enum(EscalationType::class)],
            'assigned_to_me' => ['nullable', 'boolean'],
            'task_id' => ['nullable', 'string', 'uuid'],
            'blueprint_id' => ['nullable', 'string', 'uuid'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
