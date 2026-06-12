<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Task\Requests\StoreTaskPriorityRequest;
use App\Modules\Task\Requests\UpdateTaskPriorityRequest;
use App\Modules\Task\Resources\TaskPriorityResource;
use App\Modules\Task\Services\TaskPriorityService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class TaskPriorityController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private TaskPriorityService $taskPriorityService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return TaskPriorityResource::collection($this->taskPriorityService->getAll());
    }

    public function store(StoreTaskPriorityRequest $request): TaskPriorityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $priority = $this->taskPriorityService->create($request->validated());

        return new TaskPriorityResource($priority);
    }

    public function update(UpdateTaskPriorityRequest $request, TaskPriority $priority): TaskPriorityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $priority = $this->taskPriorityService->update($priority, $request->validated());

        return new TaskPriorityResource($priority);
    }

    public function deactivate(Request $request, TaskPriority $priority): TaskPriorityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $priority = $this->taskPriorityService->deactivate($priority);

        return new TaskPriorityResource($priority);
    }

    public function reactivate(Request $request, TaskPriority $priority): TaskPriorityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $priority = $this->taskPriorityService->reactivate($priority);

        return new TaskPriorityResource($priority);
    }
}
