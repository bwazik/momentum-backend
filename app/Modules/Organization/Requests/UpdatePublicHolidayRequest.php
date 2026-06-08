<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePublicHolidayRequest extends FormRequest
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
            'holiday_date' => [
                'sometimes',
                'required',
                'date',
                Rule::unique('public_holidays')->where(function ($query) {
                    return $query->where('working_calendar_id', $this->route('workingCalendar')->id);
                })->ignore($this->route('publicHoliday')->id),
            ],
            'is_recurring' => ['boolean'],
        ];
    }
}
