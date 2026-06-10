<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Requests\StorePositionRequest;
use App\Modules\Organization\Requests\TransferPositionRequest;
use App\Modules\Organization\Requests\UpdatePositionRequest;
use App\Modules\Organization\Resources\PositionResource;
use App\Modules\Organization\Services\PositionService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PositionController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private PositionService $positionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = Position::query()->with(['department', 'authorityGrade', 'reportsTo']);

        if ($request->has('department_id')) {
            $deptId = Department::where('public_id', $request->input('department_id'))->value('id');
            $query->where('department_id', $deptId);
        }

        if ($request->has('authority_grade_id')) {
            $gradeId = AuthorityGrade::where('public_id', $request->input('authority_grade_id'))->value('id');
            $query->where('authority_grade_id', $gradeId);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return PositionResource::collection(
            $query->orderBy('title_ar')->cursorPaginate($request->integer('per_page', 15))
        );
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $position = $this->positionService->create($request->validated());

        return response()->json(
            new PositionResource($position->load(['department', 'authorityGrade', 'reportsTo'])),
            201
        );
    }

    public function show(Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return response()->json(
            new PositionResource($position->load(['department', 'authorityGrade', 'reportsTo', 'currentOccupant.user']))
        );
    }

    public function update(UpdatePositionRequest $request, Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $position = $this->positionService->update($position, $request->validated());

        return response()->json(
            new PositionResource($position->load(['department', 'authorityGrade', 'reportsTo']))
        );
    }

    public function transfer(TransferPositionRequest $request, Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $position = $this->positionService->transfer($position, $request->input('department_id'));

        return response()->json(
            new PositionResource($position->load(['department', 'authorityGrade', 'reportsTo']))
        );
    }

    public function deactivate(Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $position = $this->positionService->deactivate($position);

        return response()->json(new PositionResource($position));
    }

    public function reactivate(Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $position = $this->positionService->reactivate($position);

        return response()->json(new PositionResource($position));
    }

    public function destroy(Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $this->positionService->delete($position);

        return response()->json(null, 204);
    }
}
