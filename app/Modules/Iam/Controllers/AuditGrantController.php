<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Iam\Requests\GrantAuditGrantRequest;
use App\Modules\Iam\Resources\AuditGrantResource;
use App\Modules\Iam\Services\AuditGrantService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditGrantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private AuditGrantService $auditGrantService,
    ) {}

    public function index(User $user): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        $grants = AuditGrant::where('external_auditor_user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('auditor', 'department')
            ->get();

        return AuditGrantResource::collection($grants);
    }

    public function grant(GrantAuditGrantRequest $request, User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $auditor = User::where('public_id', $request->input('external_auditor_user_id'))->firstOrFail();

        $grant = $this->auditGrantService->grant($auditor, $request->validated(), $request->user());

        return response()->json(
            new AuditGrantResource($grant->load('auditor', 'department')),
            201
        );
    }

    public function revoke(AuditGrant $grant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $grant = $this->auditGrantService->revoke($grant);

        return response()->json(new AuditGrantResource($grant->load('auditor', 'department')));
    }
}
