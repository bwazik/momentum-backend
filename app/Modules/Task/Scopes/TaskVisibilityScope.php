<?php

namespace App\Modules\Task\Scopes;

use App\Enums\AccountType;
use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\ConfidentialGovernanceParticipantService;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class TaskVisibilityScope
{
    public function __construct(
        private IamPolicy $iamPolicy,
        private ConfidentialGovernanceParticipantService $governanceService,
    ) {}

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function apply(Builder $query, User $user): Builder
    {
        $this->applyBaseVisibility($query, $user);

        return $this->applyClassificationFilter($query, $user);
    }

    private function applyBaseVisibility(Builder $query, User $user): void
    {
        if ($this->iamPolicy->hasCapability($user, 'task.view.organization')) {
            return;
        }

        $userDeptId = $user->currentPositionAssignment?->position?->department_id;

        $query->where(function (Builder $q) use ($user, $userDeptId) {
            $q->where('initiator_user_id', $user->id);

            $q->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));

            $q->orWhereHas('activeConfidentialParticipants', fn (Builder $pq) => $pq->where('user_id', $user->id));

            if ($this->iamPolicy->hasCapability($user, 'task.view.department_touched') && $userDeptId) {
                $q->orWhereHas('stageInstances', fn (Builder $sq) => $sq->where('owning_department_id', $userDeptId));
            }

            if ($this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope')) {
                $monitoredDeptIds = $user->monitoringScopeGrants()
                    ->where('scope_type', ScopeType::SPECIFIC_DEPARTMENT)
                    ->whereNull('revoked_at')
                    ->pluck('scope_department_id');

                if ($monitoredDeptIds->isNotEmpty()) {
                    $q->orWhereHas('stageInstances', fn (Builder $sq) => $sq->whereIn('owning_department_id', $monitoredDeptIds));
                }
            }

            $this->applyGovernanceBaseVisibility($q, $user);
        });
    }

    private function applyGovernanceBaseVisibility(Builder $query, User $user): void
    {
        $primaryPositionId = $user->currentPositionAssignment?->position_id;

        if (! $primaryPositionId) {
            return;
        }

        $allConfigs = $this->governanceService->allActive();
        $configs = collect($allConfigs)->filter(fn ($c) => $c->position_id === $primaryPositionId
            && $c->applies_to_classification_level === ClassificationLevel::Confidential);

        if ($configs->isEmpty()) {
            return;
        }

        $query->orWhere(function (Builder $gov) use ($configs) {
            $gov->where('classification_level', ClassificationLevel::Confidential->value);

            $hasTenantScope = $configs->contains(fn ($c) => $c->scope_type === ScopeType::TENANT);
            $departmentIds = $configs->whereIn('scope_type', [ScopeType::SPECIFIC_DEPARTMENT, ScopeType::DEPARTMENT_TREE])
                ->pluck('scope_department_id')->filter()->unique()->values()->all();
            $categoryIds = $configs->pluck('blueprint_category_id')->filter()->unique()->values()->all();

            if ($hasTenantScope && empty($categoryIds)) {
                $gov->whereRaw('1 = 1');

                return;
            }

            if (! $hasTenantScope && ! empty($departmentIds)) {
                $gov->whereHas('stageInstances', fn (Builder $sq) => $sq->whereIn('owning_department_id', $departmentIds));
            }

            if (! empty($categoryIds)) {
                $gov->whereHas('blueprint', fn (Builder $bq) => $bq->whereIn('category_id', $categoryIds));
            }
        });
    }

    private function applyClassificationFilter(Builder $query, User $user): Builder
    {
        if ($user->isTenantAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            // Public tasks: already filtered by base visibility.
            $q->where('classification_level', ClassificationLevel::Public->value);

            // Internal tasks: allow if user has org-wide, is participant, or task touched allowed scope.
            $q->orWhere(function (Builder $internal) use ($user) {
                $internal->where('classification_level', ClassificationLevel::Internal->value);

                if (! $this->iamPolicy->hasCapability($user, 'task.view.organization')) {
                    $internal->where(function (Builder $allow) use ($user) {
                        $allow->where('initiator_user_id', $user->id)
                            ->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));

                        $userDeptId = $user->currentPositionAssignment?->position?->department_id;
                        if ($userDeptId) {
                            $allow->orWhereHas('stageInstances', fn (Builder $sq) => $sq->where('owning_department_id', $userDeptId));
                        }
                    });
                }
            });

            // Confidential tasks: strict allow list.
            $q->orWhere(function (Builder $conf) use ($user) {
                $conf->where('classification_level', ClassificationLevel::Confidential->value)
                    ->where(function (Builder $allow) use ($user) {
                        $allow->where('initiator_user_id', $user->id)
                            ->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id))
                            ->orWhereHas('activeConfidentialParticipants', fn (Builder $pq) => $pq->where('user_id', $user->id));

                        $this->applyGovernanceAccess($allow, $user);
                        $this->applyExternalAuditorAccess($allow, $user);
                    });
            });
        });
    }

    private function applyGovernanceAccess(Builder $query, User $user): void
    {
        $primaryPositionId = $user->currentPositionAssignment?->position_id;

        if (! $primaryPositionId) {
            return;
        }

        $allConfigs = $this->governanceService->allActive();
        $configs = collect($allConfigs)->filter(fn ($c) => $c->position_id === $primaryPositionId
            && $c->applies_to_classification_level === ClassificationLevel::Confidential);

        if ($configs->isEmpty()) {
            return;
        }

        $hasTenantScope = $configs->contains(fn ($c) => $c->scope_type === ScopeType::TENANT);
        $departmentIds = $configs->whereIn('scope_type', [ScopeType::SPECIFIC_DEPARTMENT, ScopeType::DEPARTMENT_TREE])
            ->pluck('scope_department_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $categoryIds = $configs->pluck('blueprint_category_id')->filter()->unique()->values()->all();

        $query->orWhere(function (Builder $governance) use ($hasTenantScope, $departmentIds, $categoryIds) {
            if ($hasTenantScope && empty($categoryIds)) {
                $governance->whereRaw('1 = 1');

                return;
            }

            if (! $hasTenantScope && ! empty($departmentIds)) {
                $governance->whereHas('stageInstances', function (Builder $sq) use ($departmentIds) {
                    $sq->whereIn('owning_department_id', $departmentIds);
                });
            }

            if (! empty($categoryIds)) {
                $governance->whereHas('blueprint', function (Builder $bq) use ($categoryIds) {
                    $bq->whereIn('category_id', $categoryIds);
                });
            }
        });
    }

    private function applyExternalAuditorAccess(Builder $query, User $user): void
    {
        if ($user->account_type !== AccountType::EXTERNAL_AUDITOR) {
            return;
        }

        $grantDeptIds = $this->iamPolicy->getAuditGrantDepartmentIds($user);

        if ($grantDeptIds === []) {
            return;
        }

        if ($grantDeptIds === null) {
            $query->orWhere(function (Builder $auditor) {
                $auditor->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
                    ->orWhereNotNull('archived_at');
            });

            return;
        }

        $query->orWhere(function (Builder $auditor) use ($grantDeptIds) {
            $auditor->where(function (Builder $statusQ) {
                $statusQ->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
                    ->orWhereNotNull('archived_at');
            })->whereHas('stageInstances', function (Builder $sq) use ($grantDeptIds) {
                $sq->whereIn('owning_department_id', $grantDeptIds);
            });
        });
    }
}
