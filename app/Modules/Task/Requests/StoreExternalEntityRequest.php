<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ExternalEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExternalEntityRequest extends FormRequest
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
            'entity_type' => ['required', Rule::enum(ExternalEntityType::class)],
        ];
    }
}
