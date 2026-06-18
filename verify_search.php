<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = \App\Models\Tenant::where('slug', 'moh')->firstOrFail();
tenancy()->initialize($tenant);

echo "=== CAPABILITY GRANTS ===\n";
\App\Modules\Iam\Models\UserCapabilityGrant::with('capability')->get()->each(fn ($g) => printf("  user=%d cap=%s scope=%d reason=%s\n", $g->user_id, $g->capability->key, $g->scope_type->value, $g->reason));

echo "\n=== SEARCH TEST (simulating SearchService::searchTasks) ===\n";
$admin = \App\Models\User::where('email', 'admin@moh.test')->first();
$service = app(\App\Modules\Search\Services\SearchService::class);

$queries = ['System', 'Platform', 'Security', 'Health', 'Portal', 'Data', 'system', 'platform'];
foreach ($queries as $q) {
    $results = $service->searchTasks($admin, ['q' => $q, 'per_page' => 25]);
    printf("\"%s\": %d results\n", $q, $results->total());
    foreach ($results->items() as $task) {
        printf("  id=%d status=%s title=%s\n", $task->id, $task->status->name, $task->title_en);
    }
}

// Also check which tasks are NOT visible (draft / confidential)
echo "\n=== ALL EXISTING TASKS ===\n";
\App\Modules\Task\Models\Task::withoutGlobalScopes()->get()->each(fn ($t) => printf("  id=%d status=%s class=%s title=%s\n", $t->id, $t->status->name, $t->classification_level->name, $t->title_en));

echo "\nDONE\n";
