<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\FollowUp\Enums\FollowUpActionType;
use App\Modules\FollowUp\Models\FollowUpAction;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Models\Task;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'FollowUp Action Test',
        'name_ar' => 'اختبار',
        'slug' => 'fua-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->department = Department::factory()->create(['is_active' => true]);

    $this->user = User::factory()->tenantAdmin()->create();
    $this->actingAs($this->user, 'sanctum');

    $orgCapability = Capability::where('key', 'task.view.organization')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $orgCapability->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $this->task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
    ]);
    $this->task->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $this->department->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

// --- Create ---

it('creates a follow-up action', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/follow-up/tasks/'.$this->task->public_id.'/actions', [
            'action_type' => FollowUpActionType::PhoneCall->value,
            'note_ar' => 'تم الاتصال بالمسؤول',
            'note_en' => 'Called the responsible person',
            'contact_name' => 'أحمد',
        ]);

    $response->assertCreated()
        ->assertJsonPath('action_type', 'PhoneCall')
        ->assertJsonPath('note_ar', 'تم الاتصال بالمسؤول')
        ->assertJsonPath('note_en', 'Called the responsible person')
        ->assertJsonPath('contact_name', 'أحمد');

    $this->assertDatabaseHas('follow_up_actions', [
        'task_id' => $this->task->id,
        'action_type' => FollowUpActionType::PhoneCall->value,
    ]);
});

it('falls back to note_ar when note_en is empty', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/follow-up/tasks/'.$this->task->public_id.'/actions', [
            'action_type' => FollowUpActionType::PhoneCall->value,
            'note_ar' => 'نص ملاحظة عربي فقط',
        ]);

    $response->assertCreated()
        ->assertJsonPath('note_en', 'نص ملاحظة عربي فقط');
});

// --- List ---

it('lists actions in chronological order', function () {
    FollowUpAction::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'action_type' => FollowUpActionType::PhoneCall,
        'note_ar' => 'First action',
        'created_at' => now()->subDay(),
    ]);
    FollowUpAction::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'action_type' => FollowUpActionType::Message,
        'note_ar' => 'Second action',
        'created_at' => now(),
    ]);

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->getJson('/v1/follow-up/tasks/'.$this->task->public_id.'/actions');

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.note_ar', 'First action')
        ->assertJsonPath('data.1.note_ar', 'Second action');
});

// --- Validation & Authorization ---

it('denies action creation without capability', function () {
    $userWithoutCapability = User::factory()->create();
    $this->actingAs($userWithoutCapability, 'sanctum');

    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/follow-up/tasks/'.$this->task->public_id.'/actions', [
            'action_type' => FollowUpActionType::PhoneCall->value,
            'note_ar' => 'Test',
        ]);

    $response->assertForbidden();
});

it('denies action creation on invisible task despite having capability', function () {
    $followUpUser = User::factory()->create();
    $followUpCap = Capability::where('key', 'task.view.follow_up_scope')->first();
    UserCapabilityGrant::create([
        'user_id' => $followUpUser->id,
        'capability_id' => $followUpCap->id,
        'scope_type' => ScopeType::SPECIFIC_DEPARTMENT,
        'scope_department_id' => $this->department->id,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test',
    ]);

    $otherDepartment = Department::factory()->create(['is_active' => true]);
    $invisibleTask = Task::factory()->active()->create(['initiator_user_id' => $this->user->id]);
    $invisibleTask->stageInstances()->create([
        'blueprint_stage_id' => BlueprintStage::factory()->create()->id,
        'sequence_order' => 1,
        'owning_department_id' => $otherDepartment->id,
        'status' => StageInstanceStatus::Active,
        'completion_rule' => CompletionRule::AnyAssignee->value,
        'entered_at' => now(),
    ]);

    $this->actingAs($followUpUser, 'sanctum');
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/follow-up/tasks/'.$invisibleTask->public_id.'/actions', [
            'action_type' => FollowUpActionType::PhoneCall->value,
            'note_ar' => 'Test',
        ]);

    $response->assertNotFound();
});

it('returns 422 when action_type is missing', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/follow-up/tasks/'.$this->task->public_id.'/actions', [
            'note_ar' => 'Test',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('action_type');
});

it('returns 422 when note_ar is missing', function () {
    $response = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/follow-up/tasks/'.$this->task->public_id.'/actions', [
            'action_type' => FollowUpActionType::PhoneCall->value,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('note_ar');
});
