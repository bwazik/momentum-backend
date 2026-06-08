<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkingCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'working_days' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[0-6](,[0-6])*$/'],
            'working_hours_start' => ['sometimes', 'required', 'date_format:H:i'],
            'working_hours_end' => ['sometimes', 'required', 'date_format:H:i', 'after:working_hours_start'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:100', 'timezone'],
            'is_default' => ['boolean'],
        ];
    }
}
