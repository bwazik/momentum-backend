<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\PreferredLanguage;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Iam\Models\Delegation;
use App\Modules\Iam\Models\MonitoringScopeGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Traits\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name_ar',
    'name_en',
    'email',
    'password',
    'mobile',
    'employee_id',
    'account_type',
    'preferred_language',
    'is_active',
    'is_out_of_office',
    'out_of_office_delegate_user_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferred_language' => PreferredLanguage::class,
            'is_active' => 'boolean',
            'is_out_of_office' => 'boolean',
        ];
    }

    public function currentPositionAssignment(): HasOne
    {
        return $this->hasOne(UserPositionAssignment::class, 'user_id')
            ->where('is_primary', true)
            ->whereNull('ended_at');
    }

    public function positionAssignments(): HasMany
    {
        return $this->hasMany(UserPositionAssignment::class, 'user_id');
    }

    public function activePositionAssignments(): HasMany
    {
        return $this->hasMany(UserPositionAssignment::class, 'user_id')
            ->whereNull('ended_at');
    }

    public function userCapabilityGrants(): HasMany
    {
        return $this->hasMany(UserCapabilityGrant::class);
    }

    public function monitoringScopeGrants(): HasMany
    {
        return $this->hasMany(MonitoringScopeGrant::class);
    }

    public function auditGrants(): HasMany
    {
        return $this->hasMany(AuditGrant::class, 'external_auditor_user_id');
    }

    public function delegationsAsDelegator(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegator_user_id');
    }

    public function delegationsAsDelegate(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegate_user_id');
    }

    public function outOfOfficeDelegate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'out_of_office_delegate_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    public function isTenantAdmin(): bool
    {
        return $this->account_type === AccountType::TENANT_ADMIN;
    }

    public function isInternalUser(): bool
    {
        return $this->account_type === AccountType::INTERNAL_USER;
    }

    public function isExternalAuditor(): bool
    {
        return $this->account_type === AccountType::EXTERNAL_AUDITOR;
    }
}
