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
        'name_en' => 'Document Test',
        'name_ar' => 'اختبار المستندات',
        'slug' => 'doc-test-'.uniqid(),
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
});

afterEach(function () {
    $slug = $this->tenant->slug;
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    cleanupTenantStorage($this->tenant->id);
    $this->tenant->delete();
});

it('uploads a document to a task', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $response = $this->withHeaders($this->authHeaders)
        ->post("/v1/tasks/{$this->task->public_id}/documents", [
            'file' => $file,
            'description' => 'Test document',
        ]);

    $response->assertCreated()
        ->assertJsonPath('original_filename', 'document.pdf')
        ->assertJsonPath('mime_type', 'application/pdf')
        ->assertJsonPath('version_number', 1)
        ->assertJsonStructure(['public_id', 'download_url', 'preview_url', 'uploader']);
});

it('lists task documents with cursor pagination', function () {
    Document::factory()->count(3)->create([
        'entity_type' => DocumentEntityType::Task,
        'entity_id' => $this->task->id,
        'uploader_user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/documents");

    $response->assertOk()
        ->assertJsonStructure(['data' => [['public_id', 'original_filename']], 'next_cursor', 'has_more'])
        ->assertJsonCount(3, 'data');
});

it('returns 403 when uploading without manage_documents capability', function () {
    $userWithoutCap = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/iam/auth/login', [
            'email' => $userWithoutCap->email,
            'password' => 'password',
        ]);
    $token = $loginResponse->json('token');
    $headers = [
        'Authorization' => "Bearer {$token}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $response = $this->withHeaders($headers)
        ->post("/v1/tasks/{$this->task->public_id}/documents", [
            'file' => $file,
        ]);

    $response->assertForbidden();
});

it('rejects oversized file with 422', function () {
    $file = UploadedFile::fake()->create('large.pdf', 25000, 'application/pdf');

    $response = $this->withHeaders($this->authHeaders)
        ->post("/v1/tasks/{$this->task->public_id}/documents", [
            'file' => $file,
        ]);

    $response->assertStatus(422);
});

it('rejects disallowed mime type with 422', function () {
    $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

    $response = $this->withHeaders($this->authHeaders)
        ->post("/v1/tasks/{$this->task->public_id}/documents", [
            'file' => $file,
        ]);

    $response->assertStatus(422);
});

it('lists only the latest version after creating a version', function () {
    $uploadResponse = $this->withHeaders($this->authHeaders)
        ->post("/v1/tasks/{$this->task->public_id}/documents", [
            'file' => UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf'),
        ]);
    $documentId = $uploadResponse->json('public_id');

    $this->withHeaders($this->authHeaders)
        ->post("/v1/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->create('v2.pdf', 200, 'application/pdf'),
        ]);

    $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/documents")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.original_filename', 'v2.pdf')
        ->assertJsonPath('data.0.version_number', 2);
});

it('uploader can delete own document without manage_documents capability', function () {
    $uploadResponse = $this->withHeaders($this->authHeaders)
        ->post("/v1/tasks/{$this->task->public_id}/documents", [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);
    $documentId = $uploadResponse->json('public_id');

    $manageDocs = Capability::where('key', 'task.manage_documents')->first();
    UserCapabilityGrant::where('user_id', $this->user->id)
        ->where('capability_id', $manageDocs->id)
        ->delete();

    $this->withHeaders($this->authHeaders)
        ->deleteJson("/v1/documents/{$documentId}")
        ->assertStatus(204);

    expect(Document::withTrashed()->where('public_id', $documentId)->exists())->toBeTrue();
});
