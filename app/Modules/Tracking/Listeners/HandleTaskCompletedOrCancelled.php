<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\TaskCancelled;
use App\Modules\Task\Events\TaskCompleted;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleTaskCompletedOrCancelled
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(TaskCompleted|TaskCancelled $event): void
    {
        try {
            $this->slaTimerService->completeAllTimersForTask($event->task);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle task completion/cancellation', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.complete_all_from_event',
                'entity_type' => 'task',
                'entity_id' => $event->task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
