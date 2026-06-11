<?php

namespace App\Modules\Blueprint\Models;

use App\Models\TenantModel;
use App\Modules\Blueprint\Enums\AssignmentCardinality;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['blueprint_stage_id', 'sla_policy_id', 'name_en', 'name_ar', 'description_en', 'description_ar', 'sequence_order', 'is_required', 'assignment_type', 'assigned_position_id', 'assigned_department_id', 'assignment_cardinality', 'completion_rule'])]
class BlueprintSubStage extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sequence_order' => 'integer',
            'is_required' => 'boolean',
            'assignment_type' => AssignmentType::class,
            'assignment_cardinality' => AssignmentCardinality::class,
            'completion_rule' => CompletionRule::class,
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(BlueprintStage::class, 'blueprint_stage_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function assignedPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'assigned_position_id');
    }

    public function assignedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assigned_department_id');
    }
}
