<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use App\Modules\Search\Models\TaskSearchIndex;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Task\Models\TaskStageInstance;
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

    $orgCapability = Capability::where('key', 'task.view.organization')->first();
    if ($orgCapability) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $orgCapability->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('returns 422 when q is too short', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=a');

    $response->assertStatus(422);
});

it('returns 422 when q is empty', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=');

    $response->assertStatus(422);
});

it('returns 422 when q is missing entirely', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks');

    $response->assertStatus(422);
});

it('finds tasks by English title', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Budget Ceiling Approval',
        'description_en' => 'Request for budget ceiling increase',
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Other Task',
        'description_en' => 'Unrelated content',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Budget');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Budget Ceiling Approval');
});

it('excludes draft tasks from search results', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->draft()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Draft Task Unique',
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Active Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Draft');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters by status', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Active Task One',
    ]);
    Task::factory()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Completed Task',
        'status' => TaskStatus::Completed,
        'completed_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Task&status[]=active');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Active Task One');
});

it('excludes confidential tasks for non-participant users', function () {
    $orgCapability = Capability::where('key', 'task.view.organization')->first();
    $otherUser = User::factory()->create();
    UserCapabilityGrant::create([
        'user_id' => $otherUser->id,
        'capability_id' => $orgCapability->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Confidential Task',
        'classification_level' => ClassificationLevel::Confidential,
    ]);

    $this->actingAs($otherUser, 'sanctum');
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Confidential');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('paginates results with correct envelope shape', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->active()->count(25)->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Paginatable Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Paginatable&per_page=10');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(10, 'data');
});

it('applies blueprint category filter', function () {
    $category = BlueprintCategory::factory()->create();
    $blueprint = Blueprint::factory()->create(['category_id' => $category->id]);
    $priority = TaskPriority::where('is_default', true)->first();

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'blueprint_id' => $blueprint->id,
        'title_en' => 'Category Filtered Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Category&blueprint_category_id='.$category->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns 422 for external reference when 014 not available', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=test&external_reference=REF-001');

    $response->assertStatus(422)
        ->assertJson(['message' => 'External reference search is not yet available.']);
});

it('applies priority filter with multiple UUIDs', function () {
    $critical = TaskPriority::where('name_en', 'Critical')->first();
    $urgent = TaskPriority::where('name_en', 'Urgent')->first();

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $critical->id,
        'title_en' => 'Critical Priority Task',
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $urgent->id,
        'title_en' => 'Urgent Priority Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Task&priority_id[]='.$critical->public_id.'&priority_id[]='.$urgent->public_id);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns empty result for user with no task.view capability', function () {
    $noViewUser = User::factory()->create();
    $this->actingAs($noViewUser, 'sanctum');

    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Hidden Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Hidden');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});

it('applies date range filter', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Date Filtered Task',
        'created_at' => now()->subDays(5),
    ]);
    $task->created_at = now()->subDays(5);
    $task->save();

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Too Old Task',
        'created_at' => now()->subDays(15),
    ]);
    $old = Task::where('title_en', 'Too Old Task')->first();
    $old->created_at = now()->subDays(15);
    $old->save();

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Task&date_from='.now()->subDays(10)->format('Y-m-d'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Date Filtered Task');
});

it('finds tasks by Arabic title with prefix/suffix variants', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_ar' => 'متابعة التواصل بين الإدارات',
        'title_en' => 'Communication Follow-up',
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_ar' => 'مهمة غير ذات صلة',
        'title_en' => 'Unrelated Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=تواصل');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_ar', 'متابعة التواصل بين الإدارات');
});

it('finds tasks by completion notes', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_ar' => 'مهمة قانونية',
        'title_en' => 'Legal Task',
    ]);

    TaskSearchIndex::create([
        'task_id' => $task->id,
        'notes_ar' => 'الرأي القانوني: الاعتراض على بند المشتريات',
        'notes_en' => 'Legal opinion: object to procurement clause',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=procurement');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Legal Task');
});

it('filters by active stage owning department', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $department = Department::factory()->create();

    $matchingTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Department Task',
    ]);
    TaskStageInstance::create([
        'task_id' => $matchingTask->id,
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Active,
        'entered_at' => now(),
        'owning_department_id' => $department->id,
    ]);

    $otherTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Other Department Task',
    ]);
    TaskStageInstance::create([
        'task_id' => $otherTask->id,
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'completion_rule' => 1,
        'status' => StageInstanceStatus::Active,
        'entered_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Department&department_id='.$department->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Department Task');
});

it('filters by blueprint', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $blueprint = Blueprint::factory()->create();
    $otherBlueprint = Blueprint::factory()->create();

    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'blueprint_id' => $blueprint->id,
        'title_en' => 'Blueprint Task',
    ]);
    Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'blueprint_id' => $otherBlueprint->id,
        'title_en' => 'Other Blueprint Task',
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Blueprint&blueprint_id='.$blueprint->public_id);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Blueprint Task');
});

it('filters by completed_at date field', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    Task::factory()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Recently Completed Task',
        'status' => TaskStatus::Completed,
        'completed_at' => now()->subDays(3),
    ]);
    Task::factory()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'title_en' => 'Old Completed Task',
        'status' => TaskStatus::Completed,
        'completed_at' => now()->subDays(20),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/search/tasks?q=Completed&date_field=completed_at&date_from='.now()->subDays(10)->format('Y-m-d'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title_en', 'Recently Completed Task');
});
