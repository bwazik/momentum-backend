<?php

namespace App\Modules\Iam\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Models\PositionCapabilityGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use Illuminate\Support\Collection;

class IamPolicy
{
    private ?Collection $capabilitiesCache = null;

    private ?int $cachedUserId = null;

    public function check(User $user, string $capability, ?ScopeType $scopeType = null, ?int $departmentId = null): bool
    {
        $effectiveCapabilities = $this->getEffectiveCapabilities($user);

        $matchingGrant = $effectiveCapabilities->first(
            fn ($grant) => $grant->capability_key === $capability && $grant->revoked_at === null
        );

        if ($matchingGrant === null) {
            return false;
        }

        if ($scopeType === null && $departmentId === null) {
            return true;
        }

        return $this->scopeCoversDepartment(
            $matchingGrant->scope_type,
            $matchingGrant->scope_department_id,
            $user,
            $scopeType,
            $departmentId
        );
    }

    public function getEffectiveCapabilities(User $user): Collection
    {
        if ($this->capabilitiesCache !== null && $this->cachedUserId === $user->id) {
            return $this->capabilitiesCache;
        }

        $positionGrants = $this->getPositionGrants($user);
        $userGrants = $this->getUserGrants($user);

        $merged = $positionGrants->merge($userGrants);

        $this->capabilitiesCache = $merged;
        $this->cachedUserId = $user->id;

        return $merged;
    }

    public function hasCapability(User $user, string $capability): bool
    {
        return $this->check($user, $capability);
    }

    public function isOutOfOffice(User $user): bool
    {
        return (bool) $user->is_out_of_office;
    }

    public function resolveAssignee(User $user): User
    {
        if ($this->isOutOfOffice($user) && $user->out_of_office_delegate_user_id) {
            return $user->outOfOfficeDelegate;
        }

        return $user;
    }

    public function getActiveDelegate(User $user): ?User
    {
        return Delegation::where('delegator_user_id', $user->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('created_at')
            ->first()
            ?->delegate;
    }

    public function checkAuditGrant(User $auditor, ?int $departmentId = null): bool
    {
        return AuditGrant::where('external_auditor_user_id', $auditor->id)
            ->whereNull('revoked_at')
            ->where('date_range_start', '<=', now())
            ->where('date_range_end', '>=', now())
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $departmentId))
            ->exists();
    }

    public function clearCache(): void
    {
        $this->capabilitiesCache = null;
        $this->cachedUserId = null;
    }

    private function getPositionGrants(User $user): Collection
    {
        $primaryAssignment = $user->currentPositionAssignment;

        if ($primaryAssignment === null) {
            return collect();
        }

        return PositionCapabilityGrant::where('position_id', $primaryAssignment->position_id)
            ->whereNull('revoked_at')
            ->with('capability')
            ->get()
            ->map(fn ($grant) => (object) [
                'capability_key' => $grant->capability->key,
                'scope_type' => $grant->scope_type,
                'scope_department_id' => $grant->scope_department_id,
                'source' => 'position',
                'revoked_at' => $grant->revoked_at,
            ]);
    }

    private function getUserGrants(User $user): Collection
    {
        return UserCapabilityGrant::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('capability')
            ->get()
            ->map(fn ($grant) => (object) [
                'capability_key' => $grant->capability->key,
                'scope_type' => $grant->scope_type,
                'scope_department_id' => $grant->scope_department_id,
                'source' => 'user',
                'revoked_at' => $grant->revoked_at,
            ]);
    }

    private function scopeCoversDepartment(
        ScopeType $grantScopeType,
        ?int $grantScopeDepartmentId,
        User $user,
        ?ScopeType $requiredScopeType,
        ?int $requiredDepartmentId
    ): bool {
        return match ($grantScopeType) {
            ScopeType::TENANT => true,
            ScopeType::OWN_DEPARTMENT => $this->getUserDepartmentId($user) === $requiredDepartmentId,
            ScopeType::SPECIFIC_DEPARTMENT => $grantScopeDepartmentId === $requiredDepartmentId,
            ScopeType::DEPARTMENT_TREE => $this->departmentIsInTree($grantScopeDepartmentId, $requiredDepartmentId),
            ScopeType::OWN_TASKS => true,
            default => false,
        };
    }

    private function getUserDepartmentId(User $user): ?int
    {
        return $user->currentPositionAssignment?->position?->department_id;
    }

    private function departmentIsInTree(?int $ancestorId, ?int $descendantId): bool
    {
        if ($ancestorId === null || $descendantId === null) {
            return false;
        }

        if ($ancestorId === $descendantId) {
            return true;
        }

        $descendant = Department::find($descendantId);

        if ($descendant === null) {
            return false;
        }

        $current = $descendant;
        $maxDepth = 10;

        while ($current->parent_department_id !== null && $maxDepth-- > 0) {
            if ($current->parent_department_id === $ancestorId) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }
}
