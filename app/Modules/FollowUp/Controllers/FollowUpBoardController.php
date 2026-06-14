<?php

namespace App\Modules\FollowUp\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FollowUp\Requests\BoardRequest;
use App\Modules\FollowUp\Resources\BoardTaskResource;
use App\Modules\FollowUp\Resources\BottleneckResource;
use App\Modules\FollowUp\Services\FollowUpBoardService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class FollowUpBoardController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private FollowUpBoardService $boardService,
    ) {}

    public function board(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->boardService->board($request->user(), $request->validated());
        $items = $paginator->through(fn ($task) => new BoardTaskResource($task))->items();

        return response()->json([
            'data' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function overdue(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $filters = array_merge($request->validated(), ['status' => 'overdue']);
        $paginator = $this->boardService->board($request->user(), $filters);
        $items = $paginator->through(fn ($task) => new BoardTaskResource($task))->items();

        return response()->json([
            'data' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function atRisk(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $filters = array_merge($request->validated(), ['status' => 'at_risk']);
        $paginator = $this->boardService->board($request->user(), $filters);
        $items = $paginator->through(fn ($task) => new BoardTaskResource($task))->items();

        return response()->json([
            'data' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function bottlenecks(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->boardService->bottlenecks($request->user(), $request->validated());

        return response()->json([
            'data' => BottleneckResource::collection($items),
        ]);
    }
}
