<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Platform\Resources\AuditEventResource;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;

class PlatformAuditEventController extends Controller
{
    use HasRateLimiting;

    public function index(Request $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = AuditEvent::with('user')->orderByDesc('created_at');

        if ($request->has('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json(array_merge(
            $paginator->toArray(),
            ['has_more' => $paginator->hasMorePages()]
        ));
    }
}
