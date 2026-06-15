<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Enums\TransitionType;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Events\StageAssignmentCompleted;
use App\Modules\Task\Events\StageAssignmentCreated;
use App\Modules\Task\Events\StageAssignmentOverridden;
use App\Modules\Task\Events\StageInstanceAdvanced;
use App\Modules\Task\Events\StageInstanceCompleted;
use App\Modules\Task\Events\StageInstanceCreated;
use App\Modules\Task\Events\StageInstanceReturned;
use App\Modules\Task\Events\SubStageAssignmentCompleted;
use App\Modules\Task\Events\SubStageInstanceCompleted;
use App\Modules\Task\Events\SubStageInstanceCreated;
use App\Modules\Task\Events\SubStageInstanceReturned;
use App\Modules\Task\Events\TaskCompleted;
use App\Modules\Task\Exceptions\AssigneeNotFoundForOverrideException;
use App\Modules\Task\Exceptions\InvalidReturnTargetException;
use App\Modules\Task\Exceptions\InvalidSubStageReturnTargetException;
use App\Modules\Task\Exceptions\RequiredSubStagesIncompleteException;
use App\Modules\Task\Exceptions\StageNotActiveException;
use App\Modules\Task\Exceptions\SubStageNotActiveException;
use App\Modules\Task\Exceptions\TaskNotActiveException;
use App\Modules\Task\Exceptions\UserNotAssigneeException;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StageLifecycleService
{
    use AuthenticatedUser;

    public function __construct(
        private AssignmentResolutionService $assignmentResolutionService,
        private IamPolicy $iamPolicy,
    ) {}

    public function completeStage(Task $task, TaskStageInstance $stageInstance, User $user, ?string $completionNote = null): TaskStageInstance
    {
        try {
            return DB::transaction(function () use ($task, $stageInstance, $user, $completionNote) {
                if (! $task->isActive()) {
                    throw new TaskNotActiveException;
                }

                if ($stageInstance->status !== StageInstanceStatus::Active) {
                    throw new StageNotActiveException;
                }

                $assignment = $stageInstance->assignments()
                    ->where('user_id', $user->id)
                    ->where('is_completed', false)
                    ->whereNull('reassigned_at')
                    ->first();

                if (! $assignment) {
                    throw new UserNotAssigneeException;
                }

                /** @var TaskStageAssignment $assignment */
                $assignment->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                    'completion_note' => $completionNote,
                    'completion_note_ar' => $completionNote,
                    'completion_note_en' => $completionNote,
                ]);

                event(new StageAssignmentCompleted($assignment));

                if (! $this->evaluateCompletionRule($stageInstance)) {
                    return $stageInstance->fresh(['assignments']);
                }

                $hasSubStages = $stageInstance->subStageInstances()->exists();
                if ($hasSubStages) {
                    $incompleteRequired = $stageInstance->subStageInstances()
                        ->where('is_required', true)
                        ->where('status', '!=', SubStageInstanceStatus::Completed->value)
                        ->exists();

                    if ($incompleteRequired) {
                        throw new RequiredSubStagesIncompleteException;
                    }
                }

                $stageInstance->update([
                    'status' => StageInstanceStatus::Completed,
                    'exited_at' => now(),
                    'completion_note' => $completionNote,
                ]);

                event(new StageInstanceCompleted($stageInstance));

                $nextBlueprintStage = $this->resolveNextStage($task, $stageInstance);

                if ($nextBlueprintStage) {
                    $newStageInstance = $this->advanceToStage($task, $stageInstance, $nextBlueprintStage);
                    event(new StageInstanceAdvanced($stageInstance, $newStageInstance));

                    return $newStageInstance->fresh(['assignments', 'subStageInstances']);
                }

                $task->update([
                    'status' => TaskStatus::Completed,
                    'completed_at' => now(),
                ]);

                event(new TaskCompleted($task));

                return $stageInstance->fresh(['assignments']);
            });
        } catch (TaskNotActiveException|StageNotActiveException|UserNotAssigneeException|RequiredSubStagesIncompleteException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to complete stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.complete',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $stageInstance->id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function returnStage(Task $task, TaskStageInstance $stageInstance, User $user, string $targetStagePublicId, string $reason): TaskStageInstance
    {
        try {
            return DB::transaction(function () use ($task, $stageInstance, $user, $targetStagePublicId, $reason) {
                if (! $task->isActive()) {
                    throw new TaskNotActiveException;
                }

                if ($stageInstance->status !== StageInstanceStatus::Active) {
                    throw new StageNotActiveException;
                }

                $assignment = $stageInstance->assignments()
                    ->where('user_id', $user->id)
                    ->where('is_completed', false)
                    ->whereNull('reassigned_at')
                    ->first();

                if (! $assignment) {
                    throw new UserNotAssigneeException;
                }

                $targetBlueprintStage = BlueprintStage::where('public_id', $targetStagePublicId)->first();
                if (! $targetBlueprintStage) {
                    throw new InvalidReturnTargetException;
                }

                $returnTransition = BlueprintTransition::where('blueprint_id', $task->blueprint_id)
                    ->where('from_stage_id', $stageInstance->blueprint_stage_id)
                    ->where('to_stage_id', $targetBlueprintStage->id)
                    ->where('transition_type', TransitionType::Return->value)
                    ->first();

                if (! $returnTransition) {
                    throw new InvalidReturnTargetException;
                }

                $stageInstance->update([
                    'status' => StageInstanceStatus::Returned,
                    'exited_at' => now(),
                    'return_reason' => $reason,
                ]);

                $stageInstance->subStageInstances()
                    ->whereIn('status', [SubStageInstanceStatus::Active->value, SubStageInstanceStatus::Pending->value])
                    ->update(['status' => SubStageInstanceStatus::Returned->value, 'exited_at' => now()]);

                event(new StageInstanceReturned($stageInstance, $reason, $user));

                $newStageInstance = $this->advanceToStage($task, $stageInstance, $targetBlueprintStage);

                return $newStageInstance->fresh(['assignments', 'subStageInstances']);
            });
        } catch (TaskNotActiveException|StageNotActiveException|UserNotAssigneeException|InvalidReturnTargetException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to return stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.return',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $stageInstance->id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function completeSubStage(Task $task, TaskSubStageInstance $subStageInstance, User $user, ?string $completionNote = null): TaskSubStageInstance
    {
        try {
            return DB::transaction(function () use ($task, $subStageInstance, $user, $completionNote) {
                if (! $task->isActive()) {
                    throw new TaskNotActiveException;
                }

                if ($subStageInstance->status !== SubStageInstanceStatus::Active) {
                    throw new SubStageNotActiveException;
                }

                $assignment = $subStageInstance->assignments()
                    ->where('user_id', $user->id)
                    ->where('is_completed', false)
                    ->whereNull('reassigned_at')
                    ->first();

                if (! $assignment) {
                    throw new UserNotAssigneeException;
                }

                /** @var TaskStageAssignment $assignment */
                $assignment->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                    'completion_note' => $completionNote,
                    'completion_note_ar' => $completionNote,
                    'completion_note_en' => $completionNote,
                ]);

                event(new SubStageAssignmentCompleted($assignment));

                if (! $this->evaluateCompletionRule($subStageInstance)) {
                    return $subStageInstance->fresh(['assignments']);
                }

                $subStageInstance->update([
                    'status' => SubStageInstanceStatus::Completed,
                    'exited_at' => now(),
                    'completion_note' => $completionNote,
                ]);

                event(new SubStageInstanceCompleted($subStageInstance));

                $parentStageInstance = $subStageInstance->parentStageInstance;
                $nextBlueprintSubStage = BlueprintSubStage::where('blueprint_stage_id', $parentStageInstance->blueprint_stage_id)
                    ->where('sequence_order', '>', $subStageInstance->sequence_order)
                    ->orderBy('sequence_order')
                    ->first();

                if ($nextBlueprintSubStage) {
                    $nextSubInstance = TaskSubStageInstance::create([
                        'task_id' => $task->id,
                        'parent_stage_instance_id' => $parentStageInstance->id,
                        'blueprint_sub_stage_id' => $nextBlueprintSubStage->id,
                        'sequence_order' => $nextBlueprintSubStage->sequence_order,
                        'is_required' => $nextBlueprintSubStage->is_required,
                        'completion_rule' => $nextBlueprintSubStage->completion_rule->value,
                        'status' => SubStageInstanceStatus::Active->value,
                        'entered_at' => now(),
                    ]);

                    event(new SubStageInstanceCreated($nextSubInstance));

                    if ($nextBlueprintSubStage->assignment_type !== null) {
                        $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $nextBlueprintSubStage);
                        $this->assignmentResolutionService->resolveSubStageAssignments(
                            $nextBlueprintSubStage, $task, $nextSubInstance, $manualAssignments,
                        );
                    }
                }

                return $subStageInstance->fresh(['assignments']);
            });
        } catch (TaskNotActiveException|SubStageNotActiveException|UserNotAssigneeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to complete sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.complete',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $subStageInstance->id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function returnSubStage(Task $task, TaskSubStageInstance $subStageInstance, User $user, string $targetSubStagePublicId, string $reason): TaskSubStageInstance
    {
        try {
            return DB::transaction(function () use ($task, $subStageInstance, $user, $targetSubStagePublicId, $reason) {
                if (! $task->isActive()) {
                    throw new TaskNotActiveException;
                }

                if ($subStageInstance->status !== SubStageInstanceStatus::Active) {
                    throw new SubStageNotActiveException;
                }

                $assignment = $subStageInstance->assignments()
                    ->where('user_id', $user->id)
                    ->where('is_completed', false)
                    ->whereNull('reassigned_at')
                    ->first();

                if (! $assignment) {
                    throw new UserNotAssigneeException;
                }

                $targetBlueprintSubStage = BlueprintSubStage::where('public_id', $targetSubStagePublicId)->first();
                if (! $targetBlueprintSubStage) {
                    throw new InvalidSubStageReturnTargetException;
                }

                $parentStageInstance = $subStageInstance->parentStageInstance;

                if ($targetBlueprintSubStage->blueprint_stage_id !== $parentStageInstance->blueprint_stage_id) {
                    throw new InvalidSubStageReturnTargetException;
                }

                if ($targetBlueprintSubStage->sequence_order >= $subStageInstance->sequence_order) {
                    throw new InvalidSubStageReturnTargetException;
                }

                $subStageInstance->update([
                    'status' => SubStageInstanceStatus::Returned,
                    'exited_at' => now(),
                ]);

                event(new SubStageInstanceReturned($subStageInstance, $reason));

                $newSubInstance = TaskSubStageInstance::create([
                    'task_id' => $task->id,
                    'parent_stage_instance_id' => $parentStageInstance->id,
                    'blueprint_sub_stage_id' => $targetBlueprintSubStage->id,
                    'sequence_order' => $targetBlueprintSubStage->sequence_order,
                    'is_required' => $targetBlueprintSubStage->is_required,
                    'completion_rule' => $targetBlueprintSubStage->completion_rule->value,
                    'status' => SubStageInstanceStatus::Active->value,
                    'entered_at' => now(),
                ]);

                event(new SubStageInstanceCreated($newSubInstance));

                if ($targetBlueprintSubStage->assignment_type !== null) {
                    $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $targetBlueprintSubStage);
                    $this->assignmentResolutionService->resolveSubStageAssignments(
                        $targetBlueprintSubStage, $task, $newSubInstance, $manualAssignments,
                    );
                }

                return $subStageInstance->fresh(['assignments']);
            });
        } catch (TaskNotActiveException|SubStageNotActiveException|UserNotAssigneeException|InvalidSubStageReturnTargetException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to return sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.return',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $subStageInstance->id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function overrideStageAssignment(Task $task, TaskStageInstance $stageInstance, User $callerUser, array $assignmentOverrides, string $reason): TaskStageInstance
    {
        try {
            return DB::transaction(function () use ($task, $stageInstance, $callerUser, $assignmentOverrides, $reason) {
                if (! $task->isActive()) {
                    throw new TaskNotActiveException;
                }

                if ($stageInstance->status !== StageInstanceStatus::Active) {
                    throw new StageNotActiveException;
                }

                if (! $this->iamPolicy->hasCapability($callerUser, 'task.override_assignment')) {
                    abort(403, 'Missing task.override_assignment capability.');
                }

                foreach ($assignmentOverrides as $override) {
                    $currentUser = User::where('public_id', $override['current_user_id'])->first();
                    $newUser = User::where('public_id', $override['new_user_id'])->first();

                    if (! $currentUser || ! $newUser) {
                        throw new AssigneeNotFoundForOverrideException;
                    }

                    $oldAssignment = $stageInstance->assignments()
                        ->where('user_id', $currentUser->id)
                        ->where('is_completed', false)
                        ->whereNull('reassigned_at')
                        ->first();

                    if (! $oldAssignment) {
                        throw new AssigneeNotFoundForOverrideException;
                    }

                    /** @var TaskStageAssignment $oldAssignment */
                    $oldAssignment->update([
                        'reassigned_at' => now(),
                        'reassigned_by_user_id' => $callerUser->id,
                        'reassignment_reason' => $reason,
                    ]);

                    $effectiveUser = $this->iamPolicy->resolveAssignee($newUser);
                    $delegatedFrom = $effectiveUser->id !== $newUser->id ? $newUser->id : null;
                    $positionId = $newUser->currentPositionAssignment?->position_id;

                    $newAssignment = TaskStageAssignment::create([
                        'task_id' => $task->id,
                        'stage_instance_id' => $stageInstance->id,
                        'sub_stage_instance_id' => null,
                        'user_id' => $effectiveUser->id,
                        'position_id' => $positionId,
                        'delegated_from_user_id' => $delegatedFrom,
                        'assignment_role' => $oldAssignment->assignment_role->value,
                        'is_completed' => false,
                        'assigned_at' => now(),
                    ]);

                    event(new StageAssignmentCreated($newAssignment));
                    event(new StageAssignmentOverridden($oldAssignment, $newAssignment, $reason));
                }

                return $stageInstance->fresh(['assignments.user']);
            });
        } catch (TaskNotActiveException|StageNotActiveException|AssigneeNotFoundForOverrideException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to override stage assignment', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.override_assignment',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $stageInstance->id,
                'performed_by' => $callerUser->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function overrideSubStageAssignment(Task $task, TaskSubStageInstance $subStageInstance, User $callerUser, array $assignmentOverrides, string $reason): TaskSubStageInstance
    {
        try {
            return DB::transaction(function () use ($task, $subStageInstance, $callerUser, $assignmentOverrides, $reason) {
                if (! $task->isActive()) {
                    throw new TaskNotActiveException;
                }

                if ($subStageInstance->status !== SubStageInstanceStatus::Active) {
                    throw new SubStageNotActiveException;
                }

                if (! $this->iamPolicy->hasCapability($callerUser, 'task.override_assignment')) {
                    abort(403, 'Missing task.override_assignment capability.');
                }

                foreach ($assignmentOverrides as $override) {
                    $currentUser = User::where('public_id', $override['current_user_id'])->first();
                    $newUser = User::where('public_id', $override['new_user_id'])->first();

                    if (! $currentUser || ! $newUser) {
                        throw new AssigneeNotFoundForOverrideException;
                    }

                    $oldAssignment = $subStageInstance->assignments()
                        ->where('user_id', $currentUser->id)
                        ->where('is_completed', false)
                        ->whereNull('reassigned_at')
                        ->first();

                    if (! $oldAssignment) {
                        throw new AssigneeNotFoundForOverrideException;
                    }

                    /** @var TaskStageAssignment $oldAssignment */
                    $oldAssignment->update([
                        'reassigned_at' => now(),
                        'reassigned_by_user_id' => $callerUser->id,
                        'reassignment_reason' => $reason,
                    ]);

                    $effectiveUser = $this->iamPolicy->resolveAssignee($newUser);
                    $delegatedFrom = $effectiveUser->id !== $newUser->id ? $newUser->id : null;
                    $positionId = $newUser->currentPositionAssignment?->position_id;

                    $newAssignment = TaskStageAssignment::create([
                        'task_id' => $task->id,
                        'stage_instance_id' => null,
                        'sub_stage_instance_id' => $subStageInstance->id,
                        'user_id' => $effectiveUser->id,
                        'position_id' => $positionId,
                        'delegated_from_user_id' => $delegatedFrom,
                        'assignment_role' => $oldAssignment->assignment_role->value,
                        'is_completed' => false,
                        'assigned_at' => now(),
                    ]);

                    event(new StageAssignmentCreated($newAssignment));
                    event(new StageAssignmentOverridden($oldAssignment, $newAssignment, $reason));
                }

                return $subStageInstance->fresh(['assignments.user']);
            });
        } catch (TaskNotActiveException|SubStageNotActiveException|AssigneeNotFoundForOverrideException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to override sub-stage assignment', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.override_assignment',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $subStageInstance->id,
                'performed_by' => $callerUser->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getStageHistory(Task $task): Collection
    {
        return $task->stageInstances()
            ->with([
                'blueprintStage.stageType',
                'assignments.user',
                'subStageInstances.assignments.user',
                'subStageInstances.blueprintSubStage',
            ])
            ->orderBy('created_at')
            ->get();
    }

    public function getStageInstance(Task $task, TaskStageInstance $stageInstance): TaskStageInstance
    {
        return $stageInstance->load([
            'blueprintStage.stageType',
            'assignments.user',
            'assignments.position',
            'assignments.delegatedFromUser',
            'subStageInstances.assignments.user',
            'subStageInstances.blueprintSubStage',
        ]);
    }

    public function getReturnHistory(Task $task): Collection
    {
        return $task->stageInstances()
            ->where('status', StageInstanceStatus::Returned->value)
            ->with(['blueprintStage', 'assignments.user'])
            ->orderBy('exited_at')
            ->get();
    }

    public function getTimeline(Task $task): Collection
    {
        $entries = collect();

        $stageInstances = $task->stageInstances()
            ->with(['blueprintStage', 'assignments.user'])
            ->get();

        foreach ($stageInstances as $si) {
            if ($si->entered_at) {
                $entries->push([
                    'type' => 'stage_entered',
                    'timestamp' => $si->entered_at,
                    'stage_name_ar' => $si->blueprintStage?->name_ar,
                    'stage_name_en' => $si->blueprintStage?->name_en,
                    'status' => $si->status,
                    'sequence_order' => $si->sequence_order,
                ]);
            }

            if ($si->exited_at) {
                $entries->push([
                    'type' => $si->status === StageInstanceStatus::Returned ? 'stage_returned' : 'stage_completed',
                    'timestamp' => $si->exited_at,
                    'stage_name_ar' => $si->blueprintStage?->name_ar,
                    'stage_name_en' => $si->blueprintStage?->name_en,
                    'return_reason' => $si->return_reason,
                    'completion_note' => $si->completion_note,
                ]);
            }

            foreach ($si->assignments as $a) {
                $entries->push([
                    'type' => $a->reassigned_at ? 'assignment_overridden' : ($a->is_completed ? 'assignment_completed' : 'assignment_created'),
                    'timestamp' => $a->reassigned_at ?? $a->completed_at ?? $a->assigned_at,
                    'user_id' => $a->user?->public_id,
                    'user_name_ar' => $a->user?->name_ar,
                    'user_name_en' => $a->user?->name_en,
                    'reassignment_reason' => $a->reassignment_reason,
                    'completion_note' => $a->completion_note,
                ]);
            }
        }

        return $entries->sortBy('timestamp')->values();
    }

    private function evaluateCompletionRule(TaskStageInstance|TaskSubStageInstance $instance): bool
    {
        $assignments = $instance->assignments()
            ->whereNull('reassigned_at')
            ->get();

        return match ($instance->completion_rule) {
            CompletionRule::AnyAssignee => $assignments
                ->whereIn('assignment_role', [AssignmentRole::Required, AssignmentRole::Lead])
                ->where('is_completed', true)
                ->isNotEmpty(),

            CompletionRule::AllAssignees => $assignments
                ->whereIn('assignment_role', [AssignmentRole::Required, AssignmentRole::Lead])
                ->every(fn ($a) => $a->is_completed),

            CompletionRule::LeadAssignee => $assignments
                ->where('assignment_role', AssignmentRole::Lead)
                ->where('is_completed', true)
                ->isNotEmpty(),
        };
    }

    private function resolveNextStage(Task $task, TaskStageInstance $stageInstance): ?BlueprintStage
    {
        $transition = BlueprintTransition::where('blueprint_id', $task->blueprint_id)
            ->where('from_stage_id', $stageInstance->blueprint_stage_id)
            ->where('transition_type', TransitionType::Advance->value)
            ->first();

        if ($transition) {
            return BlueprintStage::find($transition->to_stage_id);
        }

        return BlueprintStage::where('blueprint_id', $task->blueprint_id)
            ->where('sequence_order', '>', $stageInstance->sequence_order)
            ->orderBy('sequence_order')
            ->first();
    }

    private function advanceToStage(Task $task, TaskStageInstance $completedInstance, BlueprintStage $nextBlueprintStage): TaskStageInstance
    {
        $newStageInstance = TaskStageInstance::create([
            'task_id' => $task->id,
            'blueprint_stage_id' => $nextBlueprintStage->id,
            'sequence_order' => $nextBlueprintStage->sequence_order,
            'completion_rule' => $nextBlueprintStage->completion_rule->value,
            'status' => StageInstanceStatus::Active->value,
            'entered_at' => now(),
        ]);

        event(new StageInstanceCreated($newStageInstance));

        $nextBlueprintStage->load('subStages');
        foreach ($nextBlueprintStage->subStages as $index => $subStage) {
            $subInstance = TaskSubStageInstance::create([
                'task_id' => $task->id,
                'parent_stage_instance_id' => $newStageInstance->id,
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

            if ($index === 0 && $subStage->assignment_type !== null) {
                $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $subStage);
                $this->assignmentResolutionService->resolveSubStageAssignments(
                    $subStage, $task, $subInstance, $manualAssignments,
                );
            }
        }

        $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $nextBlueprintStage);
        $assignments = $this->assignmentResolutionService->resolveStageAssignments(
            $nextBlueprintStage, $task, $newStageInstance, $manualAssignments,
        );

        if ($assignments->isNotEmpty()) {
            $departmentId = $assignments->first()->position_id
                ? Position::find($assignments->first()->position_id)?->department_id
                : null;
            $newStageInstance->update(['owning_department_id' => $departmentId]);
        }

        return $newStageInstance;
    }

    private function resolveManualAssignmentsForReentry(Task $task, BlueprintStage|BlueprintSubStage $stage): array
    {
        if ($stage->assignment_type !== AssignmentType::ManualAtLaunch) {
            return [];
        }

        $previousAssignments = TaskStageAssignment::where('task_id', $task->id)
            ->whereHas('stageInstance', fn ($q) => $q->where('blueprint_stage_id', $stage->id))
            ->whereNull('reassigned_at')
            ->with('user')
            ->get();

        if ($previousAssignments->isEmpty()) {
            return [];
        }

        $userPublicIds = $previousAssignments->pluck('user.public_id')->filter()->values()->all();

        $key = $stage instanceof BlueprintSubStage ? 'blueprint_sub_stage_id' : 'blueprint_stage_id';

        return [
            [
                $key => $stage->public_id,
                'user_ids' => $userPublicIds,
            ],
        ];
    }
}
