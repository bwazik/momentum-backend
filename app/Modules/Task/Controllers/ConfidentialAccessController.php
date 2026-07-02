<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Task\Exceptions\ConfidentialAccessDeniedException;
use App\Modules\Task\Models\ConfidentialAccessEvent;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\AccessOverrideRequest;
use App\Modules\Task\Resources\ConfidentialAccessEventResource;
use App\Modules\Task\Resources\TaskDetailResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\ConfidentialAccessService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class ConfidentialAccessController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ConfidentialAccessService $accessService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function metadata(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $metadata = $this->accessService->metadata($task, $request->user());

        return response()->json($metadata);
    }

    public function override(AccessOverrideRequest $request, Task $task): TaskDetailResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $task = $this->accessService->override($task, $request->validated('reason'), $request->user());

        return new TaskDetailResource($task);
    }

    public function events(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = ConfidentialAccessEvent::where('task_id', $task->id)
            ->with('user')
            ->orderBy('id')
            ->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($event) => new ConfidentialAccessEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    private function guardVisible(Task $task, User $user): void
    {
        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            throw new ConfidentialAccessDeniedException;
        }
    }
}
