<?php

namespace App\Modules\Platform\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImpersonateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_public_id' => ['required', 'uuid'],
        ];
    }
}
