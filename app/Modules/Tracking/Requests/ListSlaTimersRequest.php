<?php

namespace App\Modules\Tracking\Requests;

use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSlaTimersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(SlaTimerStatus::class)],
            'task_id' => ['nullable', 'string', 'uuid'],
            'blueprint_id' => ['nullable', 'string', 'uuid'],
            'stage_id' => ['nullable', 'string', 'uuid'],
            'assigned_user_id' => ['nullable', 'string', 'uuid'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'deadline_from' => ['nullable', 'date'],
            'deadline_to' => ['nullable', 'date', 'after_or_equal:deadline_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
