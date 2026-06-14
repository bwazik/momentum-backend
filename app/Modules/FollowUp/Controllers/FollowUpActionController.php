<?php

namespace App\Modules\FollowUp\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FollowUp\Requests\ListFollowUpActionsRequest;
use App\Modules\FollowUp\Requests\StoreFollowUpActionRequest;
use App\Modules\FollowUp\Resources\FollowUpActionResource;
use App\Modules\FollowUp\Services\FollowUpActionService;
use App\Modules\Task\Models\Task;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class FollowUpActionController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private FollowUpActionService $actionService,
    ) {}

    public function store(StoreFollowUpActionRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $action = $this->actionService->create($task, $request->user(), $request->validated());

        return response()->json(new FollowUpActionResource($action), 201);
    }

    public function index(ListFollowUpActionsRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->actionService->list($task, $request->user(), $request->validated());
        $items = $paginator->through(fn ($action) => new FollowUpActionResource($action))->items();

        return response()->json([
            'data' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
