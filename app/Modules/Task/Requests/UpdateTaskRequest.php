<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => ['sometimes', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['sometimes', 'string'],
            'description_en' => ['nullable', 'string'],
            'classification_level' => ['nullable', Rule::enum(ClassificationLevel::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
