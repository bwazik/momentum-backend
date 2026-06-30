<?php

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Search\Models\TaskSearchIndex;
use App\Modules\Search\Models\UserRecentActivity;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Events\CommentCreated;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Comment Test',
        'name_ar' => 'اختبار',
        'slug' => 'comment-test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $priority = TaskPriority::where('is_default', true)->first();

    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);
    $this->task = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
    ]);

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

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);
    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => 'Bearer '.$this->token,
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('creates a top-level comment', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'This is a test comment body.',
        ]);

    $response->assertOk()
        ->assertJsonPath('body', 'This is a test comment body.')
        ->assertJsonStructure([
            'public_id',
            'author' => ['public_id', 'name_ar', 'name_en'],
            'body',
            'created_at',
        ])
        ->assertJsonPath('parent_comment_id', null);

    expect(Comment::count())->toBe(1);
});

it('creates a reply to a top-level comment', function () {
    $parent = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'This is a reply.',
            'parent_comment_id' => $parent->public_id,
        ]);

    $response->assertOk()
        ->assertJsonPath('body', 'This is a reply.')
        ->assertJsonPath('parent_comment_id', $parent->public_id);
});

it('rejects a reply to a reply', function () {
    $topLevel = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $firstReply = Comment::create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'parent_comment_id' => $topLevel->id,
        'body' => 'First reply.',
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'Reply to a reply.',
            'parent_comment_id' => $firstReply->public_id,
        ]);

    $response->assertStatus(422)
        ->assertJson(['message' => __('task.exceptions.invalid_comment_parent')]);
});

it('rejects a reply to a comment on another task', function () {
    $otherTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => TaskPriority::where('is_default', true)->first()->id,
    ]);

    $commentOnOtherTask = Comment::factory()->create([
        'task_id' => $otherTask->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'Wrong task reply.',
            'parent_comment_id' => $commentOnOtherTask->public_id,
        ]);

    $response->assertStatus(422)
        ->assertJson(['message' => __('task.exceptions.invalid_comment_parent')]);
});

it('rejects comment creation when user cannot view task', function () {
    $noViewUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $noViewUser->email,
            'password' => 'password',
        ]);
    $noViewHeaders = [
        'Authorization' => 'Bearer '.$loginResponse->json('token'),
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($noViewHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'Should not be allowed.',
        ]);

    $response->assertForbidden();
});

it('lists top-level comments with nested replies', function () {
    $parent = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'body' => 'Parent comment.',
    ]);

    Comment::factory()->count(2)->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'parent_comment_id' => $parent->id,
        'body' => 'Reply.',
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/comments");

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(2, 'data.0.replies');
});

it('lists comments with cursor pagination', function () {
    Comment::factory()->count(20)->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/comments?per_page=5");

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(5, 'data');

    expect($response->json('has_more'))->toBeTrue();
});

it('returns 403 for comment list when user cannot view task', function () {
    $noViewUser = User::factory()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $noViewUser->email,
            'password' => 'password',
        ]);
    $noViewHeaders = [
        'Authorization' => 'Bearer '.$loginResponse->json('token'),
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($noViewHeaders)
        ->getJson("/v1/tasks/{$this->task->public_id}/comments");

    $response->assertForbidden();
});

it('validates body is required', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('validates body max length', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => str_repeat('a', 5001),
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('validates parent_comment_id exists', function () {
    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'Test.',
            'parent_comment_id' => 'non-existent-uuid',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('parent_comment_id');
});

it('uploads document to comment', function () {
    foreach (['task.manage_documents', 'task.view_documents'] as $key) {
        $capability = Capability::where('key', $key)->first();
        if ($capability) {
            UserCapabilityGrant::create([
                'user_id' => $this->user->id,
                'capability_id' => $capability->id,
                'scope_type' => ScopeType::TENANT,
                'granted_by_user_id' => $this->user->id,
                'granted_at' => now(),
                'reason' => 'Test grant',
            ]);
        }
    }

    $comment = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/comments/{$comment->public_id}/documents", [
            'file' => $file,
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['public_id', 'original_filename', 'mime_type', 'size_bytes']);

    $this->assertDatabaseHas('documents', [
        'entity_type' => DocumentEntityType::Comment->value,
        'entity_id' => $comment->id,
    ]);
});

it('lists documents attached to comment', function () {
    $viewDocCapability = Capability::where('key', 'task.view_documents')->first();
    if ($viewDocCapability) {
        UserCapabilityGrant::create([
            'user_id' => $this->user->id,
            'capability_id' => $viewDocCapability->id,
            'scope_type' => ScopeType::TENANT,
            'granted_by_user_id' => $this->user->id,
            'granted_at' => now(),
            'reason' => 'Test grant',
        ]);
    }

    $comment = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $comment->documents()->create([
        'public_id' => (string) Str::uuid7(),
        'uploader_user_id' => $this->user->id,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1000,
        'entity_type' => DocumentEntityType::Comment,
        'entity_id' => $comment->id,
        'version_number' => 1,
        'storage_path' => 'comments/test.pdf',
    ]);

    $response = $this->withHeaders($this->authHeaders)
        ->getJson("/v1/comments/{$comment->public_id}/documents");

    $response->assertOk()
        ->assertJsonStructure(['data', 'next_cursor', 'has_more'])
        ->assertJsonCount(1, 'data');
});

it('rejects comment document upload without manage capability', function () {
    $comment = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
    ]);

    $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/comments/{$comment->public_id}/documents", [
            'file' => $file,
        ]);

    $response->assertForbidden();
});

it('indexes comment text in task search index on creation', function () {
    TaskSearchIndex::create([
        'task_id' => $this->task->id,
    ]);

    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'UniqueSearchableCommentPhrase',
        ]);

    $this->assertDatabaseHas('task_search_index', [
        'task_id' => $this->task->id,
    ]);

    $index = TaskSearchIndex::where('task_id', $this->task->id)->first();
    expect($index->comment_content_ar)->toContain('UniqueSearchableCommentPhrase');
    expect($index->comment_content_en)->toContain('UniqueSearchableCommentPhrase');
});

it('records CommentAdded in recent activity on comment creation', function () {
    $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'Activity test comment.',
        ]);

    $activity = UserRecentActivity::where('user_id', $this->user->id)
        ->where('task_id', $this->task->id)
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->activity_type)->toBe(SearchActivityType::CommentAdded);
});

it('excludes confidential task comments from unauthorized users', function () {
    $priority = TaskPriority::where('is_default', true)->first();
    $confidentialTask = Task::factory()->active()->create([
        'initiator_user_id' => $this->user->id,
        'priority_id' => $priority->id,
        'classification_level' => ClassificationLevel::Confidential,
    ]);

    $otherUser = User::factory()->create(['password' => bcrypt('password')]);
    $orgCapability = Capability::where('key', 'task.view.organization')->first();
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

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ]);
    $otherHeaders = [
        'Authorization' => 'Bearer '.$loginResponse->json('token'),
        'X-Tenant' => $this->tenant->public_id,
    ];

    $response = $this->withHeaders($otherHeaders)
        ->postJson("/v1/tasks/{$confidentialTask->public_id}/comments", [
            'body' => 'Should be denied.',
        ]);

    $response->assertForbidden();
});

it('records audit event when comment is created', function () {
    $comment = Comment::factory()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->user->id,
        'body' => 'Auditable comment.',
    ]);

    event(new CommentCreated($comment->load('task'), $this->user));

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'comment.created',
        'root_entity_public_id' => $this->task->public_id,
    ]);

    $event = AuditEvent::where('event_type', 'comment.created')->first();
    expect($event)->not->toBeNull();
    expect($event->payload)->toHaveKey('body');
});

it('returns 429 when rate limit exceeded for comment creation', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->withHeaders($this->authHeaders)
            ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
                'body' => "Comment {$i}.",
            ]);
    }

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/tasks/{$this->task->public_id}/comments", [
            'body' => 'This should be rate-limited.',
        ]);

    $response->assertStatus(429);
});
