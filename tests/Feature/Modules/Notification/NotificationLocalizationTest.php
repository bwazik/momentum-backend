<?php

use App\Enums\PreferredLanguage;
use App\Models\User;
use App\Modules\Notification\Notifications\StageAssignmentReceivedNotification;
use App\Modules\Notification\Notifications\TaskCompletedNotification;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Notification Localization Test',
        'name_ar' => 'اختبار',
        'slug' => 'notif-loc-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    $this->loginUser = User::factory()->tenantAdmin()->create([
        'password' => bcrypt('password'),
        'preferred_language' => PreferredLanguage::ARABIC,
    ]);

    $this->actingAs($this->loginUser);
    $this->authHeaders = [
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('sends notification in Arabic when preferred_language is Arabic', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'preferred_language' => PreferredLanguage::ARABIC,
    ]);

    Notification::fake();

    $notification = new StageAssignmentReceivedNotification(
        taskPublicId: 'test-task',
        taskTitleAr: 'مهمة اختبار',
        taskTitleEn: null,
        stagePublicId: 'stage-1',
        stageNameAr: 'مراجعة',
        stageNameEn: null,
        dedupeKey: 'test:1',
    );

    $user->notify($notification);

    Notification::assertSentTo(
        $user,
        StageAssignmentReceivedNotification::class,
        function ($sent, $channels, $notifiable) use ($notification) {
            $mail = $notification->toMail($notifiable);

            return str_contains($mail->subject, 'تعيين');
        }
    );
});

it('sends notification in English when preferred_language is English', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'preferred_language' => PreferredLanguage::ENGLISH,
    ]);

    Notification::fake();

    $notification = new StageAssignmentReceivedNotification(
        taskPublicId: 'test-task',
        taskTitleAr: 'مهمة اختبار',
        taskTitleEn: 'Test Task',
        stagePublicId: 'stage-1',
        stageNameAr: 'مراجعة',
        stageNameEn: 'Review',
        dedupeKey: 'test:1',
    );

    $user->notify($notification);

    Notification::assertSentTo(
        $user,
        StageAssignmentReceivedNotification::class,
        function ($sent, $channels, $notifiable) use ($notification) {
            $mail = $notification->toMail($notifiable);

            return str_contains($mail->subject, 'assigned');
        }
    );
});

it('stores bilingual payload in database notification', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'preferred_language' => PreferredLanguage::ENGLISH,
    ]);

    $notification = new StageAssignmentReceivedNotification(
        taskPublicId: 'test-task',
        taskTitleAr: 'مهمة اختبار',
        taskTitleEn: 'Test Task',
        stagePublicId: 'stage-1',
        stageNameAr: 'مراجعة',
        stageNameEn: 'Review',
        dedupeKey: 'test:1',
    );

    $user->notify($notification);

    $note = $user->notifications()->first();
    $data = $note->data;

    expect($data['title_ar'])->not->toBeEmpty();
    expect($data['title_en'])->not->toBeEmpty();
    expect($data['body_ar'])->toContain('مراجعة');
    expect($data['body_en'])->toContain('Review');
    expect($data['stage_public_id'])->toBe('stage-1');
    expect($data['task_public_id'])->toBe('test-task');
});

it('falls back to Arabic when English title is null', function () {
    $user = User::factory()->create([
        'is_active' => true,
        'preferred_language' => PreferredLanguage::ENGLISH,
    ]);

    $notification = new TaskCompletedNotification(
        taskPublicId: 'test-task',
        taskTitleAr: 'مهمة اختبار',
        taskTitleEn: null,
        dedupeKey: 'test:1',
    );

    $mail = $notification->toMail($user);

    expect(str_contains($mail->subject, 'completed'))->toBeTrue();

    $user->notify($notification);

    $data = $user->notifications()->first()->data;
    expect($data['body_en'])->toContain('مهمة اختبار');
});
