<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Requests\AgingReportRequest;
use App\Modules\Analytics\Resources\AgingReportResource;
use App\Modules\Analytics\Services\AgingReportService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class AgingReportController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private AgingReportService $service,
    ) {}

    public function index(AgingReportRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->service->aging($request->user(), $request->validated());

        $this->service->loadSlaHealthForTasks($paginator->items());

        $paginator->through(fn ($task) => new AgingReportResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
