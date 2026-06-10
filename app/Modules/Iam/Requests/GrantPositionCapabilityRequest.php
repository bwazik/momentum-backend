<?php

namespace App\Modules\Iam\Requests;

use App\Enums\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantPositionCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capability_id' => ['required', 'exists:capabilities,public_id'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)->except([ScopeType::AUDIT_GRANT])],
            'scope_department_id' => [
                'required_if:scope_type,3,4',
                'nullable',
                'exists:departments,public_id',
            ],
        ];
    }
}
