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
    public function login(string $email, string $password, string $ip): array
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

            $token = $user->createToken('platform-admin')->plainTextToken;

            event(new PlatformAdminLoggedIn($user, $ip));

            return ['user' => $user, 'token' => $token];
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

    public function logout(User $user, bool $allDevices, string $ip): void
    {
        try {
            if ($allDevices) {
                $user->tokens()->delete();
            } else {
                $token = $user->currentAccessToken();

                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }
            }

            event(new PlatformAdminLoggedOut($user, $ip, $allDevices));
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin logout failed', [
                'action' => 'platform_admin.logout',
                'entity_id' => $user->public_id,
                'all_devices' => $allDevices,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
