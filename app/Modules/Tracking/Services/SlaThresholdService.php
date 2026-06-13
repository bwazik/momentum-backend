<?php

namespace App\Modules\Tracking\Services;

use App\Modules\Tracking\Enums\SlaTimerStatus;
use App\Modules\Tracking\Events\SlaBreached;
use App\Modules\Tracking\Events\SlaWarningTriggered;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlaThresholdService
{
    public function __construct(
        private SlaEscalationService $escalationService,
    ) {}

    public function processWarnings(): int
    {
        $count = 0;

        SlaTimerInstance::dueWarning()
            ->chunkById(100, function ($timers) use (&$count) {
                foreach ($timers as $timer) {
                    try {
                        DB::transaction(function () use ($timer) {
                            $fresh = SlaTimerInstance::lockForUpdate()->find($timer->id);
                            if (! $fresh || $fresh->status !== SlaTimerStatus::Running) {
                                return;
                            }

                            $fresh->update(['status' => SlaTimerStatus::Warning]);
                            event(new SlaWarningTriggered($fresh));
                        });
                        $count++;
                    } catch (\Throwable $e) {
                        Log::channel('tracking')->error('Failed to process SLA warning', [
                            'tenant_slug' => tenant()?->slug ?? 'central',
                            'action' => 'sla_timer.warning',
                            'entity_type' => 'sla_timer_instance',
                            'entity_id' => $timer->public_id,
                            'performed_by' => 'system',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }

    public function processBreaches(): int
    {
        $count = 0;

        SlaTimerInstance::dueBreach()
            ->chunkById(100, function ($timers) use (&$count) {
                foreach ($timers as $timer) {
                    try {
                        DB::transaction(function () use ($timer) {
                            $fresh = SlaTimerInstance::lockForUpdate()->find($timer->id);
                            if (! $fresh || $fresh->status->isTerminal() || $fresh->status === SlaTimerStatus::Paused) {
                                return;
                            }

                            $fresh->update(['status' => SlaTimerStatus::Breached]);
                            event(new SlaBreached($fresh));

                            $this->escalationService->createAutoEscalation($fresh);
                        });
                        $count++;
                    } catch (\Throwable $e) {
                        Log::channel('tracking')->error('Failed to process SLA breach', [
                            'tenant_slug' => tenant()?->slug ?? 'central',
                            'action' => 'sla_timer.breach',
                            'entity_type' => 'sla_timer_instance',
                            'entity_id' => $timer->public_id,
                            'performed_by' => 'system',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $count;
    }
}
