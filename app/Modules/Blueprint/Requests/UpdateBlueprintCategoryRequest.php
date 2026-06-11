<?php

namespace App\Modules\Blueprint\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlueprintCategoryRequest extends FormRequest
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
            'display_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
