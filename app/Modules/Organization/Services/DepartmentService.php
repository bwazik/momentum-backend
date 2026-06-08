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
use Illuminate\Database\Eloquent\Collection;

class DepartmentService
{
    public function create(array $data): Department
    {
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
    }

    public function update(Department $department, array $data): Department
    {
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
    }

    public function deactivate(Department $department, bool $cascadeToChildren = false): Department
    {
        $department->update(['is_active' => false]);

        if ($cascadeToChildren) {
            $this->deactivateChildren($department);
        }

        event(new DepartmentDeactivated($department));

        return $department->fresh();
    }

    public function reactivate(Department $department): Department
    {
        $department->update(['is_active' => true]);

        event(new DepartmentReactivated($department));

        return $department->fresh();
    }

    public function delete(Department $department): void
    {
        if ($department->children()->exists()) {
            throw new DepartmentHasChildrenException;
        }

        if ($department->activePositions()->exists()) {
            throw new DepartmentHasActivePositionsException;
        }

        $department->positions()->update(['is_active' => false]);

        $department->delete();
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
