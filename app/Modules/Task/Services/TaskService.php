<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Blueprint\Events\BlueprintLocked;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Events\StageInstanceCreated;
use App\Modules\Task\Events\SubStageInstanceCreated;
use App\Modules\Task\Events\TaskCancelled;
use App\Modules\Task\Events\TaskCreated;
use App\Modules\Task\Events\TaskLaunched;
use App\Modules\Task\Events\TaskResumed;
use App\Modules\Task\Events\TaskSuspended;
use App\Modules\Task\Events\TaskUpdated;
use App\Modules\Task\Events\TaskViewed;
use App\Modules\Task\Exceptions\BlueprintHasNoStagesException;
use App\Modules\Task\Exceptions\BlueprintNotActiveException;
use App\Modules\Task\Exceptions\InvalidTaskStateTransitionException;
use App\Modules\Task\Exceptions\MissingManualAssignmentException;
use App\Modules\Task\Exceptions\TaskNotActiveException;
use App\Modules\Task\Exceptions\TaskNotDraftException;
use App\Modules\Task\Exceptions\TaskNotSuspendedException;
use App\Modules\Task\Exceptions\UnresolvableAssignmentException;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Traits\AuthenticatedUser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskService
{
    use AuthenticatedUser;

    public function __construct(
        private AssignmentResolutionService $assignmentResolutionService,
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function create(array $data, User $user): Task
    {
        try {
            $blueprintId = Blueprint::where('public_id', $data['blueprint_id'])->value('id');

            if (! $blueprintId) {
                abort(404, 'Blueprint not found.');
            }

            $blueprint = Blueprint::findOrFail($blueprintId);

            if (! $blueprint->is_active) {
                throw new BlueprintNotActiveException;
            }

            $priorityId = null;
            if (! empty($data['priority_id'])) {
                $priorityId = TaskPriority::where('public_id', $data['priority_id'])->value('id');
            }

            if (! $priorityId) {
                $priorityId = TaskPriority::where('is_default', true)->value('id');
            }

            $classificationLevel = $data['classification_level'] ?? ClassificationLevel::Public->value;

            if ((int) $classificationLevel === ClassificationLevel::Confidential->value) {
                if (! $this->iamPolicy->hasCapability($user, 'task.classify.confidential')) {
                    abort(403, 'Missing task.classify.confidential capability.');
                }
            }

            $task = Task::create([
                'blueprint_id' => $blueprintId,
                'priority_id' => $priorityId,
                'title_ar' => $data['title_ar'],
                'title_en' => ! empty($data['title_en']) ? $data['title_en'] : $data['title_ar'],
                'description_ar' => $data['description_ar'],
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : $data['description_ar'],
                'classification_level' => $classificationLevel,
                'initiator_user_id' => $user->id,
                'status' => TaskStatus::Draft,
                'due_date' => $data['due_date'] ?? null,
                'draft_manual_assignments' => $data['manual_assignments'] ?? null,
            ]);

            event(new TaskCreated($task));

            return $task->fresh(['priority', 'blueprint', 'initiator']);
        } catch (BlueprintNotActiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.create',
                'entity_type' => 'task',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Task $task, array $data, User $user): Task
    {
        try {
            if (! $task->isDraft()) {
                throw new TaskNotDraftException;
            }

            if ($task->initiator_user_id !== $user->id) {
                $hasCapability = $this->iamPolicy
                    ->hasCapability($user, 'task.manage');

                if (! $hasCapability) {
                    abort(403, 'You are not the task initiator and do not have the task.manage capability.');
                }
            }

            $newLevel = $data['classification_level'] ?? null;
            if ($newLevel !== null) {
                $resolvedLevel = is_numeric($newLevel) ? (int) $newLevel : ($newLevel instanceof ClassificationLevel ? $newLevel->value : ClassificationLevel::tryFrom($newLevel)?->value);
                if (
                    $resolvedLevel === ClassificationLevel::Confidential->value
                    || $task->classification_level?->value === ClassificationLevel::Confidential->value
                ) {
                    if (! $this->iamPolicy->hasCapability($user, 'task.classify.confidential')) {
                        abort(403, 'Missing task.classify.confidential capability.');
                    }
                }
            }

            $updateData = [
                'title_ar' => $data['title_ar'] ?? $task->title_ar,
                'title_en' => ! empty($data['title_en']) ? $data['title_en'] : ($data['title_ar'] ?? $task->title_en),
                'description_ar' => $data['description_ar'] ?? $task->description_ar,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? $task->description_en),
                'classification_level' => $data['classification_level'] ?? $task->classification_level,
                'due_date' => $data['due_date'] ?? $task->due_date,
            ];

            if (array_key_exists('manual_assignments', $data)) {
                $updateData['draft_manual_assignments'] = $data['manual_assignments'] ?? null;
            }

            $task->update($updateData);

            event(new TaskUpdated($task));

            return $task->fresh(['priority', 'blueprint', 'initiator']);
        } catch (TaskNotDraftException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to update task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.update',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(Task $task, User $user): void
    {
        try {
            if (! $task->isDraft()) {
                throw new TaskNotDraftException;
            }

            if ($task->initiator_user_id !== $user->id) {
                abort(403, 'Only the task initiator can delete a draft task.');
            }

            $task->delete();
        } catch (TaskNotDraftException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to delete task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.delete',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function findVisible(Task $task, User $user): Task
    {
        try {
            $visibleTask = $this->taskVisibilityScope->apply(
                Task::query()->where('id', $task->id),
                $user
            )->firstOrFail();

            $visibleTask->load([
                'priority', 'blueprint.category', 'blueprint.stages.slaPolicy', 'blueprint.stages.stageType', 'blueprint.transitions', 'initiator',
                'stageInstances.blueprintStage.stageType',
                'stageInstances.assignments.user',
                'stageInstances.subStageInstances.blueprintSubStage.slaPolicy',
                'stageInstances.subStageInstances.assignments.user',
                'stageInstances.owningDepartment',
            ]);

            event(new TaskViewed($visibleTask, $user));

            return $visibleTask;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to load visible task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.find_visible',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(Request $request): CursorPaginator
    {
        $query = Task::query()->with(['priority', 'blueprint', 'initiator']);

        if ($request->filled('status')) {
            $query->where('status', $request->integer('status'));
        }
        if ($request->filled('priority_id')) {
            $priorityId = TaskPriority::where('public_id', $request->input('priority_id'))->value('id');
            $query->where('priority_id', $priorityId);
        }
        if ($request->filled('blueprint_id')) {
            $blueprintId = Blueprint::where('public_id', $request->input('blueprint_id'))->value('id');
            $query->where('blueprint_id', $blueprintId);
        }
        if ($request->filled('classification_level')) {
            $query->where('classification_level', $request->integer('classification_level'));
        }
        if ($request->filled('initiator_user_id')) {
            $uid = User::where('public_id', $request->input('initiator_user_id'))->value('id');
            if ($uid) {
                $query->where('initiator_user_id', $uid);
            }
        }
        if ($request->filled('created_from')) {
            $query->where('created_at', '>=', $request->input('created_from'));
        }
        if ($request->filled('created_to')) {
            $query->where('created_at', '<=', $request->input('created_to'));
        }
        if ($request->filled('due_from')) {
            $query->where('due_date', '>=', $request->input('due_from'));
        }
        if ($request->filled('due_to')) {
            $query->where('due_date', '<=', $request->input('due_to'));
        }
        if ($request->filled('search')) {
            $searchTerm = str_replace(['%', '_'], ['\\%', '\\_'], $request->input('search'));
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title_ar', 'like', '%'.$searchTerm.'%')
                    ->orWhere('title_en', 'like', '%'.$searchTerm.'%');
            });
        }

        $this->taskVisibilityScope->apply($query, $request->user());

        return $query->orderBy('id')->cursorPaginate($request->integer('per_page', 15));
    }

    public function launch(Task $task, array $manualAssignments = []): Task
    {
        try {
            if (empty($manualAssignments) && $task->draft_manual_assignments) {
                $manualAssignments = $task->draft_manual_assignments;
            }

            return DB::transaction(function () use ($task, $manualAssignments) {
                if (! $task->isDraft()) {
                    throw new TaskNotDraftException;
                }

                $blueprint = $task->blueprint;

                if (! $blueprint->is_active) {
                    throw new BlueprintNotActiveException;
                }

                $stages = $blueprint->stages()->with('subStages')->orderBy('sequence_order')->get();

                if ($stages->isEmpty()) {
                    throw new BlueprintHasNoStagesException;
                }

                if (! $blueprint->is_locked) {
                    $blueprint->update(['is_locked' => true]);
                    event(new BlueprintLocked($blueprint));
                }

                $firstStage = $stages->first();
                $stageInstance = TaskStageInstance::create([
                    'task_id' => $task->id,
                    'blueprint_stage_id' => $firstStage->id,
                    'sequence_order' => $firstStage->sequence_order,
                    'completion_rule' => $firstStage->completion_rule->value,
                    'status' => StageInstanceStatus::Active->value,
                    'entered_at' => now(),
                ]);

                event(new StageInstanceCreated($stageInstance));

                foreach ($firstStage->subStages as $index => $subStage) {
                    $subInstance = TaskSubStageInstance::create([
                        'task_id' => $task->id,
                        'parent_stage_instance_id' => $stageInstance->id,
                        'blueprint_sub_stage_id' => $subStage->id,
                        'sequence_order' => $subStage->sequence_order,
                        'is_required' => $subStage->is_required,
                        'completion_rule' => $subStage->completion_rule->value,
                        'status' => $index === 0
                            ? SubStageInstanceStatus::Active->value
                            : SubStageInstanceStatus::Pending->value,
                        'entered_at' => $index === 0 ? now() : null,
                    ]);
                    event(new SubStageInstanceCreated($subInstance));

                    if ($subStage->assignment_type !== null) {
                        $this->assignmentResolutionService->resolveSubStageAssignments(
                            $subStage,
                            $task,
                            $subInstance,
                            $manualAssignments,
                        );
                    }
                }

                $assignments = $this->assignmentResolutionService->resolveStageAssignments(
                    $firstStage,
                    $task,
                    $stageInstance,
                    $manualAssignments,
                );

                if ($assignments->isNotEmpty()) {
                    $firstAssignment = $assignments->first();
                    $departmentId = $firstAssignment->position_id
                        ? Position::find($firstAssignment->position_id)?->department_id
                        : null;
                    $stageInstance->update(['owning_department_id' => $departmentId]);
                }

                $task->update([
                    'status' => TaskStatus::Active,
                    'launched_at' => now(),
                    'draft_manual_assignments' => null,
                ]);

                event(new TaskLaunched($task));

                return $task->fresh(['stageInstances.assignments', 'stageInstances.owningDepartment', 'priority', 'blueprint']);
            });
        } catch (TaskNotDraftException|BlueprintNotActiveException|BlueprintHasNoStagesException|
            UnresolvableAssignmentException|
            MissingManualAssignmentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::channel('task')->error('Failed to launch task', [
                    'tenant_slug' => tenant()?->slug ?? 'central',
                    'action' => 'task.launch',
                    'entity_type' => 'task',
                    'entity_id' => $task->public_id,
                    'performed_by' => $this->user()?->public_id ?? 'system',
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
    }

    public function suspend(Task $task, string $reason): Task
    {
        try {
            return DB::transaction(function () use ($task, $reason) {
                if (! $task->status->canTransitionTo(TaskStatus::Suspended)) {
                    throw new TaskNotActiveException;
                }

                $task->update([
                    'status' => TaskStatus::Suspended,
                    'suspended_at' => now(),
                    'suspension_reason' => $reason,
                ]);

                event(new TaskSuspended($task, $reason));

                return $task->fresh();
            });
        } catch (TaskNotActiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to suspend task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.suspend',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function resume(Task $task): Task
    {
        try {
            return DB::transaction(function () use ($task) {
                if (! $task->status->canTransitionTo(TaskStatus::Active)) {
                    throw new TaskNotSuspendedException;
                }

                $task->update([
                    'status' => TaskStatus::Active,
                    'resumed_at' => now(),
                ]);

                event(new TaskResumed($task));

                return $task->fresh();
            });
        } catch (TaskNotSuspendedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to resume task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.resume',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function cancel(Task $task, string $reason): Task
    {
        try {
            return DB::transaction(function () use ($task, $reason) {
                if (! $task->status->canTransitionTo(TaskStatus::Cancelled)) {
                    throw new InvalidTaskStateTransitionException('Cannot cancel task in '.$task->status->name.' status.');
                }

                $task->update([
                    'status' => TaskStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]);

                event(new TaskCancelled($task, $reason));

                return $task->fresh();
            });
        } catch (InvalidTaskStateTransitionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to cancel task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task.cancel',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
