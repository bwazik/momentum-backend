<?php

namespace App\Modules\Iam\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListActiveDelegationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delegator_user_id' => ['nullable', 'string', 'exists:users,public_id'],
            'delegate_user_id' => ['nullable', 'string', 'exists:users,public_id'],
            'blueprint_category_id' => ['nullable', 'string', 'exists:blueprint_categories,public_id'],
            'stage_type_id' => ['nullable', 'string', 'exists:stage_types,public_id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
