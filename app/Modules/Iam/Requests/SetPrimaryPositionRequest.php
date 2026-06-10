<?php

namespace App\Modules\Iam\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPrimaryPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
