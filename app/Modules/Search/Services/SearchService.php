<?php

namespace App\Modules\Search\Services;

use App\Models\User;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Organization\Models\Department;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Search\Exceptions\ExternalReferenceSearchNotAvailableException;
use App\Modules\Search\Exceptions\SearchQueryTooShortException;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SearchService
{
    use IntersectsTaskVisibility;

    public function __construct(
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function searchTasks(User $user, array $filters): CursorPaginator
    {
        try {
            $query = $this->baseTaskQuery($user)
                ->with([
                    'priority',
                    'blueprint.category',
                    'stageInstances.blueprintStage.stageType',
                    'stageInstances.owningDepartment',
                ])
                ->leftJoin('task_search_index', 'task_search_index.task_id', '=', 'tasks.id');

            if (DB::connection()->getDriverName() === 'pgsql') {
                $tsqueryAr = $this->toTsquery($filters['q'], 'simple');
                $tsqueryEn = $this->toTsquery($filters['q'], 'english');

                $query->selectRaw("tasks.*, GREATEST(
                    COALESCE(ts_rank(tasks.search_vector_ar, to_tsquery('simple', ?)), 0),
                    COALESCE(ts_rank(tasks.search_vector_en, to_tsquery('english', ?)), 0),
                    COALESCE(ts_rank(task_search_index.search_vector_notes_ar, to_tsquery('simple', ?)), 0),
                    COALESCE(ts_rank(task_search_index.search_vector_notes_en, to_tsquery('english', ?)), 0)
                ) as combined_rank", [$tsqueryAr, $tsqueryEn, $tsqueryAr, $tsqueryEn])
                    ->selectRaw("ts_headline('simple', coalesce(tasks.title_ar,'') || ' ' || coalesce(tasks.description_ar,'') || ' ' || coalesce(task_search_index.notes_ar,''), to_tsquery('simple', ?), 'StartSel=<mark>,StopSel=</mark>,MaxWords=35,MinWords=15') as snippet_ar", [$tsqueryAr])
                    ->selectRaw("ts_headline('english', coalesce(tasks.title_en,'') || ' ' || coalesce(tasks.description_en,'') || ' ' || coalesce(task_search_index.notes_en,''), to_tsquery('english', ?), 'StartSel=<mark>,StopSel=</mark>,MaxWords=35,MinWords=15') as snippet_en", [$tsqueryEn])
                    ->where(function (Builder $q) use ($tsqueryAr, $tsqueryEn) {
                        $q->whereRaw('tasks.search_vector_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
                            ->orWhereRaw('tasks.search_vector_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn])
                            ->orWhereRaw('task_search_index.search_vector_notes_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
                            ->orWhereRaw('task_search_index.search_vector_notes_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn]);
                    });
            } elseif (app()->runningUnitTests()) {
                $q = $filters['q'];
                $query->selectRaw('tasks.*, 0 as combined_rank')
                    ->where(function (Builder $qry) use ($q) {
                        $qry->where('tasks.title_ar', 'like', '%'.$q.'%')
                            ->orWhere('tasks.title_en', 'like', '%'.$q.'%')
                            ->orWhere('tasks.description_ar', 'like', '%'.$q.'%')
                            ->orWhere('tasks.description_en', 'like', '%'.$q.'%')
                            ->orWhere('task_search_index.notes_ar', 'like', '%'.$q.'%')
                            ->orWhere('task_search_index.notes_en', 'like', '%'.$q.'%');
                    });
            } else {
                throw new \RuntimeException('Full-text search requires PostgreSQL.');
            }

            $this->applyStructuredFilters($query, $filters);

            $query->orderByDesc('combined_rank')
                ->orderByDesc('tasks.id');

            $perPage = $filters['per_page'] ?? 15;

            return $query->cursorPaginate($perPage);
        } catch (SearchQueryTooShortException|ExternalReferenceSearchNotAvailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to search tasks', [
                'tenant_slug' => tenant()->slug,
                'action' => 'search.tasks',
                'entity_type' => 'task',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function recentActivity(User $user): Collection
    {
        try {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $rows = DB::select(<<<'SQL'
                    SELECT task_id, activity_type, occurred_at
                    FROM (
                        SELECT DISTINCT ON (ura.task_id)
                            ura.task_id,
                            ura.activity_type,
                            ura.occurred_at
                        FROM user_recent_activity ura
                        JOIN tasks ON tasks.id = ura.task_id
                        WHERE ura.user_id = ?
                          AND tasks.deleted_at IS NULL
                        ORDER BY ura.task_id, ura.occurred_at DESC
                    ) latest
                    ORDER BY occurred_at DESC
                    LIMIT 20
                SQL, [$user->id]);
            } else {
                $rows = DB::select(<<<'SQL'
                    SELECT ura.task_id, ura.activity_type, ura.occurred_at
                    FROM user_recent_activity ura
                    JOIN tasks ON tasks.id = ura.task_id
                    WHERE ura.user_id = ?
                      AND tasks.deleted_at IS NULL
                      AND ura.occurred_at = (
                          SELECT MAX(ura2.occurred_at)
                          FROM user_recent_activity ura2
                          WHERE ura2.user_id = ura.user_id
                            AND ura2.task_id = ura.task_id
                      )
                    ORDER BY ura.occurred_at DESC
                    LIMIT 20
                SQL, [$user->id]);
            }

            if (empty($rows)) {
                return collect();
            }

            $taskIds = array_column($rows, 'task_id');

            $allowedIds = Task::whereIn('id', $taskIds)
                ->tap(fn (Builder $q) => $this->taskVisibilityScope->apply($q, $user))
                ->pluck('id')
                ->all();

            $tasks = Task::whereIn('id', $allowedIds)
                ->with(['priority'])
                ->get()
                ->keyBy('id');

            return collect($rows)
                ->filter(fn ($row) => in_array($row->task_id, $allowedIds, true))
                ->map(function ($row) use ($tasks) {
                    $task = $tasks->get($row->task_id);
                    if (! $task) {
                        return null;
                    }

                    $task->_activity_type = SearchActivityType::from($row->activity_type);
                    $task->_occurred_at = $row->occurred_at;

                    return $task;
                })
                ->filter();
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to fetch recent activity', [
                'tenant_slug' => tenant()->slug,
                'action' => 'search.recent',
                'entity_type' => 'user_recent_activity',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function toTsquery(string $q, string $config): string
    {
        $q = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q);
        $terms = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_filter($terms, fn ($t) => mb_strlen($t) >= 2);

        if (empty($terms)) {
            throw new SearchQueryTooShortException;
        }

        return implode(' & ', $terms);
    }

    private function applyStructuredFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $values = array_filter(array_map(function (string $s): ?int {
                return match ($s) {
                    'active' => TaskStatus::Active->value,
                    'suspended' => TaskStatus::Suspended->value,
                    'completed' => TaskStatus::Completed->value,
                    'cancelled' => TaskStatus::Cancelled->value,
                    default => null,
                };
            }, $statuses));
            if (! empty($values)) {
                $query->whereIn('tasks.status', $values);
            }
        }

        if (! empty($filters['priority_id'])) {
            $ids = (array) $filters['priority_id'];
            $query->whereHas('priority', fn ($q) => $q->whereIn('public_id', $ids));
        }

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $field = $filters['date_field'] ?? 'created_at';
            $column = in_array($field, ['created_at', 'completed_at'], true) ? "tasks.{$field}" : 'tasks.created_at';
            if (! empty($filters['date_from'])) {
                $query->where($column, '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->where($column, '<=', $filters['date_to']);
            }
        }

        if (! empty($filters['department_id'])) {
            $departmentId = Department::where('public_id', $filters['department_id'])->value('id');
            if ($departmentId) {
                $query->whereHas('stageInstances', function ($sq) use ($departmentId) {
                    $sq->where('status', StageInstanceStatus::Active)
                        ->where('owning_department_id', $departmentId);
                });
            }
        }

        if (! empty($filters['blueprint_id'])) {
            $blueprintId = Blueprint::where('public_id', $filters['blueprint_id'])->value('id');
            if ($blueprintId) {
                $query->where('tasks.blueprint_id', $blueprintId);
            }
        }

        if (! empty($filters['blueprint_category_id'])) {
            $categoryId = BlueprintCategory::where('public_id', $filters['blueprint_category_id'])->value('id');
            if ($categoryId) {
                $query->whereHas('blueprint', fn ($q) => $q->where('category_id', $categoryId));
            }
        }

        if (! empty($filters['external_reference'])) {
            if (! Schema::hasTable('task_external_references')) {
                throw new ExternalReferenceSearchNotAvailableException;
            }

            $referenceNumber = $filters['external_reference'];
            $query->whereExists(function ($sub) use ($referenceNumber) {
                $sub->selectRaw('1')
                    ->from('task_external_references')
                    ->whereColumn('task_external_references.task_id', 'tasks.id')
                    ->where('task_external_references.reference_number', $referenceNumber);
            });
        }
    }
}
