<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Task\Requests\CompleteStageRequest;
use App\Modules\Task\Requests\OverrideAssignmentRequest;
use App\Modules\Task\Requests\ReturnStageRequest;
use App\Modules\Task\Requests\ReturnSubStageRequest;
use App\Modules\Task\Resources\StageReturnResource;
use App\Modules\Task\Resources\TaskStageInstanceResource;
use App\Modules\Task\Resources\TaskTimelineResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\StageLifecycleService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class StageLifecycleController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private StageLifecycleService $stageLifecycleService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function stages(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $stages = $this->stageLifecycleService->getStageHistory($task);

        return TaskStageInstanceResource::collection($stages);
    }

    public function showStage(Request $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $stage = $this->stageLifecycleService->getStageInstance($task, $stageInstance);

        return new TaskStageInstanceResource($stage);
    }

    public function completeStage(CompleteStageRequest $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $result = $this->stageLifecycleService->completeStage(
            $task, $stageInstance, $request->user(), $request->validated('completion_note'), $request->validated('target_stage_id'),
        );

        return new TaskStageInstanceResource($result);
    }

    public function returnStage(ReturnStageRequest $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $result = $this->stageLifecycleService->returnStage(
            $task, $stageInstance, $request->user(),
            $request->validated('target_stage_id'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result);
    }

    public function completeSubStage(CompleteStageRequest $request, Task $task, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $result = $this->stageLifecycleService->completeSubStage(
            $task, $subStageInstance, $request->user(), $request->validated('completion_note'),
        );

        return new TaskStageInstanceResource($result->parentStageInstance);
    }

    public function returnSubStage(ReturnSubStageRequest $request, Task $task, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $result = $this->stageLifecycleService->returnSubStage(
            $task, $subStageInstance, $request->user(),
            $request->validated('target_sub_stage_id'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result->parentStageInstance);
    }

    public function overrideStageAssignment(OverrideAssignmentRequest $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $result = $this->stageLifecycleService->overrideStageAssignment(
            $task, $stageInstance, $request->user(),
            $request->validated('assignments'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result);
    }

    public function overrideSubStageAssignment(OverrideAssignmentRequest $request, Task $task, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $result = $this->stageLifecycleService->overrideSubStageAssignment(
            $task, $subStageInstance, $request->user(),
            $request->validated('assignments'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result->parentStageInstance);
    }

    public function returns(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $returns = $this->stageLifecycleService->getReturnHistory($task);

        return StageReturnResource::collection($returns);
    }

    public function timeline(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $timeline = $this->stageLifecycleService->getTimeline($task);

        return TaskTimelineResource::collection($timeline);
    }

    private function authorizeTaskVisibility(Request $request, Task $task): void
    {
        $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();
    }
}
