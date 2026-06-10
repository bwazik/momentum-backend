<?php

use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Iam\Services\UserService;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM OOO Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-ooo-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->service = app(UserService::class);
    $this->user = User::factory()->create();
    $this->delegate = User::factory()->create();
});

afterEach(function () {
    User::whereNotNull('id')->forceDelete();
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('marks a user as out of office', function () {
    $updated = $this->service->markOutOfOffice($this->user, $this->delegate->id);

    expect($updated->is_out_of_office)->toBeTrue()
        ->and($updated->out_of_office_delegate_user_id)->toBe($this->delegate->id);
});

it('marks a user as back in office', function () {
    $this->service->markOutOfOffice($this->user, $this->delegate->id);
    $updated = $this->service->markBackInOffice($this->user);

    expect($updated->is_out_of_office)->toBeFalse()
        ->and($updated->out_of_office_delegate_user_id)->toBeNull();
});

it('resolves assignee to delegate when OOO', function () {
    $this->service->markOutOfOffice($this->user, $this->delegate->id);

    $policy = app(IamPolicy::class);
    $assignee = $policy->resolveAssignee($this->user);

    expect($assignee->id)->toBe($this->delegate->id);
});

it('resolves assignee to self when not OOO', function () {
    $policy = app(IamPolicy::class);
    $assignee = $policy->resolveAssignee($this->user);

    expect($assignee->id)->toBe($this->user->id);
});
