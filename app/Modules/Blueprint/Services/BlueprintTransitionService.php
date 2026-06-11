<?php

namespace App\Modules\Blueprint\Services;

use App\Modules\Blueprint\Enums\TransitionType;
use App\Modules\Blueprint\Events\TransitionCreated;
use App\Modules\Blueprint\Events\TransitionDeleted;
use App\Modules\Blueprint\Events\TransitionUpdated;
use App\Modules\Blueprint\Exceptions\BlueprintLockedException;
use App\Modules\Blueprint\Exceptions\InvalidTransitionException;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BlueprintTransitionService
{
    use AuthenticatedUser;

    public function create(Blueprint $blueprint, array $data): BlueprintTransition
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $fromStage = BlueprintStage::where('public_id', $data['from_stage_id'])
                ->where('blueprint_id', $blueprint->id)
                ->firstOrFail();
            $toStage = BlueprintStage::where('public_id', $data['to_stage_id'])
                ->where('blueprint_id', $blueprint->id)
                ->firstOrFail();

            if ($fromStage->id === $toStage->id) {
                throw new InvalidTransitionException('A stage cannot transition to itself.');
            }

            if ($data['transition_type'] === TransitionType::Advance->value) {
                if ($toStage->sequence_order <= $fromStage->sequence_order) {
                    throw new InvalidTransitionException('Advance transition must go to a stage with a higher sequence order.');
                }
            } elseif ($data['transition_type'] === TransitionType::Return->value) {
                if ($toStage->sequence_order >= $fromStage->sequence_order) {
                    throw new InvalidTransitionException('Return transition must go to a stage with a lower sequence order.');
                }
            }

            $transition = BlueprintTransition::create([
                'blueprint_id' => $blueprint->id,
                'from_stage_id' => $fromStage->id,
                'to_stage_id' => $toStage->id,
                'transition_type' => $data['transition_type'],
                'return_reason_required' => $data['return_reason_required'] ?? ($data['transition_type'] === TransitionType::Return->value),
            ]);

            $this->clearCache($blueprint);
            event(new TransitionCreated($transition));

            return $transition;
        } catch (BlueprintLockedException|InvalidTransitionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create transition', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'transition.create',
                'entity_type' => 'transition',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Blueprint $blueprint, BlueprintTransition $transition, array $data): BlueprintTransition
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $fromStage = isset($data['from_stage_id'])
                ? BlueprintStage::where('public_id', $data['from_stage_id'])->where('blueprint_id', $blueprint->id)->firstOrFail()
                : null;
            $toStage = isset($data['to_stage_id'])
                ? BlueprintStage::where('public_id', $data['to_stage_id'])->where('blueprint_id', $blueprint->id)->firstOrFail()
                : null;

            $fromStageId = $fromStage?->id ?? $transition->from_stage_id;
            $toStageId = $toStage?->id ?? $transition->to_stage_id;

            if ($fromStageId === $toStageId) {
                throw new InvalidTransitionException('A stage cannot transition to itself.');
            }

            $transitionType = $data['transition_type'] ?? $transition->transition_type;

            $resolvedFrom = $fromStage ?? BlueprintStage::where('id', $transition->from_stage_id)->first();
            $resolvedTo = $toStage ?? BlueprintStage::where('id', $transition->to_stage_id)->first();

            if ($transitionType === TransitionType::Advance->value) {
                if ($resolvedTo->sequence_order <= $resolvedFrom->sequence_order) {
                    throw new InvalidTransitionException('Advance transition must go to a stage with a higher sequence order.');
                }
            } elseif ($transitionType === TransitionType::Return->value) {
                if ($resolvedTo->sequence_order >= $resolvedFrom->sequence_order) {
                    throw new InvalidTransitionException('Return transition must go to a stage with a lower sequence order.');
                }
            }

            $transition->update([
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStageId,
                'transition_type' => $transitionType,
                'return_reason_required' => $data['return_reason_required'] ?? $transition->return_reason_required,
            ]);

            $this->clearCache($blueprint);
            event(new TransitionUpdated($transition));

            return $transition->fresh();
        } catch (BlueprintLockedException|InvalidTransitionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update transition', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'transition.update',
                'entity_type' => 'transition',
                'entity_id' => $transition->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(Blueprint $blueprint, BlueprintTransition $transition): void
    {
        try {
            if ($blueprint->is_locked) {
                throw new BlueprintLockedException;
            }

            $transition->delete();

            $this->clearCache($blueprint);
            event(new TransitionDeleted($transition));
        } catch (BlueprintLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete transition', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'transition.delete',
                'entity_type' => 'transition',
                'entity_id' => $transition->id,
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
