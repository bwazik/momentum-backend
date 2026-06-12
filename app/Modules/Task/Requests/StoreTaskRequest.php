<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blueprint_id' => ['required', Rule::exists('blueprints', 'public_id')->where('is_active', true)],
            'priority_id' => ['nullable', 'exists:task_priorities,public_id'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['required', 'string'],
            'description_en' => ['nullable', 'string'],
            'classification_level' => ['nullable', Rule::enum(ClassificationLevel::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'manual_assignments' => ['nullable', 'array'],
            'manual_assignments.*.blueprint_stage_id' => ['string'],
            'manual_assignments.*.blueprint_sub_stage_id' => ['string'],
            'manual_assignments.*.user_ids' => ['required', 'array', 'min:1'],
            'manual_assignments.*.user_ids.*' => ['string', 'exists:users,public_id'],
        ];
    }
}
