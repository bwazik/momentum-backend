<?php

namespace App\Modules\Iam\Services;

use App\Models\User;
use App\Modules\Iam\Events\AuditGrantCreated;
use App\Modules\Iam\Events\AuditGrantRevoked;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Organization\Models\Department;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditGrantService
{
    use AuthenticatedUser;

    public function grant(User $auditor, array $data, User $grantedBy): AuditGrant
    {
        try {
            return DB::transaction(function () use ($auditor, $data, $grantedBy) {
                $departmentId = null;
                if (! empty($data['department_id'])) {
                    $dept = Department::where('public_id', $data['department_id'])->firstOrFail();
                    $departmentId = $dept->id;
                }

                $grant = AuditGrant::create([
                    'external_auditor_user_id' => $auditor->id,
                    'granted_by_user_id' => $grantedBy->id,
                    'date_range_start' => $data['date_range_start'],
                    'date_range_end' => $data['date_range_end'],
                    'department_id' => $departmentId,
                    'granted_at' => now(),
                ]);

                event(new AuditGrantCreated($grant));

                return $grant;
            });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to grant audit scope', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit_grant.grant',
                'entity_type' => 'audit_grant',
                'entity_id' => null,
                'performed_by' => $grantedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revoke(AuditGrant $grant): AuditGrant
    {
        try {
            $grant->update(['revoked_at' => now()]);

            event(new AuditGrantRevoked($grant));

            return $grant->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke audit grant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit_grant.revoke',
                'entity_type' => 'audit_grant',
                'entity_id' => $grant->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
