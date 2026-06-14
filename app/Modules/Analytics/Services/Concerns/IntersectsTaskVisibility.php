<?php

namespace App\Modules\Analytics\Services\Concerns;

use App\Models\User;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

trait IntersectsTaskVisibility
{
    protected function baseTaskQuery(User $user): Builder
    {
        $scope = app(TaskVisibilityScope::class);

        $query = Task::query()
            ->where('tasks.status', '!=', TaskStatus::Draft)
            ->whereNull('tasks.archived_at')
            ->whereNull('tasks.deleted_at');

        return $scope->apply($query, $user);
    }

    protected function applyDateRange(Builder $query, array $filters, string $column = 'created_at'): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where($column, '<=', $filters['date_to']);
        }

        return $query;
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['priority_id'])) {
            $ids = (array) $filters['priority_id'];
            $query->whereHas('priority', fn ($q) => $q->whereIn('public_id', $ids));
        }

        if (! empty($filters['department_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereHas('stageInstances', function ($sq) use ($filters) {
                    $sq->where('status', StageInstanceStatus::Active)
                        ->whereHas('owningDepartment', fn ($dq) => $dq->where('public_id', $filters['department_id']));
                })->orWhereHas('stageInstances', function ($sq) use ($filters) {
                    $sq->where('status', StageInstanceStatus::Active)
                        ->whereHas('subStageInstances', function ($ssq) use ($filters) {
                            $ssq->where('status', SubStageInstanceStatus::Active)
                                ->whereHas('owningDepartment', fn ($dq) => $dq->where('public_id', $filters['department_id']));
                        });
                });
            });
        }

        if (! empty($filters['blueprint_category_id'])) {
            $query->whereHas('blueprint', fn ($q) => $q->whereHas('category', fn ($cq) => $cq->where('public_id', $filters['blueprint_category_id'])));
        }
    }

    protected function cacheKey(string $suffix): string
    {
        return sprintf('%s:analytics:%s', tenant()->slug, $suffix);
    }

    protected function trackCacheKey(string $group, string $cacheKey): void
    {
        $slug = tenant()->slug;
        $listKey = "{$slug}:analytics:keys:{$group}";

        $keys = Cache::get($listKey, []);
        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::forever($listKey, $keys);
        }
    }
}
