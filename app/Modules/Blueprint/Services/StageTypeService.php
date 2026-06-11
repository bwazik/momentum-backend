<?php

namespace App\Modules\Blueprint\Services;

use App\Modules\Blueprint\Events\StageTypeCreated;
use App\Modules\Blueprint\Events\StageTypeUpdated;
use App\Modules\Blueprint\Exceptions\StageTypeInUseException;
use App\Modules\Blueprint\Models\StageType;
use App\Traits\AuthenticatedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StageTypeService
{
    use AuthenticatedUser;

    public function getAll(): Collection
    {
        $tenantSlug = tenant()?->slug ?? 'central';

        return Cache::remember("{$tenantSlug}:blueprint:stage_types:all", 300, function () {
            return StageType::active()->orderBy('display_order')->get();
        });
    }

    public function create(array $data): StageType
    {
        try {
            $stageType = StageType::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'display_order' => $data['display_order'] ?? 0,
                'is_system_default' => false,
                'is_active' => true,
            ]);

            $this->clearCache();
            event(new StageTypeCreated($stageType));

            return $stageType;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create stage type', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage_type.create',
                'entity_type' => 'stage_type',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(StageType $stageType, array $data): StageType
    {
        try {
            if ($stageType->is_system_default) {
                if (isset($data['name_ar']) || isset($data['name_en'])) {
                    // System defaults cannot be renamed, skip name fields
                    unset($data['name_ar'], $data['name_en']);
                }
            }

            $stageType->update([
                'name_ar' => $data['name_ar'] ?? $stageType->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $stageType->name_ar),
                'display_order' => $data['display_order'] ?? $stageType->display_order,
                'is_active' => $data['is_active'] ?? $stageType->is_active,
            ]);

            $this->clearCache();
            event(new StageTypeUpdated($stageType));

            return $stageType->fresh();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update stage type', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage_type.update',
                'entity_type' => 'stage_type',
                'entity_id' => $stageType->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(StageType $stageType): void
    {
        try {
            if ($stageType->is_system_default) {
                throw new StageTypeInUseException;
            }

            if ($stageType->stages()->exists()) {
                throw new StageTypeInUseException;
            }

            $stageType->delete();

            $this->clearCache();
        } catch (StageTypeInUseException $e) {
            Log::channel('blueprint')->warning('Failed to delete stage type in use', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage_type.delete',
                'entity_type' => 'stage_type',
                'entity_id' => $stageType->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete stage type', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'stage_type.delete',
                'entity_type' => 'stage_type',
                'entity_id' => $stageType->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:blueprint:stage_types:all");
    }
}
