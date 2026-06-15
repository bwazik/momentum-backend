<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Requests\LoginRequest;
use App\Modules\Iam\Resources\AuthTokenResource;
use App\Modules\Iam\Resources\UserResource;
use App\Modules\Iam\Services\AuthService;
use App\Support\RateLimits;
use App\Traits\AuthenticatedUser;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use AuthenticatedUser, HasRateLimiting;

    public function __construct(
        private AuthService $authService,
    ) {}

    public function login(LoginRequest $request): AuthTokenResource|JsonResponse
    {
        $this->checkRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        $user = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
        );

        $this->clearRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        return new AuthTokenResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(): JsonResource
    {
        return new UserResource(
            $this->authService->getUser($this->user())
        );
    }
}
