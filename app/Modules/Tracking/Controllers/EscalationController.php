<?php

namespace App\Modules\Tracking\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Tracking\Models\Escalation;
use App\Modules\Tracking\Requests\CreateManualEscalationRequest;
use App\Modules\Tracking\Requests\ListEscalationsRequest;
use App\Modules\Tracking\Requests\ResolveEscalationRequest;
use App\Modules\Tracking\Resources\EscalationDetailResource;
use App\Modules\Tracking\Resources\EscalationResource;
use App\Modules\Tracking\Services\SlaEscalationService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class EscalationController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private SlaEscalationService $escalationService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function index(ListEscalationsRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $query = Escalation::query()
            ->with(['task', 'stageInstance', 'subStageInstance', 'escalatedToUser', 'escalatedByUser'])
            ->whereHas('task', fn ($q) => $this->taskVisibilityScope->apply($q, $request->user()))
            ->orderBy('id');

        $filters = $request->validated();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('escalation_type', $filters['type']);
        }

        if (! empty($filters['assigned_to_me']) && filter_var($filters['assigned_to_me'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('escalated_to_user_id', $request->user()->id);
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

        if (! empty($filters['department_id'])) {
            $department = Department::where('public_id', $filters['department_id'])->first();
            if ($department) {
                $query->whereHas('task', fn ($q) => $q->whereHas('stageInstances', fn ($q) => $q->where('owning_department_id', $department->id)));
            }
        }

        if (! empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        $paginator = $query->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($escalation) => new EscalationResource($escalation));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function show(Request $request, Escalation $escalation)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $escalation->task_id);

        $escalation->load([
            'task', 'stageInstance', 'subStageInstance',
            'slaTimerInstance.slaPolicy', 'escalatedToUser',
            'escalatedToPosition', 'escalatedByUser',
        ]);

        return new EscalationDetailResource($escalation);
    }

    public function store(CreateManualEscalationRequest $request)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $escalation = $this->escalationService->createManualEscalation(
            $request->validated(),
            $request->user()
        );

        return response()->json(new EscalationDetailResource($escalation->load([
            'task', 'stageInstance', 'subStageInstance',
            'escalatedToUser', 'escalatedToPosition',
        ])), 201);
    }

    public function resolve(ResolveEscalationRequest $request, Escalation $escalation)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $escalation->task_id);

        $escalation = $this->escalationService->resolveEscalation(
            $escalation,
            $request->user(),
            $request->validated('resolution_note')
        );

        return new EscalationDetailResource($escalation);
    }

    private function authorizeTaskVisibility(Request $request, int $taskId): void
    {
        $this->taskVisibilityScope->apply(
            Task::query()->where('id', $taskId),
            $request->user()
        )->firstOrFail();
    }
}
