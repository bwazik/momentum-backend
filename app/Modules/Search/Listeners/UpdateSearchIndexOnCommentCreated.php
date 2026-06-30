<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchIndexService;
use App\Modules\Task\Events\CommentCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateSearchIndexOnCommentCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchIndexService $searchIndexService,
    ) {}

    public function handle(CommentCreated $event): void
    {
        $this->searchIndexService->upsertForTask($event->comment->task);
    }
}
