<?php

namespace App\Modules\Task\Enums;

enum ConfidentialAccessEventType: int
{
    case MetadataView = 1;
    case ContentOverride = 2;
    case ParticipantAdded = 3;
    case ParticipantRemoved = 4;

    public function auditEventType(): string
    {
        return match ($this) {
            self::MetadataView => 'confidential.metadata_viewed',
            self::ContentOverride => 'confidential.content_overridden',
            self::ParticipantAdded => 'confidential.participant_added',
            self::ParticipantRemoved => 'confidential.participant_removed',
        };
    }
}
