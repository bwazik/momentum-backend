<?php

namespace App\Modules\Iam\Requests;

use App\Enums\AccountType;
use App\Enums\PreferredLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'string', 'max:50', 'unique:users,employee_id'],
            'account_type' => ['required', Rule::enum(AccountType::class)->except([AccountType::PLATFORM_ADMIN])],
            'preferred_language' => ['nullable', Rule::enum(PreferredLanguage::class)],
        ];
    }
}
