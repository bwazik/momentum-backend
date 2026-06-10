<?php

namespace App\Modules\Iam\Services;

use App\Modules\Iam\Exceptions\CannotRevokeSystemCapabilityKeyException;
use App\Modules\Iam\Models\Capability;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\Log;

class CapabilityService
{
    use AuthenticatedUser;

    public function create(array $data): Capability
    {
        try {
            $capability = Capability::create([
                'key' => $data['key'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? null,
                'description' => $data['description'] ?? null,
                'is_system_defined' => $data['is_system_defined'] ?? false,
            ]);

            return $capability;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to create capability', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'capability.create',
                'entity_type' => 'capability',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Capability $capability, array $data): Capability
    {
        try {
            if ($capability->is_system_defined && array_key_exists('key', $data) && $data['key'] !== $capability->key) {
                throw new CannotRevokeSystemCapabilityKeyException;
            }

            unset($data['key']);

            $capability->update($data);

            return $capability->fresh();
        } catch (CannotRevokeSystemCapabilityKeyException $e) {
            Log::channel('iam')->warning('Attempted to change system-defined capability key', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'capability.update',
                'entity_type' => 'capability',
                'entity_id' => $capability->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to update capability', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'capability.update',
                'entity_type' => 'capability',
                'entity_id' => $capability->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(): array
    {
        try {
            return Capability::orderBy('key')->get()->toArray();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to list capabilities', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'capability.list',
                'entity_type' => 'capability',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
