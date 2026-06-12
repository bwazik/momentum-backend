<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Events\TaskPriorityCreated;
use App\Modules\Task\Events\TaskPriorityUpdated;
use App\Modules\Task\Models\TaskPriority;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskPriorityService
{
    use AuthenticatedUser;

    public function getAll(): Collection
    {
        $tenantSlug = tenant()?->slug ?? 'central';

        return Cache::remember("{$tenantSlug}:task:priorities:all", 300, function () {
            return TaskPriority::active()->orderBy('display_order')->get();
        });
    }

    public function create(array $data): TaskPriority
    {
        try {
            return DB::transaction(function () use ($data) {
                if (! empty($data['is_default']) && $data['is_default']) {
                    TaskPriority::where('is_default', true)->update(['is_default' => false]);
                }

                $priority = TaskPriority::create([
                    'name_ar' => $data['name_ar'],
                    'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                    'severity_rank' => $data['severity_rank'],
                    'color_code' => $data['color_code'] ?? null,
                    'is_default' => $data['is_default'] ?? false,
                    'is_active' => true,
                    'display_order' => $data['display_order'] ?? 0,
                ]);

                $this->clearCache();
                event(new TaskPriorityCreated($priority));

                return $priority;
            });
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create task priority', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task_priority.create',
                'entity_type' => 'task_priority',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(TaskPriority $priority, array $data): TaskPriority
    {
        try {
            return DB::transaction(function () use ($priority, $data) {
                if (isset($data['is_default']) && $data['is_default'] && ! $priority->is_default) {
                    TaskPriority::where('is_default', true)->update(['is_default' => false]);
                }

                $priority->update([
                    'name_ar' => $data['name_ar'] ?? $priority->name_ar,
                    'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $priority->name_en),
                    'severity_rank' => $data['severity_rank'] ?? $priority->severity_rank,
                    'color_code' => $data['color_code'] ?? $priority->color_code,
                    'is_default' => $data['is_default'] ?? $priority->is_default,
                    'display_order' => $data['display_order'] ?? $priority->display_order,
                ]);

                $this->clearCache();
                event(new TaskPriorityUpdated($priority));

                return $priority->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to update task priority', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task_priority.update',
                'entity_type' => 'task_priority',
                'entity_id' => $priority->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(TaskPriority $priority): TaskPriority
    {
        try {
            return DB::transaction(function () use ($priority) {
                if ($priority->is_default) {
                    $priority->update(['is_default' => false, 'is_active' => false]);
                } else {
                    $priority->update(['is_active' => false]);
                }
                $this->clearCache();

                return $priority->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to deactivate task priority', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task_priority.deactivate',
                'entity_type' => 'task_priority',
                'entity_id' => $priority->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(TaskPriority $priority): TaskPriority
    {
        try {
            $priority->update(['is_active' => true]);
            $this->clearCache();

            return $priority->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to reactivate task priority', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'task_priority.reactivate',
                'entity_type' => 'task_priority',
                'entity_id' => $priority->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:task:priorities:all");
    }
}
