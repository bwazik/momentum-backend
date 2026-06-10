<?php

namespace App\Modules\Iam\Services;

use App\Models\User;
use App\Modules\Iam\Events\UserCreated;
use App\Modules\Iam\Events\UserDeactivated;
use App\Modules\Iam\Events\UserMarkedBackInOffice;
use App\Modules\Iam\Events\UserMarkedOutOfOffice;
use App\Modules\Iam\Events\UserReactivated;
use App\Modules\Iam\Exceptions\UserAlreadyActiveException;
use App\Modules\Iam\Exceptions\UserAlreadyDeactivatedException;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Traits\AuthenticatedUser;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserService
{
    use AuthenticatedUser;

    public function create(array $data): User
    {
        try {
            $user = User::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'email' => $data['email'],
                'password' => $data['password'],
                'mobile' => $data['mobile'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'account_type' => $data['account_type'],
                'preferred_language' => $data['preferred_language'] ?? 1,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            event(new UserCreated($user));

            return $user;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to create user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.create',
                'entity_type' => 'user',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(User $user, array $data): User
    {
        try {
            if (array_key_exists('name_en', $data) && empty($data['name_en'])) {
                $data['name_en'] = $data['name_ar'] ?? $user->name_ar;
            }

            $user->update($data);

            return $user->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to update user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.update',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(User $user): User
    {
        try {
            return DB::transaction(function () use ($user) {
                if (! $user->is_active) {
                    throw new UserAlreadyDeactivatedException;
                }

                $user->update(['is_active' => false]);
                $user->delete();

                $refreshed = User::withTrashed()->find($user->id);

                event(new UserDeactivated($refreshed));

                return $refreshed;
            });
        } catch (UserAlreadyDeactivatedException $e) {
            Log::channel('iam')->warning('Attempted to deactivate already deactivated user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.deactivate',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to deactivate user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.deactivate',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(User $user): User
    {
        try {
            return DB::transaction(function () use ($user) {
                if ($user->is_active && $user->deleted_at === null) {
                    throw new UserAlreadyActiveException;
                }

                $user->update(['is_active' => true]);
                $user->restore();

                event(new UserReactivated($user));

                return $user->fresh();
            });
        } catch (UserAlreadyActiveException $e) {
            Log::channel('iam')->warning('Attempted to reactivate already active user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.reactivate',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to reactivate user', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.reactivate',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markOutOfOffice(User $user, ?int $delegateUserId = null): User
    {
        try {
            $user->update([
                'is_out_of_office' => true,
                'out_of_office_delegate_user_id' => $delegateUserId,
            ]);

            event(new UserMarkedOutOfOffice($user));

            return $user->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to mark user out of office', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.out_of_office',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markBackInOffice(User $user): User
    {
        try {
            $user->update([
                'is_out_of_office' => false,
                'out_of_office_delegate_user_id' => null,
            ]);

            event(new UserMarkedBackInOffice($user));

            return $user->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to mark user back in office', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'user.back_in_office',
                'entity_type' => 'user',
                'entity_id' => $user->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(array $filters): CursorPaginator
    {
        $query = User::query()->with('currentPositionAssignment.position.department');

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['account_type'])) {
            $query->where('account_type', (int) $filters['account_type']);
        }

        if (isset($filters['department_id'])) {
            $dept = Department::where('public_id', $filters['department_id'])->first();
            if ($dept) {
                $positionIds = Position::where('department_id', $dept->id)->pluck('id');
                $userIds = UserPositionAssignment::whereIn('position_id', $positionIds)
                    ->whereNull('ended_at')
                    ->pluck('user_id');
                $query->whereIn('id', $userIds);
            }
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'ilike', "%{$search}%")
                    ->orWhere('name_en', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('employee_id', 'ilike', "%{$search}%");
            });
        }

        return $query->orderBy('id')->cursorPaginate($filters['per_page'] ?? 15);
    }
}
