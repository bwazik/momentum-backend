<?php

namespace App\Modules\Analytics\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepartmentPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'priority_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'blueprint_category_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
