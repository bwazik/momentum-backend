<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'classification_level' => ['nullable', Rule::enum(ClassificationLevel::class)],
            'priority_id' => ['nullable', 'string'],
            'blueprint_id' => ['nullable', 'string'],
            'initiator_user_id' => ['nullable', 'string'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
