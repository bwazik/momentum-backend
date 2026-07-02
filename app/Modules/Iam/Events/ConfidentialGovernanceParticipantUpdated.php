<?php

namespace App\Modules\Iam\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ConfidentialGovernanceParticipantUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public ConfidentialGovernanceParticipant $config,
        public User $updatedBy,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'confidential.governance_participant_updated',
            entityType: AuditEntityType::Position,
            entityId: $this->config->position_id,
            entityPublicId: $this->config->public_id,
            user: $this->updatedBy,
            payload: [
                'scope_type' => $this->config->scope_type->value,
                'blueprint_category_id' => $this->config->blueprint_category_id,
            ],
        );
    }
}
