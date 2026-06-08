<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Requests\StoreAuthorityGradeRequest;
use App\Modules\Organization\Requests\UpdateAuthorityGradeRequest;
use App\Modules\Organization\Resources\AuthorityGradeResource;
use App\Modules\Organization\Services\AuthorityGradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuthorityGradeController extends Controller
{
    public function __construct(
        private AuthorityGradeService $authorityGradeService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return AuthorityGradeResource::collection(
            $this->authorityGradeService->listAll()
        );
    }

    public function store(StoreAuthorityGradeRequest $request): JsonResponse
    {
        $grade = $this->authorityGradeService->create($request->validated());

        return response()->json(
            new AuthorityGradeResource($grade),
            201
        );
    }

    public function show(AuthorityGrade $authorityGrade): JsonResponse
    {
        return response()->json(
            new AuthorityGradeResource($authorityGrade)
        );
    }

    public function update(UpdateAuthorityGradeRequest $request, AuthorityGrade $authorityGrade): JsonResponse
    {
        $grade = $this->authorityGradeService->update($authorityGrade, $request->validated());

        return response()->json(new AuthorityGradeResource($grade));
    }

    public function destroy(AuthorityGrade $authorityGrade): JsonResponse
    {
        $this->authorityGradeService->delete($authorityGrade);

        return response()->json(null, 204);
    }
}
