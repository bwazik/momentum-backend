<?php

namespace App\Modules\Blueprint\Services;

use App\Modules\Blueprint\Events\SubStageCreated;
use App\Modules\Blueprint\Events\SubStageDeleted;
use App\Modules\Blueprint\Events\SubStageReordered;
use App\Modules\Blueprint\Events\SubStageUpdated;
use App\Modules\Blueprint\Exceptions\BlueprintLockedException;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BlueprintSubStageService
{
    use AuthenticatedUser;

    public function create(Blueprint $blueprint, BlueprintStage $stage, array $data): BlueprintSubStage
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $sequenceOrder = $data['sequence_order'] ?? ($stage->subStages()->max('sequence_order') + 1);

            $slaPolicyId = ! empty($data['sla_policy_id'])
                ? SlaPolicy::where('public_id', $data['sla_policy_id'])->value('id')
                : null;
            $assignedPositionId = ! empty($data['assigned_position_id'])
                ? Position::where('public_id', $data['assigned_position_id'])->value('id')
                : null;
            $assignedDepartmentId = ! empty($data['assigned_department_id'])
                ? Department::where('public_id', $data['assigned_department_id'])->value('id')
                : null;

            $subStage = BlueprintSubStage::create([
                'blueprint_stage_id' => $stage->id,
                'sla_policy_id' => $slaPolicyId,
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? null),
                'sequence_order' => $sequenceOrder,
                'is_required' => $data['is_required'] ?? true,
                'assignment_type' => $data['assignment_type'],
                'assigned_position_id' => $assignedPositionId,
                'assigned_department_id' => $assignedDepartmentId,
                'assignment_cardinality' => $data['assignment_cardinality'] ?? 1,
                'completion_rule' => $data['completion_rule'] ?? 1,
            ]);

            $this->clearCache($blueprint);
            event(new SubStageCreated($subStage));

            return $subStage;
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.create',
                'entity_type' => 'sub_stage',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Blueprint $blueprint, BlueprintStage $stage, BlueprintSubStage $subStage, array $data): BlueprintSubStage
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $updateData = [
                'name_ar' => $data['name_ar'] ?? $subStage->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $subStage->name_ar),
                'description_ar' => $data['description_ar'] ?? $subStage->description_ar,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? $subStage->description_ar),
                'sequence_order' => $data['sequence_order'] ?? $subStage->sequence_order,
                'is_required' => $data['is_required'] ?? $subStage->is_required,
                'assignment_type' => $data['assignment_type'] ?? $subStage->assignment_type,
                'assignment_cardinality' => $data['assignment_cardinality'] ?? $subStage->assignment_cardinality,
                'completion_rule' => $data['completion_rule'] ?? $subStage->completion_rule,
            ];

            if (isset($data['sla_policy_id'])) {
                $updateData['sla_policy_id'] = ! empty($data['sla_policy_id'])
                    ? SlaPolicy::where('public_id', $data['sla_policy_id'])->value('id')
                    : null;
            }

            if (isset($data['assigned_position_id'])) {
                $updateData['assigned_position_id'] = ! empty($data['assigned_position_id'])
                    ? Position::where('public_id', $data['assigned_position_id'])->value('id')
                    : null;
            }

            if (isset($data['assigned_department_id'])) {
                $updateData['assigned_department_id'] = ! empty($data['assigned_department_id'])
                    ? Department::where('public_id', $data['assigned_department_id'])->value('id')
                    : null;
            }

            $subStage->update($updateData);

            $this->clearCache($blueprint);
            event(new SubStageUpdated($subStage));

            return $subStage->fresh();
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.update',
                'entity_type' => 'sub_stage',
                'entity_id' => $subStage->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(Blueprint $blueprint, BlueprintStage $stage, BlueprintSubStage $subStage): void
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $subStage->delete();

            $this->clearCache($blueprint);
            event(new SubStageDeleted($subStage));
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.delete',
                'entity_type' => 'sub_stage',
                'entity_id' => $subStage->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reorder(Blueprint $blueprint, BlueprintStage $stage, array $subStages): void
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            DB::transaction(function () use ($subStages) {
                foreach ($subStages as $item) {
                    BlueprintSubStage::where('public_id', $item['public_id'])
                        ->update(['sequence_order' => $item['sequence_order']]);
                }
            });

            $this->clearCache($blueprint);
            event(new SubStageReordered($stage));
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to reorder sub-stages', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sub_stage.reorder',
                'entity_type' => 'sub_stage',
                'entity_id' => $stage->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(Blueprint $blueprint): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:blueprint:{$blueprint->public_id}:structure");
    }
}
