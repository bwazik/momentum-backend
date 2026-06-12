<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LaunchTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'manual_assignments' => ['nullable', 'array'],
            'manual_assignments.*.blueprint_stage_id' => ['string'],
            'manual_assignments.*.blueprint_sub_stage_id' => ['string'],
            'manual_assignments.*.user_ids' => ['required', 'array', 'min:1'],
            'manual_assignments.*.user_ids.*' => ['string', 'exists:users,public_id'],
        ];
    }
}
