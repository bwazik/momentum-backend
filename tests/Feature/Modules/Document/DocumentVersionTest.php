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
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Version Test',
        'name_ar' => 'اختبار الإصدارات',
        'slug' => 'version-test-'.uniqid(),
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
});

it('creates a new version of a document', function () {
    $file = UploadedFile::fake()->create('v2.pdf', 200, 'application/pdf');

    $response = $this->withHeaders($this->authHeaders)
        ->post("/v1/documents/{$this->document->public_id}/versions", [
            'file' => $file,
        ]);

    $response->assertCreated()
        ->assertJsonPath('version_number', 2)
        ->assertJsonStructure(['public_id', 'original_filename', 'version_number']);
});

it('lists version history', function () {
    $file = UploadedFile::fake()->create('v2.pdf', 200, 'application/pdf');
    $this->withHeaders($this->authHeaders)
        ->post("/v1/documents/{$this->document->public_id}/versions", ['file' => $file]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/documents/{$this->document->public_id}/versions");

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'version_number']], 'next_cursor', 'has_more'])
        ->assertJsonCount(2, 'data');
});

it('soft-deletes entire document chain', function () {
    $file = UploadedFile::fake()->create('v2.pdf', 200, 'application/pdf');
    $versionResponse = $this->withHeaders($this->authHeaders)
        ->post("/v1/documents/{$this->document->public_id}/versions", ['file' => $file]);
    $versionPublicId = $versionResponse->json('public_id');

    $response = $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/documents/{$this->document->public_id}");

    $response->assertStatus(204);

    expect(Document::where('public_id', $this->document->public_id)->exists())->toBeFalse();
    expect(Document::where('public_id', $versionPublicId)->exists())->toBeFalse();

    expect(Document::withTrashed()->where('public_id', $this->document->public_id)->exists())->toBeTrue();
    expect(Document::withTrashed()->where('public_id', $versionPublicId)->exists())->toBeTrue();
});
