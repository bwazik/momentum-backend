<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Search\Models\UserRecentActivity;
use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Test Tenant',
        'name_ar' => 'اختبار',
        'slug' => 'test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create();
    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('returns last 20 distinct tasks for authenticated user', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $tasks = Task::factory()->active()->count(25)->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    $tasks->each(function ($task) {
        UserRecentActivity::create([
            'user_id' => $this->user->id,
            'task_id' => $task->id,
            'activity_type' => SearchActivityType::TaskViewed,
            'occurred_at' => now(),
        ]);
    });

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/recent');

    $response->assertOk()
        ->assertJsonCount(20, 'data');
});

it('deduplicates same task returning most recent activity type', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $task->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now()->subHour(),
    ]);
    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $task->id,
        'activity_type' => SearchActivityType::StageCompleted,
        'occurred_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/recent');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.activity_type', 'StageCompleted');
});

it('excludes soft-deleted tasks but keeps cancelled', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $activeTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);
    $deletedTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);
    $cancelledTask = Task::factory()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'status' => TaskStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $deletedTask->delete();

    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $activeTask->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);
    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $deletedTask->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);
    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $cancelledTask->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/recent');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('isolates activity between users', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $taskA = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);
    $taskB = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    $otherUser = User::factory()->create();

    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $taskA->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);
    UserRecentActivity::create([
        'user_id' => $otherUser->id,
        'task_id' => $taskB->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/recent');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('prune command removes rows older than 90 days', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $task->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now()->subDays(91),
    ]);

    $this->artisan('search:prune-recent-activity')->assertSuccessful();

    $remaining = UserRecentActivity::count();
    expect($remaining)->toBe(0);
});

it('keeps rows within retention period after prune', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $task->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now()->subDays(30),
    ]);

    $this->artisan('search:prune-recent-activity')->assertSuccessful();

    $remaining = UserRecentActivity::count();
    expect($remaining)->toBe(1);
});

it('orders recent activity by most recent interaction first', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $olderTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Older Task',
    ]);
    $newerTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Newer Task',
    ]);

    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $olderTask->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now()->subHour(),
    ]);
    UserRecentActivity::create([
        'user_id' => $this->user->id,
        'task_id' => $newerTask->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/recent');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title_en', 'Newer Task')
        ->assertJsonPath('data.1.title_en', 'Older Task');
});

it('deduplicates task viewed writes within five minutes', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

    $service = app(SearchActivityService::class);
    $service->recordView($this->user, $task);
    $service->recordView($this->user, $task);

    expect(UserRecentActivity::count())->toBe(1);
});

it('applies abac visibility filtering to recent activity', function () {
    $orgCapability = Capability::where('key', 'task.view.organization')->first();
    $otherUser = User::factory()->create();
    if ($orgCapability) {
        UserCapabilityGrant::create([
            'user_id' => $otherUser->id,
            'capability_id' => $orgCapability->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $priority = TaskPriority::where('is_default', true)->first();
    $confidentialTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Confidential Recent Task',
        'classification_level' => ClassificationLevel::Confidential,
    ]);

    UserRecentActivity::create([
        'user_id' => $otherUser->id,
        'task_id' => $confidentialTask->id,
        'activity_type' => SearchActivityType::TaskViewed,
        'occurred_at' => now(),
    ]);

    $this->actingAs($otherUser, 'sanctum');
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/recent');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});
