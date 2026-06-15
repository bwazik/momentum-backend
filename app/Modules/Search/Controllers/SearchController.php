<?php

namespace App\Modules\Search\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Search\Requests\SearchTasksRequest;
use App\Modules\Search\Resources\RecentActivityResource;
use App\Modules\Search\Resources\SearchTaskResource;
use App\Modules\Search\Services\SearchService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private SearchService $searchService,
    ) {}

    public function tasks(SearchTasksRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->searchService->searchTasks($request->user(), $request->validated());
        $paginator->through(fn ($task) => new SearchTaskResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function search(SearchTasksRequest $request)
    {
        return $this->tasks($request);
    }

    public function recent(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $tasks = $this->searchService->recentActivity($request->user());

        return response()->json([
            'data' => RecentActivityResource::collection($tasks),
        ]);
    }
}
