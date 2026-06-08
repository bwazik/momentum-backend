<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Events\PositionCreated;
use App\Modules\Organization\Events\PositionDeactivated;
use App\Modules\Organization\Events\PositionReactivated;
use App\Modules\Organization\Events\PositionTransferred;
use App\Modules\Organization\Events\PositionUpdated;
use App\Modules\Organization\Exceptions\CircularReportingLineException;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;

class PositionService
{
    public function create(array $data): Position
    {
        $department = Department::where('public_id', $data['department_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $authorityGrade = AuthorityGrade::where('public_id', $data['authority_grade_id'])->firstOrFail();

        $reportsToId = null;
        if (! empty($data['reports_to_position_id'])) {
            $reportsTo = Position::where('public_id', $data['reports_to_position_id'])
                ->where('is_active', true)
                ->firstOrFail();
            $reportsToId = $reportsTo->id;
        }

        $isHead = $data['is_department_head'] ?? false;

        if ($isHead) {
            Position::where('department_id', $department->id)
                ->where('is_department_head', true)
                ->update(['is_department_head' => false]);
        }

        $position = Position::create([
            'department_id' => $department->id,
            'title_ar' => $data['title_ar'],
            'title_en' => ! empty($data['title_en']) ? $data['title_en'] : $data['title_ar'],
            'reports_to_position_id' => $reportsToId,
            'authority_grade_id' => $authorityGrade->id,
            'is_department_head' => $isHead,
            'is_active' => true,
        ]);

        event(new PositionCreated($position));

        return $position;
    }

    public function update(Position $position, array $data): Position
    {
        if (array_key_exists('name_en', $data) && empty($data['title_en'])) {
            $data['title_en'] = $data['title_ar'] ?? $position->title_ar;
        }

        if (array_key_exists('reports_to_position_id', $data)) {
            if (! empty($data['reports_to_position_id'])) {
                $reportsTo = Position::where('public_id', $data['reports_to_position_id'])
                    ->where('is_active', true)
                    ->firstOrFail();
                $data['reports_to_position_id'] = $reportsTo->id;

                if ($this->wouldCreateCircularReportingLine($position, $reportsTo->id)) {
                    throw new CircularReportingLineException;
                }
            } else {
                $data['reports_to_position_id'] = null;
            }
        }

        if (array_key_exists('authority_grade_id', $data)) {
            $grade = AuthorityGrade::where('public_id', $data['authority_grade_id'])->firstOrFail();
            $data['authority_grade_id'] = $grade->id;
        }

        if (array_key_exists('department_id', $data)) {
            $dept = Department::where('public_id', $data['department_id'])
                ->where('is_active', true)
                ->firstOrFail();
            $data['department_id'] = $dept->id;
        }

        $isHead = $data['is_department_head'] ?? $position->is_department_head;
        if ($isHead && ! $position->is_department_head) {
            Position::where('department_id', $position->department_id)
                ->where('id', '!=', $position->id)
                ->where('is_department_head', true)
                ->update(['is_department_head' => false]);
        }

        $position->update($data);

        event(new PositionUpdated($position));

        return $position->fresh();
    }

    public function transfer(Position $position, string $newDepartmentPublicId): Position
    {
        $department = Department::where('public_id', $newDepartmentPublicId)
            ->where('is_active', true)
            ->firstOrFail();

        $updateData = ['department_id' => $department->id];

        if ($position->is_department_head) {
            $updateData['is_department_head'] = false;
        }

        $position->update($updateData);

        event(new PositionTransferred($position->fresh()));

        return $position->fresh();
    }

    public function deactivate(Position $position): Position
    {
        $position->update(['is_active' => false]);

        event(new PositionDeactivated($position));

        return $position->fresh();
    }

    public function reactivate(Position $position): Position
    {
        $position->update(['is_active' => true]);

        event(new PositionReactivated($position));

        return $position->fresh();
    }

    public function delete(Position $position): void
    {
        $position->delete();
    }

    private function wouldCreateCircularReportingLine(Position $position, int $newReportsToId): bool
    {
        if ($newReportsToId === $position->id) {
            return true;
        }

        $currentId = $newReportsToId;
        $visited = [$position->id => true];

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;
            $currentId = Position::where('id', $currentId)->value('reports_to_position_id');
        }

        return false;
    }
}
