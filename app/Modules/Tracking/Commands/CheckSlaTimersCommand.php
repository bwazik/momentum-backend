<?php

namespace App\Modules\Tracking\Commands;

use App\Models\Tenant;
use App\Modules\Tracking\Jobs\CheckSlaTimersJob;
use Illuminate\Console\Command;

class CheckSlaTimersCommand extends Command
{
    protected $signature = 'tracking:check-sla-timers';

    protected $description = 'Dispatch SLA timer check jobs for all active tenants';

    public function handle(): int
    {
        $tenants = Tenant::where('is_active', true)->get();

        foreach ($tenants as $tenant) {
            CheckSlaTimersJob::dispatch($tenant->slug);
        }

        $this->info("Dispatched SLA checks for {$tenants->count()} tenants.");

        return self::SUCCESS;
    }
}
