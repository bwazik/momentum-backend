<?php

namespace App\Modules\FollowUp\Requests;

use App\Modules\FollowUp\Enums\BoardSortDirection;
use App\Modules\FollowUp\Enums\BoardSortField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,suspended,overdue,at_risk,completed,cancelled'],
            'stage_type_id' => ['nullable', 'string', 'uuid'],
            'assignee_id' => ['nullable', 'string', 'uuid'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'priority_id' => ['nullable', 'array'],
            'priority_id.*' => ['string', 'uuid'],
            'blueprint_category_id' => ['nullable', 'string', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_field' => ['nullable', 'string', 'in:created_at,due_date,completed_at'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', Rule::enum(BoardSortField::class)],
            'sort_direction' => ['nullable', Rule::enum(BoardSortDirection::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
