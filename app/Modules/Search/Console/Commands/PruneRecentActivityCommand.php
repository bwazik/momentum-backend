<?php

namespace App\Modules\Search\Console\Commands;

use App\Modules\Search\Models\UserRecentActivity;
use Illuminate\Console\Command;

class PruneRecentActivityCommand extends Command
{
    protected $signature = 'search:prune-recent-activity {--days=90}';

    protected $description = 'Prune user recent activity older than the given number of days.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $count = UserRecentActivity::where('occurred_at', '<', $cutoff)->delete();
        $this->info("Pruned {$count} recent activity rows older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
