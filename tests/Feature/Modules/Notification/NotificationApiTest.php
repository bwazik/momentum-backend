<?php

use App\Enums\PreferredLanguage;
use App\Models\User;
use App\Modules\Notification\Notifications\TaskCompletedNotification;
use App\Modules\Notification\Notifications\TaskResumedNotification;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Notification API Test',
        'name_ar' => 'اختبار',
        'slug' => 'notif-api-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->user = User::factory()->tenantAdmin()->create([
        'password' => bcrypt('password'),
        'preferred_language' => PreferredLanguage::ARABIC,
    ]);

    $this->actingAs($this->user);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('lists notifications with cursor pagination', function () {
    for ($i = 0; $i < 5; $i++) {
        Notification::send($this->user, new TaskCompletedNotification(
            taskPublicId: "task-{$i}",
            taskTitleAr: "مهمة {$i}",
            taskTitleEn: "Task {$i}",
            dedupeKey: "test-cursor-{$i}",
        ));
    }

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/notifications');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['id', 'type', 'data', 'created_at']], 'next_cursor', 'has_more']);
});

it('filters unread notifications', function () {
    Notification::send($this->user, new TaskCompletedNotification(
        taskPublicId: 'test',
        taskTitleAr: 'مهمة',
        taskTitleEn: 'Task',
        dedupeKey: 'test:1',
    ));

    $this->user->notifications()->update(['read_at' => now()]);

    Notification::send($this->user, new TaskCompletedNotification(
        taskPublicId: 'test2',
        taskTitleAr: 'مهمة 2',
        taskTitleEn: 'Task 2',
        dedupeKey: 'test:2',
    ));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/notifications?read=unread');

    $response->assertOk();
    expect(count($response->json('data')))->toBe(1);
});

it('returns unread count', function () {
    Notification::send($this->user, new TaskCompletedNotification(
        taskPublicId: 'test',
        taskTitleAr: 'مهمة',
        taskTitleEn: 'Task',
        dedupeKey: 'test:1',
    ));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/notifications/unread-count');

    $response->assertOk()
        ->assertJsonPath('unread_count', 1);
});

it('marks a notification as read', function () {
    Notification::send($this->user, new TaskCompletedNotification(
        taskPublicId: 'test',
        taskTitleAr: 'مهمة',
        taskTitleEn: 'Task',
        dedupeKey: 'test:1',
    ));

    $notification = $this->user->notifications()->first();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/notifications/{$notification->id}/read");

    $response->assertStatus(204);
    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('returns 404 when marking another user notification as read', function () {
    $otherUser = User::factory()->create(['is_active' => true]);
    Notification::send($otherUser, new TaskCompletedNotification(
        taskPublicId: 'test',
        taskTitleAr: 'مهمة',
        taskTitleEn: 'Task',
        dedupeKey: 'test:1',
    ));

    $otherNotification = $otherUser->notifications()->first();

    $response = $this->withHeaders($this->authHeaders)
        ->postJson("/v1/notifications/{$otherNotification->id}/read");

    $response->assertStatus(404);
});

it('marks all notifications as read', function () {
    Notification::send($this->user, new TaskCompletedNotification(
        taskPublicId: 'test',
        taskTitleAr: 'مهمة',
        taskTitleEn: 'Task',
        dedupeKey: 'test:1',
    ));
    Notification::send($this->user, new TaskResumedNotification(
        taskPublicId: 'test2',
        taskTitleAr: 'مهمة 2',
        taskTitleEn: 'Task 2',
        dedupeKey: 'test:2',
    ));

    expect($this->user->unreadNotifications->count())->toBe(2);

    $response = $this->withHeaders($this->authHeaders)
        ->postJson('/v1/notifications/read-all');

    $response->assertStatus(204);
    expect($this->user->fresh()->unreadNotifications->count())->toBe(0);
});

it('enforces user isolation - cannot list another user notifications', function () {
    $otherUser = User::factory()->create(['is_active' => true]);
    Notification::send($otherUser, new TaskCompletedNotification(
        taskPublicId: 'test',
        taskTitleAr: 'مهمة',
        taskTitleEn: 'Task',
        dedupeKey: 'test:1',
    ));

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/notifications');

    $response->assertOk();
    expect(count($response->json('data')))->toBe(0);
});
