<?php

namespace App\Modules\Iam\Requests;

use App\Enums\DelegationScopeType;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;
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
        $scopeType = $this->input('scope_type');

        return [
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date', 'after:starts_at'],
            'scope_type' => ['sometimes', 'required', Rule::enum(DelegationScopeType::class)],
            'blueprint_category_id' => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => $scopeType !== null && in_array(
                    (int) $scopeType,
                    [DelegationScopeType::BLUEPRINT_CATEGORY->value, DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value],
                    true
                )),
                Rule::exists(BlueprintCategory::class, 'public_id'),
            ],
            'stage_type_id' => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => $scopeType !== null && in_array(
                    (int) $scopeType,
                    [DelegationScopeType::STAGE_TYPE->value, DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value],
                    true
                )),
                Rule::exists(StageType::class, 'public_id'),
            ],
        ];
    }
}
