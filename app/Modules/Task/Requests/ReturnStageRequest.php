<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_stage_id' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
