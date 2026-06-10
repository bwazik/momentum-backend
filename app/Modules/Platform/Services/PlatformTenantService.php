<?php

namespace App\Modules\Platform\Services;

use App\Models\Tenant;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Modules\Platform\Events\TenantReactivated;
use App\Modules\Platform\Events\TenantSuspended;
use App\Modules\Platform\Events\TenantUpdated;
use App\Modules\Platform\Exceptions\TenantAlreadyActiveException;
use App\Modules\Platform\Exceptions\TenantAlreadySuspendedException;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformTenantService
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
    ) {}

    public function provision(array $data, int $adminUserId, string $ip): Tenant
    {
        try {
            $tenant = $this->provisioningService->provision($data);

            event(new TenantProvisioned($tenant, $adminUserId, $ip));

            return $tenant;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant provisioning failed', [
                'action' => 'tenant.create',
                'entity_type' => 'tenant',
                'admin_user_id' => $adminUserId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function suspend(Tenant $tenant, int $adminUserId, string $ip): Tenant
    {
        if (! $tenant->is_active) {
            throw new TenantAlreadySuspendedException;
        }

        try {
            return DB::transaction(function () use ($tenant, $adminUserId, $ip) {
                $tenant->update(['is_active' => false]);

                event(new TenantSuspended($tenant, $adminUserId, $ip));

                return $tenant->fresh();
            });
        } catch (TenantAlreadySuspendedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant suspension failed', [
                'action' => 'tenant.suspend',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(Tenant $tenant, int $adminUserId, string $ip): Tenant
    {
        if ($tenant->is_active) {
            throw new TenantAlreadyActiveException;
        }

        try {
            return DB::transaction(function () use ($tenant, $adminUserId, $ip) {
                $tenant->update(['is_active' => true]);

                event(new TenantReactivated($tenant, $adminUserId, $ip));

                return $tenant->fresh();
            });
        } catch (TenantAlreadyActiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant reactivation failed', [
                'action' => 'tenant.reactivate',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Tenant $tenant, array $data, int $adminUserId, string $ip): Tenant
    {
        unset($data['slug'], $data['database_name']);

        try {
            return DB::transaction(function () use ($tenant, $data, $adminUserId, $ip) {
                $tenant->update($data);

                event(new TenantUpdated($tenant, $adminUserId, $ip, $data));

                return $tenant->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant update failed', [
                'action' => 'tenant.update',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(?string $search = null, ?bool $isActiveOnly = null, int $perPage = 15): CursorPaginator
    {
        $query = Tenant::query()->orderBy('id');

        if ($isActiveOnly !== null) {
            $query->where('is_active', $isActiveOnly);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'ilike', "%{$search}%")
                    ->orWhere('name_ar', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }

        return $query->cursorPaginate($perPage);
    }
}
