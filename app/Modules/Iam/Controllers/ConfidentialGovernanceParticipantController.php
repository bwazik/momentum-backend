<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use App\Modules\Iam\Requests\StoreConfidentialGovernanceParticipantRequest;
use App\Modules\Iam\Requests\UpdateConfidentialGovernanceParticipantRequest;
use App\Modules\Iam\Resources\ConfidentialGovernanceParticipantResource;
use App\Modules\Iam\Services\ConfidentialGovernanceParticipantService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfidentialGovernanceParticipantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ConfidentialGovernanceParticipantService $governanceService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->governanceService->list($request->integer('per_page', 15))
            ->through(fn ($config) => new ConfidentialGovernanceParticipantResource($config));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreConfidentialGovernanceParticipantRequest $request): ConfidentialGovernanceParticipantResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $config = $this->governanceService->create($request->validated(), $request->user());

        return new ConfidentialGovernanceParticipantResource($config);
    }

    public function update(UpdateConfidentialGovernanceParticipantRequest $request, ConfidentialGovernanceParticipant $participant): ConfidentialGovernanceParticipantResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $config = $this->governanceService->update($participant, $request->validated(), $request->user());

        return new ConfidentialGovernanceParticipantResource($config);
    }

    public function revoke(Request $request, ConfidentialGovernanceParticipant $participant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->governanceService->revoke($participant, $request->user());

        return response()->json(new ConfidentialGovernanceParticipantResource($participant->fresh()));
    }
}
