<?php

namespace App\Modules\Platform\Models;

use App\Enums\AuditAction;
use App\Models\CentralModel;
use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id', 'user_id', 'action', 'event_type', 'entity_type', 'entity_type_int',
    'entity_id', 'entity_public_id', 'root_entity_type', 'root_entity_id',
    'root_entity_public_id', 'payload', 'ip_address', 'user_agent',
    'impersonated_by_public_id',
])]
class AuditEvent extends CentralModel
{
    use HasPublicId;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'entity_type_int' => AuditEntityType::class,
            'root_entity_type' => AuditEntityType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
