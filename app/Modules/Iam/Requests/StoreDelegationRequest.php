<?php

namespace App\Modules\Iam\Requests;

use App\Enums\DelegationScopeType;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delegator_user_id' => ['nullable', 'exists:users,public_id'],
            'delegate_user_id' => ['required', 'exists:users,public_id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'scope_type' => ['required', Rule::enum(DelegationScopeType::class)],
            'blueprint_category_id' => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => in_array(
                    (int) $this->input('scope_type'),
                    [DelegationScopeType::BLUEPRINT_CATEGORY->value, DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value],
                    true
                )),
                Rule::exists(BlueprintCategory::class, 'public_id'),
            ],
            'stage_type_id' => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => in_array(
                    (int) $this->input('scope_type'),
                    [DelegationScopeType::STAGE_TYPE->value, DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value],
                    true
                )),
                Rule::exists(StageType::class, 'public_id'),
            ],
        ];
    }
}
