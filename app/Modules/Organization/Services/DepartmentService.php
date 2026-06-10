<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Events\DepartmentCreated;
use App\Modules\Organization\Events\DepartmentDeactivated;
use App\Modules\Organization\Events\DepartmentReactivated;
use App\Modules\Organization\Events\DepartmentUpdated;
use App\Modules\Organization\Exceptions\CircularDepartmentReferenceException;
use App\Modules\Organization\Exceptions\DepartmentHasActivePositionsException;
use App\Modules\Organization\Exceptions\DepartmentHasChildrenException;
use App\Modules\Organization\Models\Department;
use App\Traits\AuthenticatedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepartmentService
{
    use AuthenticatedUser;

    public function create(array $data): Department
    {
        try {
            $parentInternalId = null;

            if (! empty($data['parent_department_id'])) {
                $parent = Department::where('public_id', $data['parent_department_id'])
                    ->where('is_active', true)
                    ->firstOrFail();
                $parentInternalId = $parent->id;
            }

            $department = Department::create([
                'parent_department_id' => $parentInternalId,
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'is_active' => true,
            ]);

            event(new DepartmentCreated($department));

            return $department;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to create department', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.create',
                'entity_type' => 'department',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Department $department, array $data): Department
    {
        try {
            return DB::transaction(function () use ($department, $data) {
                if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
                    $data['name_en'] = $data['name_ar'] ?? $department->name_ar;
                }

                if (array_key_exists('parent_department_id', $data)) {
                    if (! empty($data['parent_department_id'])) {
                        $parent = Department::where('public_id', $data['parent_department_id'])
                            ->where('is_active', true)
                            ->firstOrFail();
                        $data['parent_department_id'] = $parent->id;

                        if ($this->wouldCreateCircularReference($department, $parent->id)) {
                            throw new CircularDepartmentReferenceException;
                        }
                    } else {
                        $data['parent_department_id'] = null;
                    }
                }

                $department->update($data);

                event(new DepartmentUpdated($department));

                return $department->fresh();
            });
        } catch (CircularDepartmentReferenceException $e) {
            Log::channel('organization')->warning('Circular department reference detected', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.update',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to update department', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.update',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(Department $department, bool $cascadeToChildren = false): Department
    {
        try {
            return DB::transaction(function () use ($department, $cascadeToChildren) {
                $department->update(['is_active' => false]);

                if ($cascadeToChildren) {
                    $this->deactivateChildren($department);
                }

                event(new DepartmentDeactivated($department));

                return $department->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to deactivate department', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.deactivate',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(Department $department): Department
    {
        try {
            $department->update(['is_active' => true]);

            event(new DepartmentReactivated($department));

            return $department->fresh();
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to reactivate department', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.reactivate',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(Department $department): void
    {
        try {
            DB::transaction(function () use ($department) {
                if ($department->children()->exists()) {
                    throw new DepartmentHasChildrenException;
                }

                if ($department->activePositions()->exists()) {
                    throw new DepartmentHasActivePositionsException;
                }

                $department->positions()->update(['is_active' => false]);

                $department->delete();
            });
        } catch (DepartmentHasChildrenException|DepartmentHasActivePositionsException $e) {
            Log::channel('organization')->warning('Failed to delete department', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.delete',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('organization')->error('Failed to delete department', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'department.delete',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getTree(): Collection
    {
        return Department::with('children.children.children.children.children')
            ->whereNull('parent_department_id')
            ->get();
    }

    private function deactivateChildren(Department $department): void
    {
        foreach ($department->children as $child) {
            $child->update(['is_active' => false]);
            $this->deactivateChildren($child);
        }
    }

    private function wouldCreateCircularReference(Department $department, int $newParentId): bool
    {
        if ($newParentId === $department->id) {
            return true;
        }

        $currentId = $newParentId;
        $visited = [$department->id => true];

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;
            $currentId = Department::where('id', $currentId)->value('parent_department_id');
        }

        return false;
    }
}
