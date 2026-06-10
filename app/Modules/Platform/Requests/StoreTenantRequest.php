<?php

namespace App\Modules\Platform\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug', 'alpha_dash'],
            'domain' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:100', 'timezone'],
            'default_language' => ['nullable', 'integer', 'in:1,2'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
