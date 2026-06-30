<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\Comment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CommentCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Comment $comment,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'comment.created',
            entityType: AuditEntityType::Comment,
            entityId: $this->comment->id,
            entityPublicId: $this->comment->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->comment->task_id,
            rootEntityPublicId: $this->comment->task?->public_id,
            user: $this->user,
            payload: [
                'body' => mb_strimwidth($this->comment->body, 0, 1000, '...'),
            ],
        );
    }
}
