<?php

namespace App\Modules\Tracking\Jobs;

use App\Modules\Tracking\Services\SlaThresholdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CheckSlaTimersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(public string $tenantSlug) {}

    public function handle(SlaThresholdService $thresholdService): void
    {
        tenancy()->initialize($this->tenantSlug);

        $warnings = $thresholdService->processWarnings();
        $breaches = $thresholdService->processBreaches();

        Log::channel('tracking')->info('SLA timer check completed', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'sla_timer.scheduled_check',
            'entity_type' => 'sla_timer_instance',
            'entity_id' => null,
            'performed_by' => 'system',
            'warnings_processed' => $warnings,
            'breaches_processed' => $breaches,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('tracking')->error('SLA timer check job failed', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'sla_timer.scheduled_check_failed',
            'entity_type' => 'sla_timer_instance',
            'entity_id' => null,
            'performed_by' => 'system',
            'error' => $exception->getMessage(),
        ]);
    }
}
