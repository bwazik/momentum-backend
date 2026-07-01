<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ExternalReferenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskExternalReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_type' => ['required', Rule::enum(ExternalReferenceType::class)],
            'reference_number' => ['required', 'string', 'max:100'],
            'external_entity_id' => ['nullable', 'string', 'uuid', 'exists:external_entities,public_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
