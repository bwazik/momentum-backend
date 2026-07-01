<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Requests\ListActiveDelegationsRequest;
use App\Modules\Iam\Requests\StoreDelegationRequest;
use App\Modules\Iam\Requests\UpdateDelegationRequest;
use App\Modules\Iam\Resources\DelegationResource;
use App\Modules\Iam\Services\DelegationService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DelegationController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private DelegationService $delegationService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = Delegation::with('delegator', 'delegate');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->boolean('active_now')) {
            $query->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now());

            $paginator = $query->orderBy('id')->cursorPaginate($request->integer('per_page', 15))
                ->through(fn ($delegation) => new DelegationResource($delegation));

            return response()->json([
                'data' => $paginator->items(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ]);
        }

        if ($request->has('delegator_user_id')) {
            $query->whereHas('delegator', fn ($q) => $q->where('public_id', $request->input('delegator_user_id')));
        }

        if ($request->has('delegate_user_id')) {
            $query->whereHas('delegate', fn ($q) => $q->where('public_id', $request->input('delegate_user_id')));
        }

        return DelegationResource::collection($query->orderByDesc('created_at')->get());
    }

    public function active(ListActiveDelegationsRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = Delegation::with(['delegator', 'delegate', 'blueprintCategory', 'stageType'])
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());

        if ($request->filled('delegator_user_id')) {
            $query->whereHas('delegator', fn ($q) => $q->where('public_id', $request->input('delegator_user_id')));
        }

        if ($request->filled('delegate_user_id')) {
            $query->whereHas('delegate', fn ($q) => $q->where('public_id', $request->input('delegate_user_id')));
        }

        if ($request->filled('blueprint_category_id')) {
            $categoryId = BlueprintCategory::where('public_id', $request->input('blueprint_category_id'))->value('id');
            $query->where('blueprint_category_id', $categoryId);
        }

        if ($request->filled('stage_type_id')) {
            $stageTypeId = StageType::where('public_id', $request->input('stage_type_id'))->value('id');
            $query->where('stage_type_id', $stageTypeId);
        }

        $paginator = $query->orderBy('id')->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($delegation) => new DelegationResource($delegation));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreDelegationRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $data = $request->validated();
        $delegatorId = $data['delegator_user_id'] ?? $request->user()->public_id;
        $delegator = User::where('public_id', $delegatorId)->firstOrFail();

        $delegation = $this->delegationService->create($data, $delegator);

        return response()->json(
            new DelegationResource($delegation->load('delegator', 'delegate')),
            201
        );
    }

    public function update(UpdateDelegationRequest $request, Delegation $delegation): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $data = $request->validated();
        $delegation->update($data);

        return response()->json(
            new DelegationResource($delegation->fresh()->load('delegator', 'delegate'))
        );
    }

    public function show(Delegation $delegation): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return response()->json(
            new DelegationResource($delegation->load('delegator', 'delegate'))
        );
    }

    public function revoke(Delegation $delegation): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $delegation = $this->delegationService->revoke($delegation);

        return response()->json(
            new DelegationResource($delegation->load('delegator', 'delegate'))
        );
    }
}
