<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,public_id'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'reports_to_position_id' => ['nullable', 'uuid', 'exists:positions,public_id'],
            'authority_grade_id' => ['required', 'exists:authority_grades,public_id'],
            'is_department_head' => ['boolean'],
        ];
    }
}
