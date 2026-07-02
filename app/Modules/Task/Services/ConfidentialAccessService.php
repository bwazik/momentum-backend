<?php

namespace App\Modules\Task\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use App\Modules\Task\Events\ConfidentialContentOverridden;
use App\Modules\Task\Events\ConfidentialMetadataViewed;
use App\Modules\Task\Exceptions\ConfidentialAccessDeniedException;
use App\Modules\Task\Exceptions\TaskNotConfidentialException;
use App\Modules\Task\Models\ConfidentialAccessEvent;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfidentialAccessService
{
    public function __construct(
        private IamPolicy $iamPolicy,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function metadata(Task $task, User $user): array
    {
        try {
            return DB::transaction(function () use ($task, $user) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                if ($this->hasFullVisibility($task, $user)) {
                    abort(404, 'Use the normal task endpoint.');
                }

                $taskDeptId = $this->taskDepartmentId($task);
                if (! $this->iamPolicy->check($user, 'task.confidential.view_metadata', ScopeType::SPECIFIC_DEPARTMENT, $taskDeptId)
                    && ! $this->iamPolicy->check($user, 'task.confidential.view_metadata', ScopeType::TENANT)) {
                    throw new ConfidentialAccessDeniedException;
                }

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'access_type' => ConfidentialAccessEventType::MetadataView,
                ]);

                event(new ConfidentialMetadataViewed($task, $user));

                $settings = tenant()?->settings['confidentiality'] ?? [];
                $showActualTitle = $settings['metadata_show_actual_title'] ?? false;

                return [
                    'public_id' => $task->public_id,
                    'classification_level' => $task->classification_level,
                    'title' => $showActualTitle
                        ? ($task->title_en ?? $task->title_ar)
                        : __('task.confidential_redacted_title'),
                    'owning_department' => $task->stageInstances()->first()?->owningDepartment?->only('public_id', 'name_ar', 'name_en'),
                    'current_responsible_position' => $task->stageInstances()->first()?->assignments()->first()?->position?->only('public_id', 'title_ar', 'title_en'),
                    'status' => $task->status,
                    'due_date' => $task->due_date?->toDateString(),
                    'sla_health' => null,
                    'metadata_only' => true,
                ];
            });
        } catch (TaskNotConfidentialException|ConfidentialAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to view confidential metadata', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential.metadata_view',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function override(Task $task, string $reason, User $user): Task
    {
        try {
            return DB::transaction(function () use ($task, $reason, $user) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                $taskDeptId = $this->taskDepartmentId($task);
                if (! $this->iamPolicy->check($user, 'task.confidential.view_override', ScopeType::SPECIFIC_DEPARTMENT, $taskDeptId)
                    && ! $this->iamPolicy->check($user, 'task.confidential.view_override', ScopeType::TENANT)) {
                    throw new ConfidentialAccessDeniedException;
                }

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'access_type' => ConfidentialAccessEventType::ContentOverride,
                    'reason' => $reason,
                ]);

                event(new ConfidentialContentOverridden($task, $user, $reason));

                return $task->load([
                    'priority', 'blueprint.category', 'initiator',
                    'stageInstances.assignments.user',
                    'stageInstances.subStageInstances',
                ]);
            });
        } catch (TaskNotConfidentialException|ConfidentialAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to override confidential access', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential.access_override',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function guardCanClassify(User $user): void
    {
        if (! $this->iamPolicy->hasCapability($user, 'task.classify.confidential')) {
            abort(403, 'Missing task.classify.confidential capability.');
        }
    }

    private function hasFullVisibility(Task $task, User $user): bool
    {
        return $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();
    }

    private function taskDepartmentId(Task $task): ?int
    {
        return $task->stageInstances()->first()?->owning_department_id
            ?? $task->initiator?->currentPositionAssignment?->position?->department_id;
    }
}
