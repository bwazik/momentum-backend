<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'holiday_date' => [
                'required',
                'date',
                Rule::unique('public_holidays')->where(function ($query) {
                    return $query->where('working_calendar_id', $this->route('workingCalendar')->id);
                }),
            ],
            'is_recurring' => ['boolean'],
        ];
    }
}
