<?php

namespace App\Modules\Blueprint\Requests;

use App\Modules\Blueprint\Enums\TransitionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBlueprintTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_stage_id' => ['nullable', 'exists:blueprint_stages,public_id'],
            'to_stage_id' => ['nullable', 'exists:blueprint_stages,public_id'],
            'transition_type' => ['nullable', Rule::enum(TransitionType::class)],
            'return_reason_required' => ['nullable', 'boolean'],
        ];
    }
}
