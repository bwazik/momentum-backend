<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchIndexService;
use App\Modules\Task\Events\StageAssignmentCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateSearchIndexOnStageAssignmentCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchIndexService $searchIndexService,
    ) {}

    public function handle(StageAssignmentCompleted $event): void
    {
        $this->searchIndexService->upsertForTask($event->assignment->task);
    }
}
