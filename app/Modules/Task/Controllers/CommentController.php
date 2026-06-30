<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\StoreCommentRequest;
use App\Modules\Task\Resources\CommentResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\CommentService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private CommentService $commentService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function index(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->commentService->listForTask(
            $task,
            $request->integer('per_page', 15)
        )->through(fn (Comment $comment) => new CommentResource($comment));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreCommentRequest $request, Task $task): CommentResource
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $comment = $this->commentService->create($task, $request->validated(), $request->user());

        return new CommentResource($comment->loadCount('documents'));
    }

    private function guardVisible(Task $task, User $user): void
    {
        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            abort(403, 'You do not have access to this task.');
        }
    }
}
