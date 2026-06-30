<?php

namespace App\Modules\Document\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Models\Document;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentVersionCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Document $version,
        public Document $previousVersion,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'document.version_created',
            entityType: AuditEntityType::Document,
            entityId: $this->version->id,
            entityPublicId: $this->version->public_id,
            rootEntityType: $this->resolveDocRootType(),
            rootEntityId: $this->resolveDocRootId(),
            user: $this->version->uploader,
            payload: [
                'original_filename' => $this->version->original_filename,
                'version_number' => $this->version->version_number,
            ],
        );
    }

    private function resolveDocRootType(): ?AuditEntityType
    {
        return match ($this->version->entity_type?->value) {
            DocumentEntityType::Task->value,
            DocumentEntityType::StageOutput->value,
            DocumentEntityType::Comment->value => AuditEntityType::Task,
            DocumentEntityType::HelpArticle->value => AuditEntityType::HelpArticle,
            default => null,
        };
    }

    private function resolveDocRootId(): ?int
    {
        if ($this->version->entity_type === DocumentEntityType::Task) {
            return $this->version->entity_id;
        }

        if ($this->version->entity_type === DocumentEntityType::StageOutput) {
            return TaskStageInstance::where('id', $this->version->entity_id)->value('task_id');
        }

        return null;
    }
}
