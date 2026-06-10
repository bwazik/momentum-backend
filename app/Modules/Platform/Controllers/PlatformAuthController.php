<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Requests\PlatformLoginRequest;
use App\Modules\Platform\Resources\PlatformAdminResource;
use App\Modules\Platform\Resources\PlatformAuthResource;
use App\Modules\Platform\Services\PlatformAuthService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlatformAuthController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private PlatformAuthService $authService,
    ) {}

    public function login(PlatformLoginRequest $request): PlatformAuthResource
    {
        $this->checkRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        $result = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
            $request->ip(),
        );

        $this->clearRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        return new PlatformAuthResource($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout(
            $request->user(),
            (bool) $request->boolean('all_devices'),
            $request->ip(),
        );

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(
            new PlatformAdminResource($request->user())
        );
    }
}
