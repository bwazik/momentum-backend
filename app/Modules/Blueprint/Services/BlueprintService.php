<?php

namespace App\Modules\Blueprint\Services;

use App\Models\User;
use App\Modules\Blueprint\Events\BlueprintActivated;
use App\Modules\Blueprint\Events\BlueprintCreated;
use App\Modules\Blueprint\Events\BlueprintDeactivated;
use App\Modules\Blueprint\Events\BlueprintDuplicated;
use App\Modules\Blueprint\Events\BlueprintUpdated;
use App\Modules\Blueprint\Exceptions\BlueprintLockedException;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Modules\Organization\Models\Department;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BlueprintService
{
    use AuthenticatedUser;

    public function create(array $data): Blueprint
    {
        try {
            $categoryId = BlueprintCategory::where('public_id', $data['category_id'])->value('id');
            $departmentId = ! empty($data['department_id'])
                ? Department::where('public_id', $data['department_id'])->value('id')
                : null;

            $blueprint = Blueprint::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? null),
                'category_id' => $categoryId,
                'scope' => $data['scope'],
                'department_id' => $departmentId,
                'created_by_user_id' => $this->user()->id,
                'is_active' => true,
                'is_locked' => false,
            ]);

            $this->clearCache($blueprint);
            event(new BlueprintCreated($blueprint));

            return $blueprint;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create blueprint', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint.create',
                'entity_type' => 'blueprint',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Blueprint $blueprint, array $data): Blueprint
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $updateData = [
                'name_ar' => $data['name_ar'] ?? $blueprint->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $blueprint->name_ar),
                'description_ar' => $data['description_ar'] ?? $blueprint->description_ar,
                'description_en' => ! empty($data['description_en']) ? $data['description_en'] : ($data['description_ar'] ?? $blueprint->description_ar),
            ];

            if (isset($data['category_id'])) {
                $updateData['category_id'] = BlueprintCategory::where('public_id', $data['category_id'])->value('id');
            }

            if (isset($data['department_id'])) {
                $updateData['department_id'] = ! empty($data['department_id'])
                    ? Department::where('public_id', $data['department_id'])->value('id')
                    : null;
            }

            $blueprint->update($updateData);

            $this->clearCache($blueprint);
            event(new BlueprintUpdated($blueprint));

            return $blueprint->fresh();
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update blueprint', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint.update',
                'entity_type' => 'blueprint',
                'entity_id' => $blueprint->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function activate(Blueprint $blueprint): Blueprint
    {
        try {
            return DB::transaction(function () use ($blueprint) {
                if ($blueprint->is_locked) {
                    throw new BlueprintLockedException;
                }

                if ($blueprint->stages()->count() === 0) {
                    throw new \RuntimeException('Cannot activate a blueprint with no stages.');
                }

                $blueprint->update(['is_active' => true]);

                $this->clearCache($blueprint);
                event(new BlueprintActivated($blueprint));

                return $blueprint->fresh();
            });
        } catch (BlueprintLockedException|\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to activate blueprint', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint.activate',
                'entity_type' => 'blueprint',
                'entity_id' => $blueprint->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(Blueprint $blueprint): Blueprint
    {
        try {
            $blueprint->update(['is_active' => false]);

            $this->clearCache($blueprint);
            event(new BlueprintDeactivated($blueprint));

            return $blueprint->fresh();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to deactivate blueprint', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint.deactivate',
                'entity_type' => 'blueprint',
                'entity_id' => $blueprint->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function duplicate(Blueprint $blueprint, User $user): Blueprint
    {
        try {
            return DB::transaction(function () use ($blueprint, $user) {
                $newBlueprint = Blueprint::create([
                    'category_id' => $blueprint->category_id,
                    'name_en' => 'Copy of '.$blueprint->name_en,
                    'name_ar' => 'Copy of '.$blueprint->name_ar,
                    'description_en' => $blueprint->description_en,
                    'description_ar' => $blueprint->description_ar,
                    'scope' => $blueprint->scope,
                    'department_id' => $blueprint->department_id,
                    'is_locked' => false,
                    'is_active' => false,
                    'created_by_user_id' => $user->id,
                ]);

                $stageMap = [];
                foreach ($blueprint->stages as $stage) {
                    $newStage = BlueprintStage::create([
                        'blueprint_id' => $newBlueprint->id,
                        'stage_type_id' => $stage->stage_type_id,
                        'sla_policy_id' => $stage->sla_policy_id,
                        'name_en' => $stage->name_en,
                        'name_ar' => $stage->name_ar,
                        'description_en' => $stage->description_en,
                        'description_ar' => $stage->description_ar,
                        'sequence_order' => $stage->sequence_order,
                        'assignment_type' => $stage->assignment_type,
                        'assigned_position_id' => $stage->assigned_position_id,
                        'assigned_department_id' => $stage->assigned_department_id,
                        'assignment_cardinality' => $stage->assignment_cardinality,
                        'completion_rule' => $stage->completion_rule,
                        'escalation_position_id' => $stage->escalation_position_id,
                    ]);
                    $stageMap[$stage->id] = $newStage->id;

                    foreach ($stage->subStages as $subStage) {
                        BlueprintSubStage::create([
                            'blueprint_stage_id' => $newStage->id,
                            'sla_policy_id' => $subStage->sla_policy_id,
                            'name_en' => $subStage->name_en,
                            'name_ar' => $subStage->name_ar,
                            'description_en' => $subStage->description_en,
                            'description_ar' => $subStage->description_ar,
                            'sequence_order' => $subStage->sequence_order,
                            'is_required' => $subStage->is_required,
                            'assignment_type' => $subStage->assignment_type,
                            'assigned_position_id' => $subStage->assigned_position_id,
                            'assigned_department_id' => $subStage->assigned_department_id,
                            'assignment_cardinality' => $subStage->assignment_cardinality,
                            'completion_rule' => $subStage->completion_rule,
                        ]);
                    }
                }

                foreach ($blueprint->transitions as $transition) {
                    BlueprintTransition::create([
                        'blueprint_id' => $newBlueprint->id,
                        'from_stage_id' => $stageMap[$transition->from_stage_id],
                        'to_stage_id' => $stageMap[$transition->to_stage_id],
                        'transition_type' => $transition->transition_type,
                        'return_reason_required' => $transition->return_reason_required,
                    ]);
                }

                $this->clearCache($newBlueprint);
                event(new BlueprintDuplicated($newBlueprint, $blueprint));

                return $newBlueprint->load('stages.subStages', 'transitions');
            });
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to duplicate blueprint', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint.duplicate',
                'entity_type' => 'blueprint',
                'entity_id' => $blueprint->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(Blueprint $blueprint): void
    {
        try {
            if ($blueprint->hasTasks()) {
                $blueprint->update(['is_active' => false]);

                return;
            }

            $blueprint->update(['is_active' => false]);
            $blueprint->delete();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete blueprint', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint.delete',
                'entity_type' => 'blueprint',
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
        Cache::forget("{$tenantSlug}:blueprint:active:all");
        Cache::forget("{$tenantSlug}:blueprint:{$blueprint->public_id}:structure");
    }
}
