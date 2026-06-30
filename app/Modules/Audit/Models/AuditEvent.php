<?php

namespace App\Modules\Audit\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'event_type',
    'entity_type',
    'entity_id',
    'entity_public_id',
    'root_entity_type',
    'root_entity_id',
    'root_entity_public_id',
    'user_id',
    'ip_address',
    'user_agent',
    'payload',
    'impersonated_by_public_id',
])]
class AuditEvent extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entity_type' => AuditEntityType::class,
            'root_entity_type' => AuditEntityType::class,
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeForRootEntity($query, AuditEntityType $type, int $id)
    {
        return $query->where('root_entity_type', $type)->where('root_entity_id', $id);
    }

    public function scopeForEntity($query, AuditEntityType $type, int $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
