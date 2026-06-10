<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Platform\Requests\ImpersonateRequest;
use App\Modules\Platform\Resources\AuditEventResource;
use App\Modules\Platform\Services\PlatformImpersonationService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformImpersonationController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private PlatformImpersonationService $impersonationService,
    ) {}

    public function start(ImpersonateRequest $request, Tenant $tenant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $result = $this->impersonationService->startImpersonation(
            $tenant,
            $request->input('user_public_id'),
            $request->user()->id,
            $request->user()->public_id,
            $request->ip(),
        );

        return response()->json($result);
    }

    public function leave(Request $request, Tenant $tenant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $this->impersonationService->endImpersonation(
            $request->user()->id,
            $request->user()->public_id,
            $tenant->public_id,
            $request->ip(),
        );

        return response()->json(['message' => 'Impersonation session ended.']);
    }

    public function activeSessions(Request $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $paginator = $this->impersonationService->listActiveSessions()
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json(array_merge(
            $paginator->toArray(),
            ['has_more' => $paginator->hasMorePages()]
        ));
    }
}
