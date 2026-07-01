<?php

namespace App\Modules\Iam\Jobs;

use App\Modules\Iam\Services\DelegationExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireDelegationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(public string $tenantSlug) {}

    public function handle(DelegationExpiryService $expiryService): void
    {
        tenancy()->initialize($this->tenantSlug);

        $expiredCount = $expiryService->expire();

        Log::channel('iam')->info('Delegations expired', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'delegation.expire',
            'entity_type' => 'delegation',
            'entity_id' => null,
            'performed_by' => 'system',
            'expired_count' => $expiredCount,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('iam')->error('Delegation expiry job failed', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'delegation.expire_failed',
            'entity_type' => 'delegation',
            'entity_id' => null,
            'performed_by' => 'system',
            'error' => $exception->getMessage(),
        ]);
    }
}
