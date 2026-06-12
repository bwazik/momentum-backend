<?php

namespace App\Modules\Task\Scopes;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class TaskVisibilityScope
{
    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($this->iamPolicy->hasCapability($user, 'task.view.organization')) {
            return $this->applyConfidentialFilter($query, $user);
        }

        $userDeptId = $user->currentPositionAssignment?->position?->department_id;

        $query->where(function (Builder $q) use ($user, $userDeptId) {
            $q->where('initiator_user_id', $user->id);

            $q->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id)
            );

            if ($this->iamPolicy->hasCapability($user, 'task.view.department_touched') && $userDeptId) {
                $q->orWhereHas('stageInstances', fn (Builder $sq) => $sq->where('owning_department_id', $userDeptId)
                );
            }

            if ($this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope')) {
                $monitoredDeptIds = $user->monitoringScopeGrants()
                    ->where('scope_type', ScopeType::SPECIFIC_DEPARTMENT)
                    ->whereNull('revoked_at')
                    ->pluck('scope_department_id');

                if ($monitoredDeptIds->isNotEmpty()) {
                    $q->orWhereHas('stageInstances', fn (Builder $sq) => $sq->whereIn('owning_department_id', $monitoredDeptIds)
                    );
                }
            }
        });

        return $this->applyConfidentialFilter($query, $user);
    }

    private function applyConfidentialFilter(Builder $query, User $user): Builder
    {
        if ($this->iamPolicy->hasCapability($user, 'task.confidential.view_metadata')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('classification_level', '!=', ClassificationLevel::Confidential->value)
                ->orWhere('initiator_user_id', $user->id)
                ->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id)
                );
        });
    }
}
