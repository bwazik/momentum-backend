<?php

namespace App\Modules\Blueprint\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:blueprint_categories,public_id'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
        ];
    }
}
