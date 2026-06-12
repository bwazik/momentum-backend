<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.current_user_id' => ['required', 'string', 'uuid'],
            'assignments.*.new_user_id' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
