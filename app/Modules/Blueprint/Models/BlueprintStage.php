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
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['blueprint_id', 'stage_type_id', 'sla_policy_id', 'name_en', 'name_ar', 'description_en', 'description_ar', 'sequence_order', 'assignment_type', 'assigned_position_id', 'assigned_department_id', 'assignment_cardinality', 'completion_rule', 'escalation_position_id'])]
class BlueprintStage extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sequence_order' => 'integer',
            'assignment_type' => AssignmentType::class,
            'assignment_cardinality' => AssignmentCardinality::class,
            'completion_rule' => CompletionRule::class,
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    public function stageType(): BelongsTo
    {
        return $this->belongsTo(StageType::class, 'stage_type_id');
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

    public function escalationPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'escalation_position_id');
    }

    public function subStages(): HasMany
    {
        return $this->hasMany(BlueprintSubStage::class, 'blueprint_stage_id')->orderBy('sequence_order');
    }

    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(BlueprintTransition::class, 'from_stage_id');
    }

    public function transitionsTo(): HasMany
    {
        return $this->hasMany(BlueprintTransition::class, 'to_stage_id');
    }
}
