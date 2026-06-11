<?php

namespace App\Modules\Blueprint\Requests;

use App\Modules\Blueprint\Enums\SlaUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSlaPolicyRequest extends FormRequest
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
            'sla_value' => ['required', 'integer', 'min:1', 'max:32767'],
            'sla_unit' => ['required', Rule::enum(SlaUnit::class)],
            'warning_threshold_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
