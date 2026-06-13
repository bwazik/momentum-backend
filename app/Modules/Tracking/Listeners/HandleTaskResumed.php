<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\TaskResumed;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleTaskResumed
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(TaskResumed $event): void
    {
        try {
            $this->slaTimerService->resumeAllTimersForTask($event->task);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to resume timers on task resumption', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.resume_all',
                'entity_type' => 'task',
                'entity_id' => $event->task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
