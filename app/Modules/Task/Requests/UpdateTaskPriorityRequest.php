<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'severity_rank' => ['sometimes', 'integer'],
            'color_code' => ['nullable', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ];
    }
}
