<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\CancelTaskRequest;
use App\Modules\Task\Requests\LaunchTaskRequest;
use App\Modules\Task\Requests\ListTaskRequest;
use App\Modules\Task\Requests\ResumeTaskRequest;
use App\Modules\Task\Requests\StoreTaskRequest;
use App\Modules\Task\Requests\SuspendTaskRequest;
use App\Modules\Task\Requests\UpdateTaskRequest;
use App\Modules\Task\Resources\TaskDetailResource;
use App\Modules\Task\Resources\TaskResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\TaskService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private TaskService $taskService,
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function index(ListTaskRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $tasks = $this->taskService->list($request);

        return TaskResource::collection($tasks);
    }

    public function show(Request $request, Task $task): TaskDetailResource
    {
        $visibleTask = $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();

        $visibleTask->load([
            'priority', 'blueprint.category', 'initiator',
            'stageInstances.assignments.user',
            'stageInstances.subStageInstances',
        ]);

        return new TaskDetailResource($visibleTask);
    }

    public function store(StoreTaskRequest $request): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->create($request->validated(), $request->user());

        return new TaskResource($task);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->update($task, $request->validated(), $request->user());

        return new TaskResource($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->taskService->delete($task, $request->user());

        return response()->json(null, 204);
    }

    public function launch(LaunchTaskRequest $request, Task $task): TaskDetailResource
    {
        $user = $request->user();
        if ($task->initiator_user_id !== $user->id && ! $this->iamPolicy->hasCapability($user, 'task.manage')) {
            abort(403, 'Only the task initiator or a user with task.manage can launch this task.');
        }

        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);
        $task = $this->taskService->launch($task, $request->validated('manual_assignments', []));

        return new TaskDetailResource($task);
    }

    public function suspend(SuspendTaskRequest $request, Task $task): TaskResource
    {
        $visibleTask = $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();

        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->suspend($visibleTask, $request->validated('reason'));

        return new TaskResource($task);
    }

    public function resume(ResumeTaskRequest $request, Task $task): TaskResource
    {
        $visibleTask = $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();

        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->resume($visibleTask);

        return new TaskResource($task);
    }

    public function cancel(CancelTaskRequest $request, Task $task): TaskResource
    {
        $visibleTask = $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();

        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->cancel($visibleTask, $request->validated('reason'));

        return new TaskResource($task);
    }
}
