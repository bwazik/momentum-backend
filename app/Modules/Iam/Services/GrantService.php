<?php

namespace App\Modules\Iam\Services;

use App\Models\User;
use App\Modules\Iam\Events\CapabilityGranted;
use App\Modules\Iam\Events\CapabilityRevoked;
use App\Modules\Iam\Exceptions\DuplicateGrantException;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\PositionCapabilityGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrantService
{
    use AuthenticatedUser;

    public function grantToPosition(Position $position, array $data, User $grantedBy): PositionCapabilityGrant
    {
        try {
            return DB::transaction(function () use ($position, $data, $grantedBy) {
                $capability = Capability::where('public_id', $data['capability_id'])->firstOrFail();
                $scopeDepartmentId = $this->resolveScopeDepartment($data);

                $exists = PositionCapabilityGrant::where('position_id', $position->id)
                    ->where('capability_id', $capability->id)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($exists) {
                    throw new DuplicateGrantException('position capability grant');
                }

                $grant = PositionCapabilityGrant::create([
                    'position_id' => $position->id,
                    'capability_id' => $capability->id,
                    'scope_type' => (int) $data['scope_type'],
                    'scope_department_id' => $scopeDepartmentId,
                    'granted_by_user_id' => $grantedBy->id,
                    'granted_at' => now(),
                ]);

                event(new CapabilityGranted($grant, 'position'));

                return $grant;
            });
        } catch (DuplicateGrantException $e) {
            Log::channel('iam')->warning('Duplicate position capability grant attempt', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'grant.position',
                'entity_type' => 'position_capability_grant',
                'entity_id' => $position->public_id,
                'performed_by' => $grantedBy->public_id,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to grant capability to position', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'grant.position',
                'entity_type' => 'position_capability_grant',
                'entity_id' => $position->public_id,
                'performed_by' => $grantedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revokePositionGrant(PositionCapabilityGrant $grant): PositionCapabilityGrant
    {
        try {
            $grant->update(['revoked_at' => now()]);

            event(new CapabilityRevoked($grant, 'position'));

            return $grant->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke position capability grant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'grant.revoke_position',
                'entity_type' => 'position_capability_grant',
                'entity_id' => $grant->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function grantToUser(User $user, array $data, User $grantedBy): UserCapabilityGrant
    {
        try {
            return DB::transaction(function () use ($user, $data, $grantedBy) {
                $capability = Capability::where('public_id', $data['capability_id'])->firstOrFail();
                $scopeDepartmentId = $this->resolveScopeDepartment($data);

                $exists = UserCapabilityGrant::where('user_id', $user->id)
                    ->where('capability_id', $capability->id)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($exists) {
                    throw new DuplicateGrantException('user capability grant');
                }

                $grant = UserCapabilityGrant::create([
                    'user_id' => $user->id,
                    'capability_id' => $capability->id,
                    'scope_type' => (int) $data['scope_type'],
                    'scope_department_id' => $scopeDepartmentId,
                    'granted_by_user_id' => $grantedBy->id,
                    'granted_at' => now(),
                    'reason' => $data['reason'],
                ]);

                event(new CapabilityGranted($grant, 'user'));

                return $grant;
            });
        } catch (DuplicateGrantException $e) {
            Log::channel('iam')->warning('Duplicate user capability grant attempt', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'grant.user',
                'entity_type' => 'user_capability_grant',
                'entity_id' => $user->public_id,
                'performed_by' => $grantedBy->public_id,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to grant capability to user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'grant.user',
                'entity_type' => 'user_capability_grant',
                'entity_id' => $user->public_id,
                'performed_by' => $grantedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revokeUserGrant(UserCapabilityGrant $grant): UserCapabilityGrant
    {
        try {
            $grant->update(['revoked_at' => now()]);

            event(new CapabilityRevoked($grant, 'user'));

            return $grant->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke user capability grant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'grant.revoke_user',
                'entity_type' => 'user_capability_grant',
                'entity_id' => $grant->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function resolveScopeDepartment(array $data): ?int
    {
        if (! empty($data['scope_department_id'])) {
            $dept = Department::where('public_id', $data['scope_department_id'])->firstOrFail();

            return $dept->id;
        }

        return null;
    }
}
