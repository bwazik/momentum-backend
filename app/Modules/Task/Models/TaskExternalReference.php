<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use App\Modules\Task\Enums\ExternalReferenceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'reference_type', 'reference_number', 'external_entity_id', 'notes'])]
class TaskExternalReference extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'reference_type' => ExternalReferenceType::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function externalEntity(): BelongsTo
    {
        return $this->belongsTo(ExternalEntity::class);
    }
}
