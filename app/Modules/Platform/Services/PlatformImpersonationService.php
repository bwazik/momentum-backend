<?php

namespace App\Modules\Platform\Services;

use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Platform\Events\ImpersonationEnded;
use App\Modules\Platform\Events\ImpersonationStarted;
use App\Modules\Platform\Exceptions\CannotImpersonatePlatformAdminException;
use App\Modules\Platform\Exceptions\CannotImpersonateSelfException;
use App\Modules\Platform\Models\AuditEvent;
use Illuminate\Support\Facades\Log;

class PlatformImpersonationService
{
    public function startImpersonation(
        Tenant $tenant,
        string $targetUserPublicId,
        int $adminUserId,
        string $adminPublicId,
        string $ip
    ): array {
        if ($targetUserPublicId === $adminPublicId) {
            throw new CannotImpersonateSelfException;
        }

        tenancy()->initialize($tenant);

        $targetUser = User::where('public_id', $targetUserPublicId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->firstOrFail();

        if ($targetUser->account_type === AccountType::PLATFORM_ADMIN) {
            tenancy()->end();
            throw new CannotImpersonatePlatformAdminException;
        }

        $tokenName = "impersonated-by:{$adminPublicId}";
        $plainTextToken = $targetUser->createToken($tokenName, ['impersonated'], now()->addHour())->plainTextToken;

        $targetUserData = $targetUser->toArray();

        tenancy()->end();

        $admin = User::findOrFail($adminUserId);

        event(new ImpersonationStarted($admin, $targetUser, $tenant->public_id, $tenant->slug, $ip));

        return [
            'token' => $plainTextToken,
            'user' => $targetUserData,
            'tenant' => $tenant,
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
    }

    public function endImpersonation(int $adminUserId, string $adminPublicId, string $tenantPublicId, string $ip): void
    {
        $startEvent = AuditEvent::where('action', AuditAction::ImpersonationStart->value)
            ->where('user_id', $adminUserId)
            ->where('payload->tenant_public_id', $tenantPublicId)
            ->latest('created_at')
            ->first();

        $entityId = $startEvent?->entity_id ?? $adminPublicId;

        $admin = User::findOrFail($adminUserId);

        try {
            $tenant = Tenant::where('public_id', $tenantPublicId)->firstOrFail();
            tenancy()->initialize($tenant);

            if ($startEvent) {
                $targetUser = User::where('public_id', $entityId)->first();
                if ($targetUser) {
                    $targetUser->tokens()
                        ->where('name', "impersonated-by:{$adminPublicId}")
                        ->delete();
                }
            }

            tenancy()->end();
        } catch (\Throwable $e) {
            tenancy()->end();

            Log::channel('platform')->error('Failed to revoke impersonation tokens', [
                'action' => 'impersonation.revoke_tokens',
                'admin_user_id' => $adminUserId,
                'tenant_public_id' => $tenantPublicId,
                'error' => $e->getMessage(),
            ]);
        }

        event(new ImpersonationEnded($admin, $tenantPublicId, $entityId, $ip));
    }

    public function listActiveSessions()
    {
        $impersonationStartIds = AuditEvent::where('action', AuditAction::ImpersonationStart->value)
            ->pluck('entity_id');

        $endedUserIds = AuditEvent::where('action', AuditAction::ImpersonationEnd->value)
            ->pluck('entity_id');

        $activeUserIds = $impersonationStartIds->diff($endedUserIds);

        return AuditEvent::where('action', AuditAction::ImpersonationStart->value)
            ->whereIn('entity_id', $activeUserIds)
            ->orderByDesc('created_at')
            ->cursorPaginate(15);
    }
}
