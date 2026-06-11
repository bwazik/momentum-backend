<?php

namespace App\Modules\Blueprint\Services;

use App\Modules\Blueprint\Events\BlueprintCategoryCreated;
use App\Modules\Blueprint\Events\BlueprintCategoryUpdated;
use App\Modules\Blueprint\Exceptions\BlueprintCategoryInUseException;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Traits\AuthenticatedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BlueprintCategoryService
{
    use AuthenticatedUser;

    public function getAll(): Collection
    {
        $tenantSlug = tenant()?->slug ?? 'central';

        return Cache::remember("{$tenantSlug}:blueprint:categories:all", 300, function () {
            return BlueprintCategory::active()->orderBy('display_order')->get();
        });
    }

    public function create(array $data): BlueprintCategory
    {
        try {
            $category = BlueprintCategory::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'display_order' => $data['display_order'] ?? 0,
                'is_active' => true,
            ]);

            $this->clearCache();
            event(new BlueprintCategoryCreated($category));

            return $category;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create blueprint category', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint_category.create',
                'entity_type' => 'blueprint_category',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(BlueprintCategory $category, array $data): BlueprintCategory
    {
        try {
            $category->update([
                'name_ar' => $data['name_ar'] ?? $category->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $category->name_ar),
                'display_order' => $data['display_order'] ?? $category->display_order,
                'is_active' => $data['is_active'] ?? $category->is_active,
            ]);

            $this->clearCache();
            event(new BlueprintCategoryUpdated($category));

            return $category->fresh();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update blueprint category', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint_category.update',
                'entity_type' => 'blueprint_category',
                'entity_id' => $category->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(BlueprintCategory $category): BlueprintCategory
    {
        try {
            $category->update(['is_active' => false]);

            $this->clearCache();
            event(new BlueprintCategoryUpdated($category));

            return $category->fresh();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to deactivate blueprint category', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint_category.deactivate',
                'entity_type' => 'blueprint_category',
                'entity_id' => $category->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(BlueprintCategory $category): BlueprintCategory
    {
        try {
            $category->update(['is_active' => true]);

            $this->clearCache();
            event(new BlueprintCategoryUpdated($category));

            return $category->fresh();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to reactivate blueprint category', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint_category.reactivate',
                'entity_type' => 'blueprint_category',
                'entity_id' => $category->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(BlueprintCategory $category): void
    {
        try {
            if ($category->blueprints()->where('is_active', true)->whereNull('deleted_at')->exists()) {
                throw new BlueprintCategoryInUseException;
            }

            $category->delete();

            $this->clearCache();
        } catch (BlueprintCategoryInUseException $e) {
            Log::channel('blueprint')->warning('Failed to delete blueprint category in use', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint_category.delete',
                'entity_type' => 'blueprint_category',
                'entity_id' => $category->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete blueprint category', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'blueprint_category.delete',
                'entity_type' => 'blueprint_category',
                'entity_id' => $category->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:blueprint:categories:all");
    }
}
