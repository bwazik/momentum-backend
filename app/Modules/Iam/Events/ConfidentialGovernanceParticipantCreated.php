<?php

namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ConfidentialGovernanceParticipantCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public ConfidentialGovernanceParticipant $config,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'confidential.governance_participant_created',
            entityType: AuditEntityType::Position,
            entityId: $this->config->position_id,
            entityPublicId: $this->config->public_id,
            user: $this->config->createdBy,
            payload: [
                'position_id' => $this->config->public_id,
                'scope_type' => $this->config->scope_type->value,
            ],
        );
    }
}
