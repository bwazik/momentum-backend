<?php

namespace App\Modules\Blueprint\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderSubStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sub_stages' => ['required', 'array', 'min:1'],
            'sub_stages.*.public_id' => ['required', 'string', 'exists:blueprint_sub_stages,public_id'],
            'sub_stages.*.sequence_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
