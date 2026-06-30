<?php

use App\Models\User;
use App\Modules\Iam\Services\AuditGrantService;
use App\Modules\Organization\Models\Department;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'IAM Audit Grant Test',
        'name_ar' => 'اختبار',
        'slug' => 'iam-audit-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->service = app(AuditGrantService::class);
    $this->adminUser = User::factory()->tenantAdmin()->create();
    $this->auditor = User::factory()->externalAuditor()->create();
    $this->dept = Department::factory()->create();
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
});

it('grants an audit scope to an external auditor', function () {
    $grant = $this->service->grant(
        $this->auditor,
        [
            'date_range_start' => '2026-01-01',
            'date_range_end' => '2026-12-31',
        ],
        $this->adminUser
    );

    expect($grant)
        ->external_auditor_user_id->toBe($this->auditor->id)
        ->granted_by_user_id->toBe($this->adminUser->id)
        ->date_range_start->format('Y-m-d')->toBe('2026-01-01')
        ->date_range_end->format('Y-m-d')->toBe('2026-12-31')
        ->department_id->toBeNull()
        ->revoked_at->toBeNull();
});

it('grants an audit scope with department restriction', function () {
    $grant = $this->service->grant(
        $this->auditor,
        [
            'date_range_start' => '2026-06-01',
            'date_range_end' => '2026-06-30',
            'department_id' => $this->dept->public_id,
        ],
        $this->adminUser
    );

    expect($grant)
        ->department_id->toBe($this->dept->id)
        ->revoked_at->toBeNull();
});

it('revokes an audit grant', function () {
    $grant = $this->service->grant(
        $this->auditor,
        [
            'date_range_start' => '2026-01-01',
            'date_range_end' => '2026-12-31',
        ],
        $this->adminUser
    );

    $revoked = $this->service->revoke($grant);

    expect($revoked->revoked_at)->not->toBeNull();
});
