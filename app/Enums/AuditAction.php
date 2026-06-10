<?php

namespace App\Enums;

enum AuditAction: string
{
    case TenantCreate = 'tenant.create';
    case TenantUpdate = 'tenant.update';
    case TenantSuspend = 'tenant.suspend';
    case TenantReactivate = 'tenant.reactivate';
    case TenantRunMigrations = 'tenant.run_migrations';
    case PlatformAdminCreate = 'platform_admin.create';
    case PlatformAdminUpdate = 'platform_admin.update';
    case PlatformAdminDeactivate = 'platform_admin.deactivate';
    case PlatformAdminReactivate = 'platform_admin.reactivate';
    case ImpersonationStart = 'impersonation.start';
    case ImpersonationEnd = 'impersonation.end';
    case PlatformLogin = 'platform_admin.login';
    case PlatformLogout = 'platform_admin.logout';
}
