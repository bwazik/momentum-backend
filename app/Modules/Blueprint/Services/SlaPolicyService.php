<?php

namespace App\Modules\Blueprint\Services;

use App\Modules\Blueprint\Events\SlaPolicyCreated;
use App\Modules\Blueprint\Events\SlaPolicyDeleted;
use App\Modules\Blueprint\Events\SlaPolicyUpdated;
use App\Modules\Blueprint\Exceptions\SlaPolicyInUseException;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Traits\AuthenticatedUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SlaPolicyService
{
    use AuthenticatedUser;

    public function getAll(): Collection
    {
        $tenantSlug = tenant()?->slug ?? 'central';

        return Cache::remember("{$tenantSlug}:blueprint:sla_policies:all", 300, function () {
            return SlaPolicy::active()->orderBy('name_ar')->get();
        });
    }

    public function create(array $data): SlaPolicy
    {
        try {
            $slaPolicy = SlaPolicy::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'sla_value' => $data['sla_value'],
                'sla_unit' => $data['sla_unit'],
                'warning_threshold_percentage' => $data['warning_threshold_percentage'] ?? 75,
                'is_active' => true,
            ]);

            $this->clearCache();
            event(new SlaPolicyCreated($slaPolicy));

            return $slaPolicy;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to create SLA policy', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_policy.create',
                'entity_type' => 'sla_policy',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(SlaPolicy $slaPolicy, array $data): SlaPolicy
    {
        try {
            $slaPolicy->update([
                'name_ar' => $data['name_ar'] ?? $slaPolicy->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $slaPolicy->name_ar),
                'sla_value' => $data['sla_value'] ?? $slaPolicy->sla_value,
                'sla_unit' => $data['sla_unit'] ?? $slaPolicy->sla_unit,
                'warning_threshold_percentage' => $data['warning_threshold_percentage'] ?? $slaPolicy->warning_threshold_percentage,
                'is_active' => $data['is_active'] ?? $slaPolicy->is_active,
            ]);

            $this->clearCache();
            event(new SlaPolicyUpdated($slaPolicy));

            return $slaPolicy->fresh();
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to update SLA policy', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_policy.update',
                'entity_type' => 'sla_policy',
                'entity_id' => $slaPolicy->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(SlaPolicy $slaPolicy): void
    {
        try {
            if ($slaPolicy->stages()->exists() || $slaPolicy->subStages()->exists()) {
                throw new SlaPolicyInUseException;
            }

            $slaPolicy->delete();

            $this->clearCache();
            event(new SlaPolicyDeleted($slaPolicy));
        } catch (SlaPolicyInUseException $e) {
            Log::channel('blueprint')->warning('Failed to delete SLA policy in use', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_policy.delete',
                'entity_type' => 'sla_policy',
                'entity_id' => $slaPolicy->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('blueprint')->error('Failed to delete SLA policy', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_policy.delete',
                'entity_type' => 'sla_policy',
                'entity_id' => $slaPolicy->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:blueprint:sla_policies:all");
    }
}
