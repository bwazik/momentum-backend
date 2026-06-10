<?php

namespace App\Modules\Platform\Services;

use App\Enums\AccountType;
use App\Models\User;
use App\Modules\Iam\Exceptions\UserAlreadyActiveException;
use App\Modules\Iam\Exceptions\UserAlreadyDeactivatedException;
use App\Modules\Platform\Events\PlatformAdminCreated;
use App\Modules\Platform\Events\PlatformAdminDeactivated;
use App\Modules\Platform\Events\PlatformAdminReactivated;
use App\Modules\Platform\Events\PlatformAdminUpdated;
use App\Modules\Platform\Exceptions\PlatformAdminCannotDeactivateSelfException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformAdminService
{
    public function create(array $data, int $createdByUserId, string $ip): User
    {
        try {
            return DB::transaction(function () use ($data, $createdByUserId, $ip) {
                $user = User::create([
                    'name_ar' => $data['name_ar'],
                    'name_en' => $data['name_en'] ?? $data['name_ar'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'account_type' => AccountType::PLATFORM_ADMIN,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);

                event(new PlatformAdminCreated($user, $createdByUserId, $ip));

                return $user;
            });
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin creation failed', [
                'action' => 'platform_admin.create',
                'entity_type' => 'platform_admin',
                'created_by' => $createdByUserId,
                'email' => $data['email'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(User $admin, array $data, int $updatedByUserId, string $ip): User
    {
        try {
            return DB::transaction(function () use ($admin, $data, $updatedByUserId, $ip) {
                if (isset($data['name_en']) && empty($data['name_en'])) {
                    $data['name_en'] = $data['name_ar'] ?? $admin->name_ar;
                }

                $admin->update($data);

                event(new PlatformAdminUpdated($admin, $updatedByUserId, $ip, $data));

                return $admin->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin update failed', [
                'action' => 'platform_admin.update',
                'entity_id' => $admin->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(User $admin, int $deactivatedByUserId, string $ip): User
    {
        if ($deactivatedByUserId === $admin->id) {
            throw new PlatformAdminCannotDeactivateSelfException;
        }

        if (! $admin->is_active || $admin->deleted_at !== null) {
            throw new UserAlreadyDeactivatedException;
        }

        try {
            return DB::transaction(function () use ($admin, $deactivatedByUserId, $ip) {
                $admin->update(['is_active' => false]);
                $admin->delete();

                event(new PlatformAdminDeactivated($admin, $deactivatedByUserId, $ip));

                return $admin->fresh();
            });
        } catch (UserAlreadyDeactivatedException|PlatformAdminCannotDeactivateSelfException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin deactivation failed', [
                'action' => 'platform_admin.deactivate',
                'entity_id' => $admin->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(User $admin, int $reactivatedByUserId, string $ip): User
    {
        if ($admin->is_active && $admin->deleted_at === null) {
            throw new UserAlreadyActiveException;
        }

        try {
            return DB::transaction(function () use ($admin, $reactivatedByUserId, $ip) {
                $admin->update(['is_active' => true]);
                $admin->restore();

                event(new PlatformAdminReactivated($admin, $reactivatedByUserId, $ip));

                return $admin->fresh();
            });
        } catch (UserAlreadyActiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin reactivation failed', [
                'action' => 'platform_admin.reactivate',
                'entity_id' => $admin->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(?string $search = null, int $perPage = 15): CursorPaginator
    {
        $query = User::where('account_type', AccountType::PLATFORM_ADMIN->value)
            ->orderBy('id');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'ilike', "%{$search}%")
                    ->orWhere('name_ar', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return $query->cursorPaginate($perPage);
    }
}
