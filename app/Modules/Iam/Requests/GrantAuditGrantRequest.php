<?php

namespace App\Modules\Iam\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GrantAuditGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_auditor_user_id' => ['required', 'exists:users,public_id'],
            'date_range_start' => ['required', 'date', 'before_or_equal:date_range_end'],
            'date_range_end' => ['required', 'date', 'after_or_equal:date_range_start'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
        ];
    }
}
