<?php

namespace App\Modules\Blueprint\Models;

use App\Models\TenantModel;
use App\Modules\Blueprint\Enums\TransitionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_id', 'blueprint_id', 'from_stage_id', 'to_stage_id', 'transition_type', 'return_reason_required'])]
class BlueprintTransition extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'transition_type' => TransitionType::class,
            'return_reason_required' => 'boolean',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(BlueprintStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(BlueprintStage::class, 'to_stage_id');
    }
}
