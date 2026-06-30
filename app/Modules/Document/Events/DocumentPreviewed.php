<?php

namespace App\Modules\Document\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Models\Document;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentPreviewed implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Document $document,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'document.previewed',
            entityType: AuditEntityType::Document,
            entityId: $this->document->id,
            entityPublicId: $this->document->public_id,
            rootEntityType: $this->resolveDocRootType(),
            rootEntityId: $this->resolveDocRootId(),
            user: $this->user,
            payload: ['original_filename' => $this->document->original_filename],
        );
    }

    private function resolveDocRootType(): ?AuditEntityType
    {
        return match ($this->document->entity_type?->value) {
            DocumentEntityType::Task->value,
            DocumentEntityType::StageOutput->value,
            DocumentEntityType::Comment->value => AuditEntityType::Task,
            DocumentEntityType::HelpArticle->value => AuditEntityType::HelpArticle,
            default => null,
        };
    }

    private function resolveDocRootId(): ?int
    {
        if ($this->document->entity_type === DocumentEntityType::Task) {
            return $this->document->entity_id;
        }

        if ($this->document->entity_type === DocumentEntityType::StageOutput) {
            return TaskStageInstance::where('id', $this->document->entity_id)->value('task_id');
        }

        return null;
    }
}
