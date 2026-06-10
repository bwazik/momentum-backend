<?php

namespace App\Modules\Platform\Models;

use App\Enums\AuditAction;
use App\Models\CentralModel;
use App\Models\User;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_id', 'user_id', 'action', 'entity_type', 'entity_id', 'payload', 'ip_address', 'user_agent'])]
class AuditEvent extends CentralModel
{
    use HasPublicId;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
