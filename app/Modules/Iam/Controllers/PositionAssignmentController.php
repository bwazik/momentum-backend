<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Iam\Requests\AssignPositionRequest;
use App\Modules\Iam\Requests\EndPositionRequest;
use App\Modules\Iam\Resources\PositionAssignmentResource;
use App\Modules\Iam\Services\PositionAssignmentService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;

class PositionAssignmentController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private PositionAssignmentService $assignmentService,
    ) {}

    public function assign(AssignPositionRequest $request, User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $assignment = $this->assignmentService->assign($user, $request->input('position_id'), $request->validated());

        return response()->json(
            new PositionAssignmentResource($assignment->load('position.department', 'position.authorityGrade')),
            201
        );
    }

    public function end(EndPositionRequest $request, User $user, UserPositionAssignment $assignment): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $assignment = $this->assignmentService->end($assignment, $request->validated());

        return response()->json(new PositionAssignmentResource($assignment));
    }

    public function setPrimary(User $user, UserPositionAssignment $assignment): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $assignment = $this->assignmentService->setPrimary($assignment);

        return response()->json(new PositionAssignmentResource($assignment->load('position.department', 'position.authorityGrade')));
    }
}
