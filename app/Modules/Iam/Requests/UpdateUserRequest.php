<?php

namespace App\Modules\Iam\Requests;

use App\Enums\AccountType;
use App\Enums\PreferredLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'mobile' => ['nullable', 'string', 'max:30'],
            'preferred_language' => ['nullable', Rule::enum(PreferredLanguage::class)],
            'account_type' => ['sometimes', 'required', Rule::enum(AccountType::class)->except([AccountType::PLATFORM_ADMIN])],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ];
    }
}
