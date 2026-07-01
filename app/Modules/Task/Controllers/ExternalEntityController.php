<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Requests\StoreExternalEntityRequest;
use App\Modules\Task\Requests\UpdateExternalEntityRequest;
use App\Modules\Task\Resources\ExternalEntityResource;
use App\Modules\Task\Services\ExternalEntityService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class ExternalEntityController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ExternalEntityService $entityService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return ExternalEntityResource::collection($this->entityService->getActive());
    }

    public function store(StoreExternalEntityRequest $request): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $entity = $this->entityService->create($request->validated(), $request->user());

        return new ExternalEntityResource($entity);
    }

    public function show(Request $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new ExternalEntityResource($entity);
    }

    public function update(UpdateExternalEntityRequest $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $entity = $this->entityService->update($entity, $request->validated(), $request->user());

        return new ExternalEntityResource($entity);
    }

    public function deactivate(Request $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        return new ExternalEntityResource($this->entityService->deactivate($entity, $request->user()));
    }

    public function reactivate(Request $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        return new ExternalEntityResource($this->entityService->reactivate($entity, $request->user()));
    }
}
