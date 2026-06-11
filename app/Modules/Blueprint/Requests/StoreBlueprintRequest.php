<?php

namespace App\Modules\Blueprint\Requests;

use App\Modules\Blueprint\Enums\BlueprintScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'category_id' => ['required', 'exists:blueprint_categories,public_id'],
            'scope' => ['required', Rule::enum(BlueprintScope::class)],
            'department_id' => ['required_if:scope,'.BlueprintScope::Department->value, 'nullable', 'exists:departments,public_id'],
        ];
    }
}
