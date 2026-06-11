<?php

namespace App\Modules\Blueprint\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.public_id' => ['required', 'exists:blueprint_stages,public_id'],
            'stages.*.sequence_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
