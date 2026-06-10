<?php

namespace App\Modules\Iam\Controllers;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Events\UserLoggedIn;
use App\Modules\Iam\Events\UserLoggedOut;
use App\Modules\Iam\Requests\LoginRequest;
use App\Modules\Iam\Resources\AuthTokenResource;
use App\Modules\Iam\Resources\UserResource;
use App\Support\RateLimits;
use App\Traits\AuthenticatedUser;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use AuthenticatedUser, HasRateLimiting;

    public function login(LoginRequest $request): AuthTokenResource|JsonResponse
    {
        $this->checkRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        $credentials = $request->validated();

        $user = User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($request->input('email'))])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active || $user->deleted_at !== null) {
            throw ValidationException::withMessages([
                'email' => __('auth.inactive'),
            ]);
        }

        if ($user->account_type === AccountType::PLATFORM_ADMIN) {
            throw ValidationException::withMessages([
                'email' => __('auth.platform_admin_login_disabled'),
            ]);
        }

        $this->clearRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        Auth::guard('web')->login($user);

        event(new UserLoggedIn($user));

        $token = $user->createToken('auth-token')->plainTextToken;

        return new AuthTokenResource($user, $token);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->user();

        if ($request->boolean('all')) {
            $user->tokens()->delete();
        } else {
            $token = $user->currentAccessToken();

            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
        }

        event(new UserLoggedOut($user));

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(): JsonResource
    {
        return new UserResource($this->user()->load('currentPositionAssignment.position.department'));
    }
}
