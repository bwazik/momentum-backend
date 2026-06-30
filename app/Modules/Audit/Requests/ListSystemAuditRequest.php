<?php

namespace App\Modules\Audit\Requests;

use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSystemAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'string', 'uuid'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', Rule::enum(AuditEntityType::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
