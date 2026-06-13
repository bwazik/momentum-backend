<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\TaskSuspended;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleTaskSuspended
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(TaskSuspended $event): void
    {
        try {
            $this->slaTimerService->pauseAllTimersForTask($event->task);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to pause timers on task suspension', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.pause_all',
                'entity_type' => 'task',
                'entity_id' => $event->task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
