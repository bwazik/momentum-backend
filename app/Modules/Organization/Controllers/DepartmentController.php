<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Requests\StoreDepartmentRequest;
use App\Modules\Organization\Requests\UpdateDepartmentRequest;
use App\Modules\Organization\Resources\DepartmentResource;
use App\Modules\Organization\Resources\DepartmentTreeResource;
use App\Modules\Organization\Services\DepartmentService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private DepartmentService $departmentService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = Department::query()->with('parent');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('parent_department_id')) {
            $parentDept = Department::where('public_id', $request->input('parent_department_id'))->first();
            $parentId = $parentDept?->id;
            $query->when($parentId !== null, fn ($q) => $q->where('parent_department_id', $parentId))
                ->when($parentId === null, fn ($q) => $q->whereRaw('1 = 0'));
        }

        $paginator = $query->orderBy('name_ar')->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($department) => new DepartmentResource($department));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function tree(): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return DepartmentTreeResource::collection($this->departmentService->getTree());
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $department = $this->departmentService->create($request->validated());

        return response()->json(
            new DepartmentResource($department->load('parent')),
            201
        );
    }

    public function show(Department $department): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return response()->json(
            new DepartmentResource($department->load('parent', 'children'))
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $department = $this->departmentService->update($department, $request->validated());

        return response()->json(new DepartmentResource($department->load('parent')));
    }

    public function deactivate(Request $request, Department $department): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $department = $this->departmentService->deactivate(
            $department,
            $request->boolean('cascade_to_children', false)
        );

        return response()->json(new DepartmentResource($department));
    }

    public function reactivate(Department $department): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $department = $this->departmentService->reactivate($department);

        return response()->json(new DepartmentResource($department));
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $this->departmentService->delete($department);

        return response()->json(null, 204);
    }
}
