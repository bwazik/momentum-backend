<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Exceptions\TaskNotVisibleException;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use App\Modules\Task\Requests\StoreTaskExternalReferenceRequest;
use App\Modules\Task\Requests\UpdateTaskExternalReferenceRequest;
use App\Modules\Task\Resources\TaskExternalReferenceResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\TaskExternalReferenceService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskExternalReferenceController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private TaskExternalReferenceService $referenceService,
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function index(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->referenceService->listForTask($task, $request->integer('per_page', 15))
            ->through(fn (TaskExternalReference $r) => new TaskExternalReferenceResource($r));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreTaskExternalReferenceRequest $request, Task $task): TaskExternalReferenceResource
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->guardCanMutate($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $reference = $this->referenceService->create($task, $request->validated(), $user);

        return new TaskExternalReferenceResource($reference);
    }

    public function update(UpdateTaskExternalReferenceRequest $request, Task $task, TaskExternalReference $reference): TaskExternalReferenceResource
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->guardCanMutate($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $reference = $this->referenceService->update($reference, $request->validated(), $user);

        return new TaskExternalReferenceResource($reference);
    }

    public function destroy(Request $request, Task $task, TaskExternalReference $reference): JsonResponse
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->guardCanMutate($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $this->referenceService->delete($reference, $user);

        return response()->json(null, 204);
    }

    private function guardVisible(Task $task, User $user): void
    {
        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            throw new TaskNotVisibleException;
        }
    }

    private function guardCanMutate(Task $task, User $user): void
    {
        if ($task->initiator_user_id === $user->id) {
            return;
        }

        if ($this->iamPolicy->hasCapability($user, 'task.manage')) {
            return;
        }

        throw new TaskNotVisibleException;
    }
}
