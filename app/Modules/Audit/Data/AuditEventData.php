<?php

namespace App\Modules\Audit\Data;

use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;

class AuditEventData
{
    public function __construct(
        public string $eventType,
        public AuditEntityType $entityType,
        public int $entityId,
        public ?string $entityPublicId = null,
        public ?AuditEntityType $rootEntityType = null,
        public ?int $rootEntityId = null,
        public ?string $rootEntityPublicId = null,
        public ?User $user = null,
        public ?array $payload = null,
    ) {}
}
