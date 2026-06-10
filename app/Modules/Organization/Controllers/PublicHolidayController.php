<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\PublicHoliday;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Requests\StorePublicHolidayRequest;
use App\Modules\Organization\Requests\UpdatePublicHolidayRequest;
use App\Modules\Organization\Resources\PublicHolidayResource;
use App\Modules\Organization\Services\CalendarService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicHolidayController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private CalendarService $calendarService,
    ) {}

    public function index(Request $request, WorkingCalendar $workingCalendar): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = $workingCalendar->holidays();

        if ($request->has('year')) {
            $year = $request->integer('year');
            $query->whereYear('holiday_date', $year);
        }

        return PublicHolidayResource::collection(
            $query->orderBy('holiday_date')->get()
        );
    }

    public function store(StorePublicHolidayRequest $request, WorkingCalendar $workingCalendar): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $holiday = $this->calendarService->createHoliday($workingCalendar, $request->validated());

        return response()->json(
            new PublicHolidayResource($holiday),
            201
        );
    }

    public function show(WorkingCalendar $workingCalendar, PublicHoliday $publicHoliday): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        if ($publicHoliday->working_calendar_id !== $workingCalendar->id) {
            abort(404, 'Holiday does not belong to this calendar.');
        }

        return response()->json(
            new PublicHolidayResource($publicHoliday)
        );
    }

    public function update(UpdatePublicHolidayRequest $request, WorkingCalendar $workingCalendar, PublicHoliday $publicHoliday): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        if ($publicHoliday->working_calendar_id !== $workingCalendar->id) {
            abort(404, 'Holiday does not belong to this calendar.');
        }

        $holiday = $this->calendarService->updateHoliday($publicHoliday, $request->validated());

        return response()->json(new PublicHolidayResource($holiday));
    }

    public function destroy(WorkingCalendar $workingCalendar, PublicHoliday $publicHoliday): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        if ($publicHoliday->working_calendar_id !== $workingCalendar->id) {
            abort(404, 'Holiday does not belong to this calendar.');
        }

        $this->calendarService->deleteHoliday($publicHoliday);

        return response()->json(null, 204);
    }
}
