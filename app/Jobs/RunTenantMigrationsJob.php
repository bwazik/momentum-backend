<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Modules\Platform\Events\TenantMigrated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Jobs\MigrateDatabase;

class RunTenantMigrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public Tenant $tenant,
        public int $adminUserId,
        public string $adminPublicId,
        public string $ip,
    ) {}

    public function handle(): void
    {
        try {
            tenancy()->initialize($this->tenant);

            $migrateJob = new MigrateDatabase($this->tenant);
            $migrateJob->handle();

            tenancy()->end();

            event(new TenantMigrated($this->tenant, $this->adminUserId, $this->ip, 'completed'));

            Log::channel('platform')->info('Tenant migrations completed', [
                'action' => 'tenant.run_migrations',
                'entity_id' => $this->tenant->public_id,
            ]);
        } catch (\Throwable $e) {
            tenancy()->end();

            event(new TenantMigrated($this->tenant, $this->adminUserId, $this->ip, 'failed', $e->getMessage()));

            Log::channel('platform')->error('Tenant migrations failed', [
                'action' => 'tenant.run_migrations',
                'entity_id' => $this->tenant->public_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
