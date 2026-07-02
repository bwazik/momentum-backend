<?php

namespace App\Modules\Iam\Requests;

use App\Enums\ScopeType;
use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConfidentialGovernanceParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'string', 'uuid', 'exists:positions,public_id'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'scope_department_id' => ['nullable', 'string', 'uuid', 'exists:departments,public_id'],
            'blueprint_category_id' => ['nullable', 'string', 'uuid', 'exists:blueprint_categories,public_id'],
            'applies_to_classification_level' => ['nullable', Rule::enum(ClassificationLevel::class)],
        ];
    }
}
