<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Models\Document;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
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
        'name_en' => 'Download Test',
        'name_ar' => 'اختبار التحميل',
        'slug' => 'download-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);

    User::factory()->tenantAdmin()->create([
        'email' => 'admin@'.$this->tenant->slug.'.test',
        'password' => bcrypt('password'),
    ]);

    $this->seed(TenantDatabaseSeeder::class);

    $this->category = BlueprintCategory::factory()->create();
    $this->blueprint = Blueprint::factory()->create([
        'category_id' => $this->category->id,
        'created_by_user_id' => User::factory()->create()->id,
    ]);
    $this->priority = TaskPriority::where('is_default', true)->first();

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $manageDocs = Capability::where('key', 'task.manage_documents')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $manageDocs->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $viewOrg = Capability::where('key', 'task.view.organization')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $viewOrg->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $viewDocs = Capability::where('key', 'task.view_documents')->first();
    UserCapabilityGrant::create([
        'user_id' => $this->user->id,
        'capability_id' => $viewDocs->id,
        'scope_type' => ScopeType::TENANT,
        'granted_by_user_id' => $this->user->id,
        'granted_at' => now(),
        'reason' => 'Test grant',
    ]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];

    $this->task = Task::factory()->create([
        'blueprint_id' => $this->blueprint->id,
        'priority_id' => $this->priority->id,
        'initiator_user_id' => $this->user->id,
    ]);

    $this->document = Document::factory()->create([
        'entity_type' => DocumentEntityType::Task,
        'entity_id' => $this->task->id,
        'uploader_user_id' => $this->user->id,
    ]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    cleanupTenantStorage($this->tenant->id);
    $this->tenant->delete();
});

it('shows document metadata', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/documents/{$this->document->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $this->document->public_id)
        ->assertJsonPath('original_filename', $this->document->original_filename)
        ->assertJsonStructure(['download_url', 'preview_url', 'uploader']);
});

it('returns 404 for non-existent document', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/documents/non-existent-uuid');

    $response->assertStatus(404);
});

it('returns 403 when viewing document without task access', function () {
    $otherUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/auth/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ]);
    $token = $loginResponse->json('token');
    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($headers)
        ->getJson("/v1/documents/{$this->document->public_id}");

    $response->assertForbidden();
});

it('returns 422 when previewing unsupported file type', function () {
    $wordDoc = Document::factory()->create([
        'entity_type' => DocumentEntityType::Task,
        'entity_id' => $this->task->id,
        'uploader_user_id' => $this->user->id,
        'mime_type' => 'application/msword',
        'original_filename' => 'document.doc',
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/documents/{$wordDoc->public_id}/preview");

    $response->assertStatus(422)
        ->assertJsonPath('message', 'This file type cannot be previewed inline.');
});
