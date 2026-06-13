<?php

namespace Database\Seeders;

use App\Modules\Iam\Models\Capability;
use Illuminate\Database\Seeder;

class CapabilitySeeder extends Seeder
{
    private const CAPABILITIES = [
        ['key' => 'task.view.organization', 'name_ar' => 'عرض مهام المؤسسة', 'name_en' => 'View Organization Tasks', 'description' => 'Can view tasks across the whole tenant, subject to classification rules.'],
        ['key' => 'task.view.department_touched', 'name_ar' => 'عرض مهام القسم', 'name_en' => 'View Department-Touched Tasks', 'description' => 'Can view tasks that have touched the user\'s department.'],
        ['key' => 'task.view.follow_up_scope', 'name_ar' => 'عرض نطاق المتابعة', 'name_en' => 'View Follow-Up Scope Tasks', 'description' => 'Can view active tasks inside assigned monitoring scopes.'],
        ['key' => 'task.view.own_participation', 'name_ar' => 'عرض مهامي', 'name_en' => 'View Own Participation', 'description' => 'Can view tasks the user initiated, currently owns, or previously owned.'],
        ['key' => 'task.classify.confidential', 'name_ar' => 'تصنيف المهام السرية', 'name_en' => 'Classify Confidential Tasks', 'description' => 'Can create or mark a task as confidential.'],
        ['key' => 'task.confidential.view_metadata', 'name_ar' => 'عرض بيانات المهام السرية', 'name_en' => 'View Confidential Metadata', 'description' => 'Can discover confidential task metadata without viewing full content.'],
        ['key' => 'task.confidential.view_override', 'name_ar' => 'تجاوز سرية المهام', 'name_en' => 'Override Confidential Access', 'description' => 'Can open confidential task content through justified, audited override.'],
        ['key' => 'task.confidential.manage_participants', 'name_ar' => 'إدارة مشاركين المهام السرية', 'name_en' => 'Manage Confidential Participants', 'description' => 'Can add or remove named confidential participants within granted scope.'],
        ['key' => 'task.override_assignment', 'name_ar' => 'تجاوز تعيين المهام', 'name_en' => 'Override Task Assignment', 'description' => 'Can reassign active stage/sub-stage assignees with mandatory reason.'],
        ['key' => 'task.cancel', 'name_ar' => 'إلغاء مهام', 'name_en' => 'Cancel Tasks', 'description' => 'Can cancel active tasks with mandatory reason.'],
        ['key' => 'task.suspend_resume', 'name_ar' => 'تعليق واستئناف المهام', 'name_en' => 'Suspend & Resume Tasks', 'description' => 'Can suspend or resume tasks.'],
        ['key' => 'blueprint.view_library', 'name_ar' => 'عرض مكتبة القوالب', 'name_en' => 'View Blueprint Library', 'description' => 'Can browse the Blueprint library.'],
        ['key' => 'blueprint.create.organization', 'name_ar' => 'إنشاء قوالب مؤسسية', 'name_en' => 'Create Organization Blueprints', 'description' => 'Can create organization-wide Blueprints.'],
        ['key' => 'blueprint.create.department', 'name_ar' => 'إنشاء قوالب قسم', 'name_en' => 'Create Department Blueprints', 'description' => 'Can create department-scoped Blueprints.'],
        ['key' => 'blueprint.manage', 'name_ar' => 'إدارة القوالب', 'name_en' => 'Manage Blueprints', 'description' => 'Can activate, deactivate, duplicate, or lock/manage Blueprints.'],
        ['key' => 'analytics.view.organization', 'name_ar' => 'عرض تحليلات المؤسسة', 'name_en' => 'View Organization Analytics', 'description' => 'Can view organization-wide analytics.'],
        ['key' => 'analytics.view.department', 'name_ar' => 'عرض تحليلات القسم', 'name_en' => 'View Department Analytics', 'description' => 'Can view department-level analytics.'],
        ['key' => 'analytics.view.individuals_in_department', 'name_ar' => 'عرض أداء الأفراد', 'name_en' => 'View Individual Metrics', 'description' => 'Can view individual employee metrics inside own department.'],
        ['key' => 'iam.manage_users', 'name_ar' => 'إدارة المستخدمين', 'name_en' => 'Manage Users', 'description' => 'Can create, deactivate, and transfer users.'],
        ['key' => 'iam.manage_positions', 'name_ar' => 'إدارة الهيكل التنظيمي', 'name_en' => 'Manage Organization Structure', 'description' => 'Can manage departments, positions, reporting lines, and grades.'],
        ['key' => 'iam.manage_capabilities', 'name_ar' => 'إدارة الصلاحيات', 'name_en' => 'Manage Capabilities', 'description' => 'Can assign capabilities and permission templates.'],
        ['key' => 'audit.view_task', 'name_ar' => 'عرض سجل المهام', 'name_en' => 'View Task Audit Trail', 'description' => 'Can view task-level audit trail for visible tasks.'],
        ['key' => 'audit.view_system', 'name_ar' => 'عرض سجل النظام', 'name_en' => 'View System Audit', 'description' => 'Can view system-wide user activity logs.'],
        ['key' => 'audit.create_grant', 'name_ar' => 'إنشاء صلاحيات المراجعة', 'name_en' => 'Create Audit Grants', 'description' => 'Can create external audit grants.'],
        ['key' => 'organization.manage', 'name_ar' => 'إدارة الهيكل التنظيمي', 'name_en' => 'Manage Organization', 'description' => 'Can manage departments, positions, grades, and calendars.'],
        ['key' => 'helpcenter.manage', 'name_ar' => 'إدارة مركز المساعدة', 'name_en' => 'Manage Help Center', 'description' => 'Can create, edit, publish, unpublish, and delete help articles.'],
        ['key' => 'helpcenter.view', 'name_ar' => 'عرض مركز المساعدة', 'name_en' => 'View Help Center', 'description' => 'Can browse and read published help articles.'],
        ['key' => 'task.manage_priorities', 'name_ar' => 'إدارة أولويات المهام', 'name_en' => 'Manage Task Priorities', 'description' => 'Can create, update, deactivate, and reactivate task priority levels.'],
        ['key' => 'task.manage', 'name_ar' => 'إدارة المهام', 'name_en' => 'Manage Tasks', 'description' => 'Can update or delete other users\' draft tasks.'],
        ['key' => 'task.escalate', 'name_ar' => 'تصعيد مهمة', 'name_en' => 'Escalate Task', 'description' => 'Create manual escalations for at-risk stages'],
        ['key' => 'task.resolve_escalations', 'name_ar' => 'حل التصعيدات', 'name_en' => 'Resolve Escalations', 'description' => 'Resolve escalations beyond own assignments'],
    ];

    public function run(): void
    {
        foreach (self::CAPABILITIES as $cap) {
            Capability::create([
                'key' => $cap['key'],
                'name_ar' => $cap['name_ar'],
                'name_en' => $cap['name_en'],
                'description' => $cap['description'],
                'is_system_defined' => true,
            ]);
        }
    }
}
