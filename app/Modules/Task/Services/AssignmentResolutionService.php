<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentCardinality;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Events\StageAssignmentCreated;
use App\Modules\Task\Exceptions\MissingManualAssignmentException;
use App\Modules\Task\Exceptions\UnresolvableAssignmentException;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AssignmentResolutionService
{
    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function resolveStageAssignments(
        BlueprintStage $blueprintStage,
        Task $task,
        TaskStageInstance $stageInstance,
        array $manualAssignments = [],
    ): Collection {
        try {
            $assignments = collect();

            $resolvedUsers = $this->resolveUsers($blueprintStage, $manualAssignments);

            $blueprintCategoryId = $task->blueprint?->category_id;
            $stageTypeId = $blueprintStage->stage_type_id;

            foreach ($resolvedUsers as $i => $resolvedUser) {
                $effectiveUser = $this->iamPolicy->resolveDelegateForAssignment(
                    $resolvedUser,
                    $blueprintCategoryId,
                    $stageTypeId,
                ) ?? $resolvedUser;
                $delegatedFrom = $effectiveUser->id !== $resolvedUser->id ? $resolvedUser->id : null;
                $positionId = $resolvedUser->currentPositionAssignment?->position_id;

                $assignment = TaskStageAssignment::create([
                    'task_id' => $task->id,
                    'stage_instance_id' => $stageInstance->id,
                    'sub_stage_instance_id' => null,
                    'user_id' => $effectiveUser->id,
                    'position_id' => $positionId,
                    'delegated_from_user_id' => $delegatedFrom,
                    'assignment_role' => $i === 0 && $blueprintStage->completion_rule === CompletionRule::LeadAssignee
                        ? AssignmentRole::Lead->value
                        : AssignmentRole::Required->value,
                    'is_completed' => false,
                    'assigned_at' => now(),
                ]);

                event(new StageAssignmentCreated($assignment));
                $assignments->push($assignment);
            }

            return $assignments;
        } catch (UnresolvableAssignmentException|MissingManualAssignmentException $e) {
            Log::channel('task')->warning('Assignment resolution failed', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'assignment.resolve',
                'entity_type' => 'task_stage_assignment',
                'entity_id' => $task->public_id,
                'stage_id' => $blueprintStage->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Unexpected assignment resolution error', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'assignment.resolve',
                'entity_type' => 'task_stage_assignment',
                'entity_id' => $task->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function resolveSubStageAssignments(
        BlueprintSubStage $blueprintSubStage,
        Task $task,
        TaskSubStageInstance $subStageInstance,
        array $manualAssignments = [],
    ): Collection {
        try {
            if ($blueprintSubStage->assignment_type === null) {
                return collect();
            }

            $assignments = collect();
            $resolvedUsers = $this->resolveUsersForSubStage($blueprintSubStage, $manualAssignments);

            $blueprintCategoryId = $task->blueprint?->category_id;
            $stageTypeId = $blueprintSubStage->stage->stage_type_id ?? null;

            foreach ($resolvedUsers as $i => $resolvedUser) {
                $effectiveUser = $this->iamPolicy->resolveDelegateForAssignment(
                    $resolvedUser,
                    $blueprintCategoryId,
                    $stageTypeId,
                ) ?? $resolvedUser;
                $delegatedFrom = $effectiveUser->id !== $resolvedUser->id ? $resolvedUser->id : null;
                $positionId = $resolvedUser->currentPositionAssignment?->position_id;

                $assignment = TaskStageAssignment::create([
                    'task_id' => $task->id,
                    'stage_instance_id' => null,
                    'sub_stage_instance_id' => $subStageInstance->id,
                    'user_id' => $effectiveUser->id,
                    'position_id' => $positionId,
                    'delegated_from_user_id' => $delegatedFrom,
                    'assignment_role' => $i === 0 && $blueprintSubStage->completion_rule === CompletionRule::LeadAssignee
                        ? AssignmentRole::Lead->value
                        : AssignmentRole::Required->value,
                    'is_completed' => false,
                    'assigned_at' => now(),
                ]);

                event(new StageAssignmentCreated($assignment));
                $assignments->push($assignment);
            }

            return $assignments;
        } catch (UnresolvableAssignmentException|MissingManualAssignmentException $e) {
            Log::channel('task')->warning('Sub-stage assignment resolution failed', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'assignment.resolve',
                'entity_type' => 'task_sub_stage_assignment',
                'entity_id' => $task->public_id,
                'sub_stage_id' => $blueprintSubStage->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Unexpected sub-stage assignment resolution error', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'assignment.resolve',
                'entity_type' => 'task_sub_stage_assignment',
                'entity_id' => $task->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function resolveUsers(BlueprintStage $blueprintStage, array $manualAssignments): array
    {
        return match ($blueprintStage->assignment_type) {
            AssignmentType::SpecificPosition => $this->resolveSpecificPositionUsers($blueprintStage),
            AssignmentType::DepartmentHead => [$this->resolveDepartmentHead($blueprintStage)],
            AssignmentType::ManualAtLaunch => $this->resolveManualUsers($blueprintStage, $manualAssignments),
            default => [],
        };
    }

    private function resolveUsersForSubStage(BlueprintSubStage $subStage, array $manualAssignments): array
    {
        return match ($subStage->assignment_type) {
            AssignmentType::SpecificPosition => $this->resolveSpecificPositionUsersFromSubStage($subStage),
            AssignmentType::DepartmentHead => [$this->resolveDepartmentHeadFromSubStage($subStage)],
            AssignmentType::ManualAtLaunch => $this->resolveManualUsersFromSubStage($subStage, $manualAssignments),
            default => [],
        };
    }

    private function resolveSpecificPositionUsers(BlueprintStage $stage): array
    {
        if ($stage->assignment_cardinality === AssignmentCardinality::Multiple) {
            $occupants = UserPositionAssignment::where('position_id', $stage->assigned_position_id)
                ->where('is_primary', true)
                ->whereNull('ended_at')
                ->with('user')
                ->get();

            if ($occupants->isEmpty()) {
                throw new UnresolvableAssignmentException(
                    "Position '{$stage->assignedPosition?->title_ar}' has no active occupants."
                );
            }

            return $occupants->pluck('user')->all();
        }

        $occupant = $stage->assignedPosition?->currentOccupant;

        if (! $occupant) {
            throw new UnresolvableAssignmentException(
                "Position '{$stage->assignedPosition?->title_ar}' has no active occupant."
            );
        }

        return [$occupant->user];
    }

    private function resolveSpecificPositionUsersFromSubStage(BlueprintSubStage $subStage): array
    {
        if ($subStage->assignment_cardinality === AssignmentCardinality::Multiple) {
            $occupants = UserPositionAssignment::where('position_id', $subStage->assigned_position_id)
                ->where('is_primary', true)
                ->whereNull('ended_at')
                ->with('user')
                ->get();

            if ($occupants->isEmpty()) {
                throw new UnresolvableAssignmentException(
                    "Position '{$subStage->assignedPosition?->title_ar}' has no active occupants."
                );
            }

            return $occupants->pluck('user')->all();
        }

        $occupant = $subStage->assignedPosition?->currentOccupant;

        if (! $occupant) {
            throw new UnresolvableAssignmentException(
                "Position '{$subStage->assignedPosition?->title_ar}' has no active occupant."
            );
        }

        return [$occupant->user];
    }

    private function resolveDepartmentHead(BlueprintStage $stage): User
    {
        $headPosition = Position::where('department_id', $stage->assigned_department_id)
            ->where('is_department_head', true)
            ->active()
            ->first();

        if (! $headPosition) {
            throw new UnresolvableAssignmentException(
                'No department head position found for department.'
            );
        }

        $occupant = $headPosition->currentOccupant;

        if (! $occupant) {
            throw new UnresolvableAssignmentException(
                'Department head position is vacant.'
            );
        }

        return $occupant->user;
    }

    private function resolveDepartmentHeadFromSubStage(BlueprintSubStage $subStage): User
    {
        $headPosition = Position::where('department_id', $subStage->assigned_department_id)
            ->where('is_department_head', true)
            ->active()
            ->first();

        if (! $headPosition) {
            throw new UnresolvableAssignmentException(
                'No department head position found for sub-stage department.'
            );
        }

        $occupant = $headPosition->currentOccupant;

        if (! $occupant) {
            throw new UnresolvableAssignmentException(
                'Department head sub-stage position is vacant.'
            );
        }

        return $occupant->user;
    }

    private function resolveManualUsers(BlueprintStage $stage, array $manualAssignments): array
    {
        $stageAssignments = collect($manualAssignments)
            ->firstWhere('blueprint_stage_id', $stage->public_id);

        if (! $stageAssignments || empty($stageAssignments['user_ids'])) {
            throw new MissingManualAssignmentException($stage->name_en ?? $stage->name_ar);
        }

        $users = User::whereIn('public_id', $stageAssignments['user_ids'])
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            throw new UnresolvableAssignmentException(
                'Manual assignee users not found or inactive.'
            );
        }

        return $users->all();
    }

    private function resolveManualUsersFromSubStage(BlueprintSubStage $subStage, array $manualAssignments): array
    {
        $stageAssignments = collect($manualAssignments)
            ->firstWhere('blueprint_sub_stage_id', $subStage->public_id);

        if (! $stageAssignments || empty($stageAssignments['user_ids'])) {
            throw new MissingManualAssignmentException($subStage->name_en ?? $subStage->name_ar, isSubStage: true);
        }

        $users = User::whereIn('public_id', $stageAssignments['user_ids'])
            ->where('is_active', true)
            ->get();

        if ($users->isEmpty()) {
            throw new UnresolvableAssignmentException(
                'Manual assignee users for sub-stage not found or inactive.'
            );
        }

        return $users->all();
    }
}
