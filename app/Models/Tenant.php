<?php

namespace App\Models;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $fillable = [
        'public_id',
        'name_en',
        'name_ar',
        'slug',
        'domain',
        'database_name',
        'logo_path',
        'default_language',
        'timezone',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'public_id',
            'name_en',
            'name_ar',
            'slug',
            'domain',
            'database_name',
            'logo_path',
            'default_language',
            'timezone',
            'is_active',
            'settings',
        ];
    }

    protected static function booted()
    {
        parent::booted();

        static::creating(function ($tenant) {
            if (empty($tenant->public_id)) {
                $tenant->public_id = Str::uuid()->toString();
            }

            if (empty($tenant->database_name)) {
                $tenant->database_name = config('tenancy.database.prefix').$tenant->slug;
            }
        });
    }

    public function getInternalKey(string $key): string
    {
        if ($key === 'db_name') {
            return 'database_name';
        }

        return parent::getInternalKey($key);
    }
}
