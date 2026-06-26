<?php

namespace App\Modules\FollowUp\Services;

use App\Models\User;
use App\Modules\FollowUp\Events\FollowUpActionCreated;
use App\Modules\FollowUp\Exceptions\FollowUpActionNotAllowedException;
use App\Modules\FollowUp\Models\FollowUpAction;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;

class FollowUpActionService
{
    public function __construct(
        private IamPolicy $iamPolicy,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function create(Task $task, User $user, array $data): FollowUpAction
    {
        try {
            $this->ensureCanLogActions($user);
            $this->ensureTaskVisible($task, $user);

            $action = FollowUpAction::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'action_type' => $data['action_type'],
                'note_ar' => $data['note_ar'],
                'note_en' => ! empty($data['note_en']) ? $data['note_en'] : $data['note_ar'],
                'contact_name' => $data['contact_name'] ?? null,
            ]);

            event(new FollowUpActionCreated($action));

            return $action->fresh(['user']);
        } catch (FollowUpActionNotAllowedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('followup')->error('Failed to create follow-up action', [
                'tenant_slug' => tenant()->slug,
                'action' => 'followup.action.create',
                'entity_type' => 'follow_up_action',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function listRecent(User $user, array $filters): CursorPaginator
    {
        try {
            $visibleTaskIds = $this->taskVisibilityScope->apply(
                Task::query()->select('tasks.id'),
                $user
            );

            return FollowUpAction::whereIn('task_id', $visibleTaskIds)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->cursorPaginate($filters['per_page'] ?? 15);
        } catch (\Throwable $e) {
            Log::channel('followup')->error('Failed to list recent follow-up actions', [
                'tenant_slug' => tenant()->slug,
                'action' => 'followup.action.recent',
                'entity_type' => 'follow_up_action',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(Task $task, User $user, array $filters): CursorPaginator
    {
        try {
            $this->ensureTaskVisible($task, $user);

            return FollowUpAction::where('task_id', $task->id)
                ->with('user')
                ->orderBy('created_at')
                ->orderBy('id')
                ->cursorPaginate($filters['per_page'] ?? 15);
        } catch (\Throwable $e) {
            Log::channel('followup')->error('Failed to list follow-up actions', [
                'tenant_slug' => tenant()->slug,
                'action' => 'followup.action.list',
                'entity_type' => 'follow_up_action',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function ensureCanLogActions(User $user): void
    {
        if (
            ! $this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope')
            && ! $this->iamPolicy->hasCapability($user, 'task.view.organization')
            && ! $this->iamPolicy->hasCapability($user, 'task.view.department_touched')
        ) {
            throw new FollowUpActionNotAllowedException;
        }
    }

    private function ensureTaskVisible(Task $task, User $user): void
    {
        $this->taskVisibilityScope->apply(
            Task::query()->where('tasks.id', $task->id),
            $user
        )->firstOrFail();
    }
}
