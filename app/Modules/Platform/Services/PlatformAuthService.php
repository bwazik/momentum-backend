<?php

namespace App\Modules\Platform\Services;

use App\Enums\AccountType;
use App\Models\User;
use App\Modules\Platform\Events\PlatformAdminLoggedIn;
use App\Modules\Platform\Events\PlatformAdminLoggedOut;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformAuthService
{
    public function login(string $email, string $password, string $ip): User
    {
        try {
            $user = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->first();

            if (! $user || ! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => __('auth.failed'),
                ]);
            }

            if ($user->account_type !== AccountType::PLATFORM_ADMIN) {
                throw ValidationException::withMessages([
                    'email' => __('auth.failed'),
                ]);
            }

            if (! $user->is_active || $user->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'email' => __('auth.failed'),
                ]);
            }

            Auth::guard('web')->login($user);

            event(new PlatformAdminLoggedIn($user, $ip));

            return $user;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin login failed', [
                'action' => 'platform_admin.login',
                'entity_type' => 'platform_admin',
                'email' => $email,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function logout(User $user, string $ip): void
    {
        try {
            event(new PlatformAdminLoggedOut($user, $ip, false));
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin logout failed', [
                'action' => 'platform_admin.logout',
                'entity_id' => $user->public_id,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
