<?php

namespace App\Modules\Iam\Requests;

use App\Enums\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantMonitoringScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::enum(ScopeType::class)->only([
                ScopeType::TENANT,
                ScopeType::OWN_DEPARTMENT,
                ScopeType::SPECIFIC_DEPARTMENT,
                ScopeType::DEPARTMENT_TREE,
            ])],
            'scope_department_id' => [
                'required_if:scope_type,3,4',
                'nullable',
                'exists:departments,public_id',
            ],
            'blueprint_category_id' => ['nullable', 'integer'],
        ];
    }
}
