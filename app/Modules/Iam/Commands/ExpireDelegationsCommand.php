<?php

namespace App\Modules\Iam\Commands;

use App\Models\Tenant;
use App\Modules\Iam\Jobs\ExpireDelegationsJob;
use Illuminate\Console\Command;

class ExpireDelegationsCommand extends Command
{
    protected $signature = 'iam:expire-delegations';

    protected $description = 'Dispatch delegation expiry jobs for all active tenants';

    public function handle(): int
    {
        $tenants = Tenant::where('is_active', true)->get();

        foreach ($tenants as $tenant) {
            ExpireDelegationsJob::dispatch($tenant->slug);
        }

        $this->info("Dispatched delegation expiry for {$tenants->count()} tenants.");

        return self::SUCCESS;
    }
}
