<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\StageInstanceReturned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnStageInstanceReturned implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(StageInstanceReturned $event): void
    {
        $this->activityService->recordStageReturned(
            $event->returnedByUser,
            $event->returnedStageInstance->task
        );
    }
}
