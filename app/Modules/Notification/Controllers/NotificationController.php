<?php

namespace App\Modules\Notification\Controllers;

use App\Modules\Notification\Requests\ListNotificationsRequest;
use App\Modules\Notification\Resources\NotificationResource;
use App\Modules\Notification\Services\NotificationReadService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class NotificationController extends Controller
{
    use HasRateLimiting;

    public function __construct(private NotificationReadService $readService) {}

    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $query = $request->user()->notifications()->orderByDesc('id');
        $filter = $request->validated()['read'] ?? 'all';

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $paginator = $query->cursorPaginate($request->integer('per_page', 15));

        $data = $paginator->items();
        $items = array_map(fn ($item) => new NotificationResource($item), $data);

        return response()->json([
            'data' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return response()->json([
            'unread_count' => $this->readService->unreadCount($request->user()),
        ]);
    }

    public function markRead(Request $request, string $notification): Response
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->readService->markRead($request->user(), $notification);

        return response()->noContent();
    }

    public function markAllRead(Request $request): Response
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->readService->markAllRead($request->user());

        return response()->noContent();
    }
}
