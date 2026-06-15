<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\TaskViewed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnTaskViewed implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(TaskViewed $event): void
    {
        $this->activityService->recordView($event->user, $event->task);
    }
}
