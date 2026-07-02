<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Task\Exceptions\ConfidentialAccessDeniedException;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\StoreConfidentialParticipantRequest;
use App\Modules\Task\Resources\ConfidentialParticipantResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\ConfidentialParticipantService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfidentialParticipantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ConfidentialParticipantService $participantService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function index(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->participantService->listForTask($task, $request->integer('per_page', 15))
            ->through(fn ($row) => new ConfidentialParticipantResource($row));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreConfidentialParticipantRequest $request, Task $task): ConfidentialParticipantResource
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $participant = User::where('public_id', $request->validated('user_id'))->firstOrFail();
        $row = $this->participantService->add($task, $participant, $user);

        return new ConfidentialParticipantResource($row);
    }

    public function destroy(Request $request, Task $task, User $user): JsonResponse
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->participantService->remove($task, $user, $request->user());

        return response()->json(null, 204);
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
