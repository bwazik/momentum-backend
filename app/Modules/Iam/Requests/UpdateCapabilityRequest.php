<?php

namespace App\Modules\Iam\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'key' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
