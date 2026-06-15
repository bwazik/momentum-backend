<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\StageAssignmentCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnStageAssignmentCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(StageAssignmentCompleted $event): void
    {
        $assignment = $event->assignment;
        $this->activityService->recordStageCompleted($assignment->user, $assignment->task);
    }
}
