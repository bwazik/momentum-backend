<?php

namespace App\Modules\Iam\Requests;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'account_type' => ['nullable', Rule::enum(AccountType::class)],
            'department_id' => ['nullable', 'string'],
            'public_ids' => ['nullable', 'array'],
            'public_ids.*' => ['string', 'exists:users,public_id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
