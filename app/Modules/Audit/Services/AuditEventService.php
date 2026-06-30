<?php

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditEventService
{
    public function __construct(
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function taskTrail(Task $task, Request $request, User $user): CursorPaginator
    {
        try {
            if ($user->isExternalAuditor()) {
                $this->guardExternalAuditorAccess($task, $user);
            } else {
                if (! $this->iamPolicy->hasCapability($user, 'audit.view_task')) {
                    abort(403, 'Missing audit.view_task capability.');
                }

                $visible = $this->taskVisibilityScope
                    ->apply(Task::query()->where('id', $task->id), $user)
                    ->exists();

                if (! $visible) {
                    abort(403, 'You do not have access to this task.');
                }
            }

            $query = AuditEvent::forRootEntity(AuditEntityType::Task, $task->id)
                ->with('user')
                ->orderBy('id');

            return $this->applyCursorPagination($query, $request);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to load task audit trail', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.task_trail',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function systemLog(Request $request, User $user): CursorPaginator
    {
        try {
            if (! $this->iamPolicy->hasCapability($user, 'audit.view_system')) {
                abort(403, 'Missing audit.view_system capability.');
            }

            $query = AuditEvent::query()->with('user')->orderBy('id');
            $this->applyFilters($query, $request);

            return $this->applyCursorPagination($query, $request);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to load system audit log', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.system_log',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function myActivity(Request $request, User $user): CursorPaginator
    {
        try {
            $query = AuditEvent::query()
                ->where('user_id', $user->id)
                ->with('user')
                ->orderBy('id');

            $this->applyFilters($query, $request);

            return $this->applyCursorPagination($query, $request);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to load my activity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.my_activity',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function guardExternalAuditorAccess(Task $task, User $user): void
    {
        if (! in_array($task->status->value, [TaskStatus::Completed->value, TaskStatus::Cancelled->value], true)) {
            abort(403, 'External auditors can only view completed or cancelled tasks.');
        }

        $taskDeptId = $task->initiator?->currentPositionAssignment?->position?->department_id;

        $hasGrant = AuditGrant::where('external_auditor_user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('date_range_start', '<=', now())
            ->where('date_range_end', '>=', now())
            ->where(function ($q) use ($taskDeptId) {
                $q->whereNull('department_id')
                    ->orWhere('department_id', $taskDeptId);
            })
            ->exists();

        if (! $hasGrant) {
            abort(403, 'No active audit grant covers this task.');
        }
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('user_id')) {
            $userId = User::where('public_id', $request->input('user_id'))->value('id');
            if ($userId) {
                $query->where('user_id', $userId);
            }
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('entity_type')) {
            $type = AuditEntityType::tryFrom($request->integer('entity_type'));
            if ($type) {
                $query->where('entity_type', $type);
            }
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }
    }

    private function applyCursorPagination($query, Request $request): CursorPaginator
    {
        $perPage = min(100, max(1, $request->integer('per_page', 15)));

        return $query->cursorPaginate($perPage);
    }
}
