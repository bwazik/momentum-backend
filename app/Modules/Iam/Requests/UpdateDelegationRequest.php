<?php

namespace App\Modules\Iam\Requests;

use App\Enums\DelegationScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date', 'after:starts_at'],
            'scope_type' => ['sometimes', 'required', Rule::enum(DelegationScopeType::class)],
            'blueprint_category_id' => ['nullable', 'integer'],
            'stage_type_id' => ['nullable', 'integer'],
        ];
    }
}
