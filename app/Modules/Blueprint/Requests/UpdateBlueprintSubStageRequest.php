<?php

namespace App\Modules\Blueprint\Requests;

use App\Modules\Blueprint\Enums\AssignmentCardinality;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBlueprintSubStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'sla_policy_id' => ['nullable', 'exists:sla_policies,public_id'],
            'sequence_order' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'is_required' => ['nullable', 'boolean'],
            'assignment_type' => ['nullable', Rule::enum(AssignmentType::class)],
            'assigned_position_id' => ['nullable', 'exists:positions,public_id'],
            'assigned_department_id' => ['nullable', 'exists:departments,public_id'],
            'assignment_cardinality' => ['nullable', Rule::enum(AssignmentCardinality::class)],
            'completion_rule' => ['nullable', Rule::enum(CompletionRule::class)],
        ];
    }
}
