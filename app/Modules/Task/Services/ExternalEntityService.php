<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Events\ExternalEntityCreated;
use App\Modules\Task\Events\ExternalEntityDeactivated;
use App\Modules\Task\Events\ExternalEntityReactivated;
use App\Modules\Task\Events\ExternalEntityUpdated;
use App\Modules\Task\Models\ExternalEntity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExternalEntityService
{
    public function getActive(): Collection
    {
        $tenantSlug = tenant()?->slug ?? 'central';

        return Cache::remember("{$tenantSlug}:task:external_entities:active", 300, function () {
            return ExternalEntity::active()->orderBy('name_ar')->get();
        });
    }

    public function create(array $data, User $user): ExternalEntity
    {
        try {
            $entity = ExternalEntity::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'entity_type' => $data['entity_type'],
                'is_active' => true,
            ]);

            $this->clearCache();
            event(new ExternalEntityCreated($entity, $user));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.create',
                'entity_type' => 'external_entity',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(ExternalEntity $entity, array $data, User $user): ExternalEntity
    {
        try {
            $entity->update([
                'name_ar' => $data['name_ar'] ?? $entity->name_ar,
                'name_en' => array_key_exists('name_en', $data)
                    ? (! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $entity->name_ar))
                    : $entity->name_en,
                'entity_type' => $data['entity_type'] ?? $entity->entity_type,
            ]);

            $this->clearCache();
            event(new ExternalEntityUpdated($entity->fresh(), $user));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to update external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.update',
                'entity_type' => 'external_entity',
                'entity_id' => $entity->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(ExternalEntity $entity, User $user): ExternalEntity
    {
        try {
            $entity->update(['is_active' => false]);
            $this->clearCache();
            event(new ExternalEntityDeactivated($entity, $user));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to deactivate external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.deactivate',
                'entity_type' => 'external_entity',
                'entity_id' => $entity->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(ExternalEntity $entity, User $user): ExternalEntity
    {
        try {
            $entity->update(['is_active' => true]);
            $this->clearCache();
            event(new ExternalEntityReactivated($entity, $user));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to reactivate external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.reactivate',
                'entity_type' => 'external_entity',
                'entity_id' => $entity->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:task:external_entities:active");
    }
}
