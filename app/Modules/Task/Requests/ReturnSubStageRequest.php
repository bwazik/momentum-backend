<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnSubStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_sub_stage_id' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
