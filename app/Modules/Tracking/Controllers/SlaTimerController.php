<?php

namespace App\Modules\Tracking\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Tracking\Models\SlaTimerInstance;
use App\Modules\Tracking\Requests\ListSlaTimersRequest;
use App\Modules\Tracking\Resources\SlaTimerInstanceResource;
use App\Modules\Tracking\Resources\TaskSlaHealthResource;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class SlaTimerController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function taskHealth(ListSlaTimersRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $timers = SlaTimerInstance::where('task_id', $task->id)
            ->with(['slaPolicy', 'stageInstance', 'subStageInstance'])
            ->get();

        return new TaskSlaHealthResource($task, $timers);
    }

    public function index(ListSlaTimersRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $query = SlaTimerInstance::query()
            ->with(['task', 'slaPolicy', 'stageInstance', 'subStageInstance'])
            ->whereHas('task', fn ($q) => $this->taskVisibilityScope->apply($q, $request->user()))
            ->orderBy('id');

        $filters = $request->validated();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['task_id'])) {
            $task = Task::where('public_id', $filters['task_id'])->first();
            if ($task) {
                $query->where('task_id', $task->id);
            }
        }

        if (! empty($filters['blueprint_id'])) {
            $query->whereHas('task', fn ($q) => $q->whereHas('blueprint', fn ($q) => $q->where('public_id', $filters['blueprint_id'])));
        }

        if (! empty($filters['stage_id'])) {
            $blueprintStage = BlueprintStage::where('public_id', $filters['stage_id'])->first();
            if ($blueprintStage) {
                $query->whereHas('stageInstance', fn ($q) => $q->where('blueprint_stage_id', $blueprintStage->id));
            }
        }

        if (! empty($filters['assigned_user_id'])) {
            $query->whereHas('stageInstance.assignments', fn ($q) => $q->whereHas('user', fn ($q) => $q->where('public_id', $filters['assigned_user_id'])));
        }

        if (! empty($filters['department_id'])) {
            $department = Department::where('public_id', $filters['department_id'])->first();
            if ($department) {
                $query->where(function ($q) use ($department) {
                    $q->whereHas('stageInstance', fn ($q) => $q->where('owning_department_id', $department->id))
                        ->orWhereHas('subStageInstance', fn ($q) => $q->where('owning_department_id', $department->id));
                });
            }
        }

        if (! empty($filters['deadline_from'])) {
            $query->where('deadline_at', '>=', $filters['deadline_from']);
        }

        if (! empty($filters['deadline_to'])) {
            $query->where('deadline_at', '<=', $filters['deadline_to']);
        }

        $paginator = $query->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($timer) => new SlaTimerInstanceResource($timer));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    private function authorizeTaskVisibility(Request $request, Task $task): void
    {
        $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();
    }
}
