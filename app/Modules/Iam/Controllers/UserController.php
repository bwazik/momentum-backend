<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Requests\ListUsersRequest;
use App\Modules\Iam\Requests\StoreUserRequest;
use App\Modules\Iam\Requests\UpdateUserRequest;
use App\Modules\Iam\Resources\UserDetailResource;
use App\Modules\Iam\Resources\UserResource;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Iam\Services\UserService;
use App\Support\RateLimits;
use App\Traits\AuthenticatedUser;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class UserController extends Controller
{
    use AuthenticatedUser, HasRateLimiting;

    public function __construct(
        private UserService $userService,
        private IamPolicy $policy,
    ) {}

    public function index(ListUsersRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $paginator = $this->userService->list($request->validated())
            ->through(fn ($user) => new UserResource($user));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $user = $this->userService->create($request->validated());

        return response()->json(
            new UserDetailResource($user->load('currentPositionAssignment.position.department')),
            201
        );
    }

    public function show(User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return response()->json(
            new UserDetailResource(
                $user->load('currentPositionAssignment.position.department', 'currentPositionAssignment.position.authorityGrade')
            )
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $user = $this->userService->update($user, $request->validated());

        return response()->json(new UserResource($user));
    }

    public function deactivate(User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $user = $this->userService->deactivate($user);

        return response()->json(new UserResource($user));
    }

    public function reactivate(User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $user = $this->userService->reactivate($user);

        return response()->json(new UserResource($user));
    }

    public function markOutOfOffice(Request $request, User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);
        $this->authorizeOutOfOffice($user);

        $user = $this->userService->markOutOfOffice(
            $user,
            $request->input('out_of_office_delegate_user_id')
                ? User::where('public_id', $request->input('out_of_office_delegate_user_id'))->first()?->id
                : null
        );

        return response()->json(new UserResource($user));
    }

    public function markBackInOffice(User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);
        $this->authorizeOutOfOffice($user);

        $user = $this->userService->markBackInOffice($user);

        return response()->json(new UserResource($user));
    }

    public function profile(): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        $user = $this->user()->load('currentPositionAssignment.position.department');

        return response()->json(new UserResource($user));
    }

    public function updateProfile(UpdateUserRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $data = Arr::except($request->validated(), ['account_type']);

        $user = $this->userService->update($this->user(), $data);

        return response()->json(new UserResource($user));
    }

    private function authorizeOutOfOffice(User $user): void
    {
        $authUser = $this->user();

        if ($authUser->id !== $user->id && ! $this->policy->check($authUser, 'iam.manage_users')) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
