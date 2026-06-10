<?php

namespace App\Modules\Iam\Services;

use App\Enums\AccountType;
use App\Models\User;
use App\Modules\Iam\Events\UserLoggedIn;
use App\Modules\Iam\Events\UserLoggedOut;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function login(string $email, string $password): array
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

            if (! $user->is_active || $user->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'email' => __('auth.failed'),
                ]);
            }

            if ($user->account_type === AccountType::PLATFORM_ADMIN) {
                throw ValidationException::withMessages([
                    'email' => __('auth.failed'),
                ]);
            }

            Auth::guard('web')->login($user);

            event(new UserLoggedIn($user));

            $token = $user->createToken('auth-token')->plainTextToken;

            return ['user' => $user, 'token' => $token];
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('IAM login failed', [
                'action' => 'iam.login',
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function logout(User $user, bool $allDevices): void
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

            event(new UserLoggedOut($user));
        } catch (\Throwable $e) {
            Log::channel('iam')->error('IAM logout failed', [
                'action' => 'iam.logout',
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getUser(User $user): User
    {
        return $user->load('currentPositionAssignment.position.department');
    }
}
