<?php

namespace App\Modules\Tracking\Services;

use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use App\Modules\Tracking\Events\EscalationCreated;
use App\Modules\Tracking\Events\EscalationResolved;
use App\Modules\Tracking\Exceptions\DuplicateOpenEscalationException;
use App\Modules\Tracking\Exceptions\EscalationAlreadyResolvedException;
use App\Modules\Tracking\Exceptions\EscalationResolutionUnauthorizedException;
use App\Modules\Tracking\Models\Escalation;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlaEscalationService
{
    public function __construct(
        private IamPolicy $iamPolicy,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function createAutoEscalation(SlaTimerInstance $timer): void
    {
        try {
            $stageInstance = $timer->stageInstance ?? $timer->subStageInstance?->parentStageInstance;
            if (! $stageInstance) {
                Log::channel('tracking')->warning('Cannot auto-escalate: no stage instance', [
                    'tenant_slug' => tenant()?->slug ?? 'central',
                    'action' => 'escalation.auto_create',
                    'entity_type' => 'sla_timer_instance',
                    'entity_id' => $timer->public_id,
                    'performed_by' => 'system',
                ]);

                return;
            }

            $blueprintStage = $stageInstance->blueprintStage;
            $targets = $this->resolveEscalationTargets($blueprintStage, $stageInstance);

            if ($targets->isEmpty()) {
                Log::channel('tracking')->warning('No escalation target resolved for breached timer', [
                    'tenant_slug' => tenant()?->slug ?? 'central',
                    'action' => 'escalation.auto_no_target',
                    'entity_type' => 'sla_timer_instance',
                    'entity_id' => $timer->public_id,
                    'performed_by' => 'system',
                ]);

                return;
            }

            foreach ($targets as $target) {
                DB::transaction(function () use ($timer, $target) {
                    $escalation = Escalation::create([
                        'task_id' => $timer->task_id,
                        'stage_instance_id' => $timer->stage_instance_id,
                        'sub_stage_instance_id' => $timer->sub_stage_instance_id,
                        'sla_timer_instance_id' => $timer->id,
                        'escalation_type' => EscalationType::AutoSlaBreach,
                        'escalated_to_user_id' => $target['user_id'],
                        'escalated_to_position_id' => $target['position_id'],
                        'escalated_by_user_id' => null,
                        'reason' => 'SLA deadline breached.',
                        'status' => EscalationStatus::Open,
                    ]);

                    event(new EscalationCreated($escalation));
                });
            }
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to create auto escalation', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'escalation.auto_create',
                'entity_type' => 'sla_timer_instance',
                'entity_id' => $timer->public_id ?? null,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function createManualEscalation(array $data, User $user): Escalation
    {
        try {
            return DB::transaction(function () use ($data, $user) {
                // Check for duplicate open escalation from same user
                $exists = Escalation::where('escalated_by_user_id', $user->id)
                    ->where('stage_instance_id', $data['stage_instance_id'] ?? null)
                    ->where('sub_stage_instance_id', $data['sub_stage_instance_id'] ?? null)
                    ->where('status', EscalationStatus::Open->value)
                    ->where('escalation_type', EscalationType::Manual->value)
                    ->exists();

                if ($exists) {
                    throw new DuplicateOpenEscalationException;
                }

                // Resolve public IDs to internal IDs
                $task = $this->taskVisibilityScope->apply(Task::query(), $user)
                    ->where('public_id', $data['task_id'])
                    ->firstOrFail();
                $taskId = $task->id;
                $stageInstanceId = null;
                $subStageInstanceId = null;

                if (! empty($data['stage_instance_id'])) {
                    $stageInstance = TaskStageInstance::where('public_id', $data['stage_instance_id'])->first();
                    $stageInstanceId = $stageInstance?->id;
                }

                if (! empty($data['sub_stage_instance_id'])) {
                    $subStageInstance = TaskSubStageInstance::where('public_id', $data['sub_stage_instance_id'])->first();
                    $subStageInstanceId = $subStageInstance?->id;
                }

                // Resolve target
                $data['task_id'] = $taskId;
                $data['stage_instance_id'] = $stageInstanceId;
                $data['sub_stage_instance_id'] = $subStageInstanceId;
                $target = $this->resolveManualEscalationTarget($data, $user);

                $escalation = Escalation::create([
                    'task_id' => $taskId,
                    'stage_instance_id' => $stageInstanceId,
                    'sub_stage_instance_id' => $subStageInstanceId,
                    'sla_timer_instance_id' => null,
                    'escalation_type' => EscalationType::Manual,
                    'escalated_to_user_id' => $target['user_id'],
                    'escalated_to_position_id' => $target['position_id'],
                    'escalated_by_user_id' => $user->id,
                    'reason' => $data['reason'],
                    'status' => EscalationStatus::Open,
                ]);

                event(new EscalationCreated($escalation));

                return $escalation;
            });
        } catch (DuplicateOpenEscalationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to create manual escalation', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'escalation.manual_create',
                'entity_type' => 'task',
                'entity_id' => $data['task_id'] ?? null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function resolveEscalation(Escalation $escalation, User $user, string $resolutionNote): Escalation
    {
        try {
            return DB::transaction(function () use ($escalation, $user, $resolutionNote) {
                if ($escalation->status === EscalationStatus::Resolved) {
                    throw new EscalationAlreadyResolvedException;
                }

                if ($escalation->escalated_to_user_id !== $user->id) {
                    $hasCapability = $this->iamPolicy->check($user, 'task.resolve_escalations');
                    if (! $hasCapability) {
                        throw new EscalationResolutionUnauthorizedException;
                    }
                }

                $escalation->update([
                    'status' => EscalationStatus::Resolved,
                    'resolution_note' => $resolutionNote,
                    'resolved_at' => now(),
                ]);

                event(new EscalationResolved($escalation));

                return $escalation->fresh();
            });
        } catch (EscalationAlreadyResolvedException|EscalationResolutionUnauthorizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to resolve escalation', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'escalation.resolve',
                'entity_type' => 'escalation',
                'entity_id' => $escalation->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function resolveEscalationTargets($blueprintStage, TaskStageInstance $stageInstance): Collection
    {
        $targets = collect();

        if ($blueprintStage->escalation_position_id) {
            $position = Position::find($blueprintStage->escalation_position_id);
            if ($position) {
                $occupant = $position->currentOccupant;
                if ($occupant) {
                    $targets->push([
                        'user_id' => $occupant->user_id,
                        'position_id' => $position->id,
                    ]);

                    return $targets;
                }
            }
        }

        $activeAssignments = $stageInstance->assignments()
            ->where('is_completed', false)
            ->whereNull('reassigned_at')
            ->with('position.reportsTo.currentOccupant')
            ->get();

        $seenPositionIds = [];
        foreach ($activeAssignments as $assignment) {
            $position = $assignment->position;
            if (! $position || ! $position->reports_to_position_id) {
                continue;
            }

            $reportsToPosition = $position->reportsTo;
            if (! $reportsToPosition || in_array($reportsToPosition->id, $seenPositionIds)) {
                continue;
            }

            $occupant = $reportsToPosition->currentOccupant;
            if ($occupant) {
                $targets->push([
                    'user_id' => $occupant->user_id,
                    'position_id' => $reportsToPosition->id,
                ]);
                $seenPositionIds[] = $reportsToPosition->id;
            }
        }

        return $targets;
    }

    private function resolveManualEscalationTarget(array $data, User $user): array
    {
        // If explicit position provided, use it
        if (isset($data['escalated_to_position_id'])) {
            $position = Position::where('public_id', $data['escalated_to_position_id'])->firstOrFail();
            $occupant = $position->currentOccupant;

            return [
                'user_id' => $occupant ? $occupant->user_id : $user->id,
                'position_id' => $position->id,
            ];
        }

        // Auto-resolve from stage/sub-stage context
        if (isset($data['stage_instance_id']) || isset($data['sub_stage_instance_id'])) {
            $stageInstance = null;
            if (isset($data['stage_instance_id'])) {
                $stageInstance = TaskStageInstance::where('public_id', $data['stage_instance_id'])->first();
            } elseif (isset($data['sub_stage_instance_id'])) {
                $subStage = TaskSubStageInstance::where('public_id', $data['sub_stage_instance_id'])->first();
                $stageInstance = $subStage?->parentStageInstance;
            }

            if ($stageInstance) {
                $blueprintStage = $stageInstance->blueprintStage;
                $targets = $this->resolveEscalationTargets($blueprintStage, $stageInstance);

                if ($targets->isNotEmpty()) {
                    return $targets->first();
                }
            }
        }

        // Fallback: escalate to the user's own reporting line
        $position = $user->currentPositionAssignment?->position;
        if ($position && $position->reports_to_position_id) {
            $reportsTo = $position->reportsTo;
            if ($reportsTo) {
                $occupant = $reportsTo->currentOccupant;

                return [
                    'user_id' => $occupant ? $occupant->user_id : $user->id,
                    'position_id' => $reportsTo->id,
                ];
            }
        }

        // Last resort: escalate to the user themselves (better than failing)
        return [
            'user_id' => $user->id,
            'position_id' => $user->currentPositionAssignment?->position_id,
        ];
    }
}
