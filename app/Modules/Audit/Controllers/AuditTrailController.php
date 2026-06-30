<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Requests\ListAuditTrailRequest;
use App\Modules\Audit\Requests\ListMyActivityRequest;
use App\Modules\Audit\Requests\ListSystemAuditRequest;
use App\Modules\Audit\Resources\AuditEventResource;
use App\Modules\Audit\Services\AuditEventService;
use App\Modules\Task\Models\Task;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class AuditTrailController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private AuditEventService $auditEventService,
    ) {}

    public function taskTrail(ListAuditTrailRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->auditEventService->taskTrail($task, $request, $request->user())
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function systemLog(ListSystemAuditRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->auditEventService->systemLog($request, $request->user())
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function myActivity(ListMyActivityRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->auditEventService->myActivity($request, $request->user())
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
