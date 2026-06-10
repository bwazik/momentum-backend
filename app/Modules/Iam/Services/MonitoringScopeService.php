<?php

namespace App\Modules\Iam\Services;

use App\Models\User;
use App\Modules\Iam\Events\MonitoringScopeGranted;
use App\Modules\Iam\Events\MonitoringScopeRevoked;
use App\Modules\Iam\Models\MonitoringScopeGrant;
use App\Modules\Organization\Models\Department;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitoringScopeService
{
    use AuthenticatedUser;

    public function grant(User $user, array $data, User $grantedBy): MonitoringScopeGrant
    {
        try {
            return DB::transaction(function () use ($user, $data, $grantedBy) {
                $scopeDepartmentId = null;
                if (! empty($data['scope_department_id'])) {
                    $dept = Department::where('public_id', $data['scope_department_id'])->firstOrFail();
                    $scopeDepartmentId = $dept->id;
                }

                $scope = MonitoringScopeGrant::create([
                    'user_id' => $user->id,
                    'scope_type' => (int) $data['scope_type'],
                    'scope_department_id' => $scopeDepartmentId,
                    'blueprint_category_id' => $data['blueprint_category_id'] ?? null,
                    'granted_by_user_id' => $grantedBy->id,
                    'granted_at' => now(),
                ]);

                event(new MonitoringScopeGranted($scope));

                return $scope;
            });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to grant monitoring scope', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'monitoring_scope.grant',
                'entity_type' => 'monitoring_scope',
                'entity_id' => null,
                'performed_by' => $grantedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revoke(MonitoringScopeGrant $scope): MonitoringScopeGrant
    {
        try {
            $scope->update(['revoked_at' => now()]);

            event(new MonitoringScopeRevoked($scope));

            return $scope->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke monitoring scope', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'monitoring_scope.revoke',
                'entity_type' => 'monitoring_scope',
                'entity_id' => $scope->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
