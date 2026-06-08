<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reports_to_position_id' => ['nullable', 'uuid', 'exists:positions,public_id'],
            'authority_grade_id' => ['sometimes', 'required', 'exists:authority_grades,public_id'],
            'is_department_head' => ['boolean'],
        ];
    }
}
