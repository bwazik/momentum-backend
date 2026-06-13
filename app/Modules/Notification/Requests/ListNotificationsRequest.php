<?php

namespace App\Modules\Notification\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'read' => ['sometimes', Rule::in(['unread', 'read', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
