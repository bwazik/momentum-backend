<?php

namespace App\Modules\Blueprint\Services;

use App\Modules\Blueprint\Events\StageCreated;
use App\Modules\Blueprint\Events\StageDeleted;
use App\Modules\Blueprint\Events\StageReordered;
use App\Modules\Blueprint\Events\StageUpdated;
use App\Modules\Blueprint\Exceptions\BlueprintLockedException;
use App\Modules\Blueprint\Exceptions\StageInUseException;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Models\TaskStageInstance;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BlueprintStageService
{
    use AuthenticatedUser;

    public function create(Blueprint $blueprint, array $data): BlueprintStage
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $sequenceOrder = $data['sequence_order'] ?? ($blueprint->stages()->max('sequence_order') + 1);

            $stageTypeId = StageType::where('public_id', $data['stage_type_id'])->value('id');
            $slaPolicyId = ! empty($data['sla_policy_id'])
                ? SlaPolicy::where('public_id', $data['sla_policy_id'])->value('id')
                : null;
            $assignedPositionId = ! empty($data['assigned_position_id'])
                ? Position::where('public_id', $data['assigned_position_id'])->value('id')
                : null;
            $assignedDepartmentId = ! empty($data['assigned_department_id'])
                ? Department::where('public_id', $data['assigned_department_id'])->value('id')
                : null;
            $escalationPositionId = ! empty($data['escalation_position_id'])
                ? Position::where('public_id', $data['escalation_position_id'])->value('id')
                : null;

            $stage = BlueprintStage::create([
                'blueprint_id' => $blueprint->id,
                'stage_type_id' => $stageTypeId,
                'sla_policy_id' => $slaPolicyId,
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? null),
                'sequence_order' => $sequenceOrder,
                'assignment_type' => $data['assignment_type'],
                'assigned_position_id' => $assignedPositionId,
                'assigned_department_id' => $assignedDepartmentId,
                'assignment_cardinality' => $data['assignment_cardinality'] ?? 1,
                'completion_rule' => $data['completion_rule'] ?? 1,
                'escalation_position_id' => $escalationPositionId,
            ]);

            $this->clearCache($blueprint);
            event(new StageCreated($stage));

            return $stage;
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.create',
                'entity_type' => 'stage',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Blueprint $blueprint, BlueprintStage $stage, array $data): BlueprintStage
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $updateData = [
                'name_ar' => $data['name_ar'] ?? $stage->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $stage->name_ar),
                'description_ar' => $data['description_ar'] ?? $stage->description_ar,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? $stage->description_ar),
                'assignment_type' => $data['assignment_type'] ?? $stage->assignment_type,
                'assignment_cardinality' => $data['assignment_cardinality'] ?? $stage->assignment_cardinality,
                'completion_rule' => $data['completion_rule'] ?? $stage->completion_rule,
            ];

            if (array_key_exists('stage_type_id', $data)) {
                $updateData['stage_type_id'] = ! empty($data['stage_type_id'])
                    ? StageType::where('public_id', $data['stage_type_id'])->value('id')
                    : null;
            }

            if (array_key_exists('sla_policy_id', $data)) {
                $updateData['sla_policy_id'] = ! empty($data['sla_policy_id'])
                    ? SlaPolicy::where('public_id', $data['sla_policy_id'])->value('id')
                    : null;
            }

            if (array_key_exists('assigned_position_id', $data)) {
                $updateData['assigned_position_id'] = ! empty($data['assigned_position_id'])
                    ? Position::where('public_id', $data['assigned_position_id'])->value('id')
                    : null;
            }

            if (array_key_exists('assigned_department_id', $data)) {
                $updateData['assigned_department_id'] = ! empty($data['assigned_department_id'])
                    ? Department::where('public_id', $data['assigned_department_id'])->value('id')
                    : null;
            }

            if (array_key_exists('escalation_position_id', $data)) {
                $updateData['escalation_position_id'] = ! empty($data['escalation_position_id'])
                    ? Position::where('public_id', $data['escalation_position_id'])->value('id')
                    : null;
            }

            $stage->update($updateData);

            $this->clearCache($blueprint);
            event(new StageUpdated($stage));

            return $stage->fresh();
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.update',
                'entity_type' => 'stage',
                'entity_id' => $stage->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(Blueprint $blueprint, BlueprintStage $stage): void
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            if (TaskStageInstance::where('blueprint_stage_id', $stage->id)->exists()) {
                throw new StageInUseException;
            }

            $stage->delete();

            $this->clearCache($blueprint);
            event(new StageDeleted($stage));
        } catch (BlueprintLockedException|StageInUseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.delete',
                'entity_type' => 'stage',
                'entity_id' => $stage->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reorder(Blueprint $blueprint, array $stages): void
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            DB::transaction(function () use ($stages) {
                foreach ($stages as $item) {
                    BlueprintStage::where('public_id', $item['public_id'])
                        ->update(['sequence_order' => -(1000 + (int) $item['sequence_order'])]);
                }
                foreach ($stages as $item) {
                    BlueprintStage::where('public_id', $item['public_id'])
                        ->update(['sequence_order' => (int) $item['sequence_order']]);
                }
            });

            $this->clearCache($blueprint);
            event(new StageReordered($blueprint));
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to reorder stages', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage.reorder',
                'entity_type' => 'stage',
                'entity_id' => $blueprint->public_id,
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
