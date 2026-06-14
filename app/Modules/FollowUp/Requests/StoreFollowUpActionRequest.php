<?php

namespace App\Modules\FollowUp\Requests;

use App\Modules\FollowUp\Enums\FollowUpActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::enum(FollowUpActionType::class)],
            'note_ar' => ['required', 'string', 'max:5000'],
            'note_en' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
