<?php

namespace App\Modules\Task\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use App\Modules\Task\Events\ConfidentialParticipantAdded;
use App\Modules\Task\Events\ConfidentialParticipantRemoved;
use App\Modules\Task\Exceptions\CannotManageConfidentialParticipantsException;
use App\Modules\Task\Exceptions\DuplicateConfidentialParticipantException;
use App\Modules\Task\Exceptions\GovernanceParticipantNotFoundException;
use App\Modules\Task\Exceptions\TaskNotConfidentialException;
use App\Modules\Task\Models\ConfidentialAccessEvent;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskConfidentialParticipant;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfidentialParticipantService
{
    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function listForTask(Task $task, int $perPage = 15): CursorPaginator
    {
        try {
            return TaskConfidentialParticipant::where('task_id', $task->id)
                ->with(['user', 'addedBy'])
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to list confidential participants', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_participant.list',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function add(Task $task, User $participant, User $addedBy): TaskConfidentialParticipant
    {
        try {
            return DB::transaction(function () use ($task, $participant, $addedBy) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                if (! $this->canManageParticipants($task, $addedBy)) {
                    throw new CannotManageConfidentialParticipantsException;
                }

                $exists = TaskConfidentialParticipant::where('task_id', $task->id)
                    ->where('user_id', $participant->id)
                    ->whereNull('removed_at')
                    ->exists();

                if ($exists) {
                    throw new DuplicateConfidentialParticipantException;
                }

                $row = TaskConfidentialParticipant::create([
                    'task_id' => $task->id,
                    'user_id' => $participant->id,
                    'added_by_user_id' => $addedBy->id,
                    'added_at' => now(),
                ]);

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $addedBy->id,
                    'access_type' => ConfidentialAccessEventType::ParticipantAdded,
                ]);

                event(new ConfidentialParticipantAdded($task, $participant, $addedBy));

                return $row->load(['user', 'addedBy']);
            });
        } catch (TaskNotConfidentialException|CannotManageConfidentialParticipantsException|DuplicateConfidentialParticipantException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to add confidential participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_participant.add',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $addedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function remove(Task $task, User $participant, User $removedBy): TaskConfidentialParticipant
    {
        try {
            return DB::transaction(function () use ($task, $participant, $removedBy) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                if (! $this->canManageParticipants($task, $removedBy)) {
                    throw new CannotManageConfidentialParticipantsException;
                }

                $row = TaskConfidentialParticipant::where('task_id', $task->id)
                    ->where('user_id', $participant->id)
                    ->whereNull('removed_at')
                    ->first();

                if (! $row) {
                    throw new GovernanceParticipantNotFoundException;
                }

                $row->update(['removed_at' => now()]);

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $removedBy->id,
                    'access_type' => ConfidentialAccessEventType::ParticipantRemoved,
                ]);

                event(new ConfidentialParticipantRemoved($task, $participant, $removedBy));

                return $row->load(['user', 'addedBy']);
            });
        } catch (TaskNotConfidentialException|CannotManageConfidentialParticipantsException|GovernanceParticipantNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to remove confidential participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_participant.remove',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $removedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function canManageParticipants(Task $task, User $user): bool
    {
        if ($task->initiator_user_id === $user->id) {
            $settings = tenant()?->settings['confidentiality'] ?? [];
            if ($settings['initiator_can_manage_participants'] ?? true) {
                return true;
            }
        }

        if ($this->iamPolicy->check($user, 'task.confidential.manage_participants', ScopeType::TENANT)) {
            return true;
        }

        $taskDeptId = $task->stageInstances()->first()?->owning_department_id
            ?? $task->initiator?->currentPositionAssignment?->position?->department_id;

        if ($taskDeptId === null) {
            return false;
        }

        return $this->iamPolicy->check($user, 'task.confidential.manage_participants', ScopeType::SPECIFIC_DEPARTMENT, $taskDeptId);
    }
}
