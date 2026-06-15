<?php

namespace App\Modules\Search\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:active,suspended,completed,cancelled'],
            'priority_id' => ['nullable', 'array'],
            'priority_id.*' => ['string', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_field' => ['nullable', 'string', 'in:created_at,completed_at'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'blueprint_id' => ['nullable', 'string', 'uuid'],
            'blueprint_category_id' => ['nullable', 'string', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
