<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Requests\StoreWorkingCalendarRequest;
use App\Modules\Organization\Requests\UpdateWorkingCalendarRequest;
use App\Modules\Organization\Resources\WorkingCalendarResource;
use App\Modules\Organization\Services\CalendarService;
use App\Modules\Organization\Services\WorkingDayCalculator;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkingCalendarController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private CalendarService $calendarService,
        private WorkingDayCalculator $workingDayCalculator,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return WorkingCalendarResource::collection(
            $this->calendarService->listCalendars()
        );
    }

    public function store(StoreWorkingCalendarRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $calendar = $this->calendarService->createCalendar($request->validated());

        return response()->json(
            new WorkingCalendarResource($calendar),
            201
        );
    }

    public function show(WorkingCalendar $workingCalendar): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return response()->json(
            new WorkingCalendarResource($workingCalendar)
        );
    }

    public function update(UpdateWorkingCalendarRequest $request, WorkingCalendar $workingCalendar): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $calendar = $this->calendarService->updateCalendar($workingCalendar, $request->validated());

        return response()->json(new WorkingCalendarResource($calendar));
    }

    public function destroy(WorkingCalendar $workingCalendar): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $this->calendarService->deleteCalendar($workingCalendar);

        return response()->json(null, 204);
    }

    public function isWorkingDay(Request $request, WorkingCalendar $workingCalendar): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = Carbon::parse($request->input('date'));
        $result = $this->workingDayCalculator->isWorkingDay($workingCalendar, $date);

        return response()->json([
            'date' => $date->toDateString(),
            'is_working_day' => $result,
        ]);
    }
}
