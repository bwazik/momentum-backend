<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\BlueprintScope;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Notifications\StageAssignmentReceivedNotification;
use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Search\Models\TaskSearchIndex;
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MockDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Department::count() > 2) {
            $this->command?->info('Mock data already exists, skipping.');

            return;
        }

        $this->command?->info('Seeding mock development data...');

        // ---- 1. Departments ----
        $deptData = [
            ['ar' => 'تقنية المعلومات', 'en' => 'Information Technology'],
            ['ar' => 'التحول الصحي الرقمي', 'en' => 'Health Digital Transformation'],
            ['ar' => 'الموارد البشرية', 'en' => 'Human Resources'],
            ['ar' => 'المالية', 'en' => 'Finance'],
            ['ar' => 'الشؤون القانونية', 'en' => 'Legal Affairs'],
            ['ar' => 'الصحة العامة', 'en' => 'Public Health'],
            ['ar' => 'سلسلة الإمدادات الطبية', 'en' => 'Medical Supply Chain'],
            ['ar' => 'خدمة المجتمع', 'en' => 'Community Service'],
        ];
        $departments = collect($deptData)->map(fn ($d) => Department::create([
            'name_ar' => $d['ar'],
            'name_en' => $d['en'],
            'is_active' => true,
        ]));

        // ---- 2. Authority Grades ----
        $gradeData = [
            ['ar' => 'مدير عام', 'en' => 'Director General'],
            ['ar' => 'مدير إدارة', 'en' => 'Department Manager'],
            ['ar' => 'رئيس قسم', 'en' => 'Section Head'],
            ['ar' => 'أخصائي', 'en' => 'Specialist'],
        ];
        AuthorityGrade::where('id', '>', 0)->forceDelete();
        $grades = collect($gradeData)->map(fn ($g, $i) => AuthorityGrade::create([
            'rank' => $i + 1,
            'name_ar' => $g['ar'],
            'name_en' => $g['en'],
        ]));

        // ---- 3. Positions ----
        $positionData = [
            [0, 'مدير تقنية المعلومات', 'IT Director', true, 0],
            [0, 'أخصائي نظم', 'Systems Specialist', false, 3],
            [1, 'مدير التحول الرقمي', 'Digital Transformation Manager', true, 0],
            [1, 'محلل بيانات', 'Data Analyst', false, 3],
            [2, 'مدير الموارد البشرية', 'HR Manager', true, 1],
            [2, 'أخصائي توظيف', 'Recruitment Specialist', false, 3],
            [3, 'مدير المالية', 'Finance Manager', true, 1],
            [4, 'مستشار قانوني', 'Legal Advisor', true, 2],
        ];
        $positions = collect($positionData)->map(fn ($p) => Position::create([
            'department_id' => $departments[$p[0]]->id,
            'title_ar' => $p[1],
            'title_en' => $p[2],
            'authority_grade_id' => $grades[$p[4]]->id,
            'is_department_head' => $p[3],
            'is_active' => true,
        ]));

        // ---- 4. Users ----
        $tenantSlug = tenant()?->slug ?? 'central';
        $admin = User::where('email', 'admin@'.$tenantSlug.'.test')->firstOrFail();

        $userData = [
            ['ar' => 'أحمد العنزي', 'en' => 'Ahmed Al-Anazi', 'email' => 'ahmed@moh.test'],
            ['ar' => 'سارة الحربي', 'en' => 'Sara Al-Harbi', 'email' => 'sara@moh.test'],
            ['ar' => 'محمد القحطاني', 'en' => 'Mohammed Al-Qahtani', 'email' => 'mohammed@moh.test'],
        ];
        $extraUsers = collect($userData)->map(fn ($u) => User::create([
            'name_ar' => $u['ar'],
            'name_en' => $u['en'],
            'email' => $u['email'],
            'password' => 'password123',
            'account_type' => AccountType::INTERNAL_USER,
            'is_active' => true,
            'email_verified_at' => now(),
        ]));
        $allUsers = collect([$admin, ...$extraUsers]);

        // Grant admin full task visibility capabilities
        $viewOrgCap = Capability::where('key', 'task.view.organization')->first();
        $confidentialViewCap = Capability::where('key', 'task.confidential.view_metadata')->first();
        $grantReason = 'Seed data: admin base capabilities';
        if ($viewOrgCap) {
            UserCapabilityGrant::create([
                'user_id' => $admin->id,
                'capability_id' => $viewOrgCap->id,
                'scope_type' => ScopeType::TENANT,
                'granted_by_user_id' => $admin->id,
                'granted_at' => now(),
                'reason' => $grantReason,
            ]);
        }
        if ($confidentialViewCap) {
            UserCapabilityGrant::create([
                'user_id' => $admin->id,
                'capability_id' => $confidentialViewCap->id,
                'scope_type' => ScopeType::TENANT,
                'granted_by_user_id' => $admin->id,
                'granted_at' => now(),
                'reason' => $grantReason,
            ]);
        }

        // ---- 5. Working Calendar ----
        $calendar = WorkingCalendar::factory()->default()->create([
            'name_ar' => 'التقويم الافتراضي',
            'name_en' => 'Default Calendar',
        ]);

        // ---- 6. Blueprint Categories ----
        $catData = [
            ['ar' => 'أنظمة تقنية', 'en' => 'IT Systems'],
            ['ar' => 'خدمات صحية', 'en' => 'Health Services'],
            ['ar' => 'إداري', 'en' => 'Administrative'],
        ];
        $categories = collect($catData)->map(fn ($c, $i) => BlueprintCategory::create([
            'name_ar' => $c['ar'],
            'name_en' => $c['en'],
            'display_order' => $i + 1,
            'is_active' => true,
        ]));

        // ---- 7. SLA Policy ----
        $slaPolicy = SlaPolicy::factory()->create([
            'name_ar' => 'سياسة SLA افتراضية',
            'name_en' => 'Default SLA Policy',
            'sla_value' => 14,
            'sla_unit' => SlaUnit::Days,
            'warning_threshold_percentage' => 75,
            'is_active' => true,
        ]);

        // ---- 8. Blueprints & Stages ----
        $bpMeta = [
            ['cat' => 0, 'ar' => 'تطوير نظام', 'en' => 'System Development', 'dept' => 0],
            ['cat' => 0, 'ar' => 'تدقيق أمني', 'en' => 'Security Audit', 'dept' => 0],
            ['cat' => 1, 'ar' => 'برنامج صحي', 'en' => 'Health Program', 'dept' => 5],
            ['cat' => 2, 'ar' => 'إجراء إداري', 'en' => 'Administrative Process', 'dept' => 2],
            ['cat' => 0, 'ar' => 'بنية تحتية', 'en' => 'Infrastructure', 'dept' => 0],
            ['cat' => 0, 'ar' => 'بوابة إلكترونية', 'en' => 'E-Portal', 'dept' => 1],
        ];
        $stageTypeIds = StageType::pluck('id')->toArray();

        $blueprints = collect($bpMeta)->map(function ($m) use ($categories, $departments, $admin, $stageTypeIds, $slaPolicy, $positions) {
            $bp = Blueprint::factory()->create([
                'category_id' => $categories[$m['cat']]->id,
                'name_ar' => $m['ar'],
                'name_en' => $m['en'],
                'description_ar' => 'نموذج عمل لـ '.$m['ar'],
                'description_en' => 'Workflow for '.$m['en'],
                'scope' => BlueprintScope::Organization,
                'department_id' => $departments[$m['dept']]->id,
                'created_by_user_id' => $admin->id,
            ]);

            foreach (array_slice($stageTypeIds, 0, 3) as $i => $stId) {
                BlueprintStage::factory()->create([
                    'blueprint_id' => $bp->id,
                    'stage_type_id' => $stId,
                    'sla_policy_id' => $slaPolicy->id,
                    'name_ar' => $m['ar'].' - المرحلة '.($i + 1),
                    'name_en' => $m['en'].' - Stage '.($i + 1),
                    'sequence_order' => $i + 1,
                    'assigned_department_id' => $departments[$m['dept']]->id,
                    'assigned_position_id' => $positions->random()->id,
                ]);
            }

            return $bp;
        });

        // ---- 9. Tasks ----
        $priorities = TaskPriority::all();

        $taskSpecs = [
            ['ar' => 'تحديث نظام المعاملات', 'en' => 'Transaction System Update',
                'desc_ar' => 'تحديث شامل لنظام المعاملات الحكومية ليشمل إصدار التراخيص والتأشيرات الإلكترونية مع ربطها بمنصة الدفع الوطنية',
                'desc_en' => 'Comprehensive update of the government transaction system to include electronic licensing and visa issuance, integrated with the national payment platform',
                'status' => TaskStatus::Active, 'dept' => 0, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'days' => 15, 'due' => 20, 'sla' => 'running'],
            ['ar' => 'تطوير منصة الخدمات', 'en' => 'Service Platform Development',
                'desc_ar' => 'تطوير منصة إلكترونية موحدة لتقديم الخدمات الصحية للمستفيدين تشمل حجز المواعيد والاستشارات عن بعد',
                'desc_en' => 'Develop a unified electronic platform for delivering health services to beneficiaries, including appointment booking and teleconsultations',
                'status' => TaskStatus::Active, 'dept' => 1, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'days' => 25, 'due' => 5, 'sla' => 'warning'],
            ['ar' => 'أرشفة السجلات الصحية', 'en' => 'Health Records Archiving',
                'desc_ar' => 'أرشفة رقمية لجميع السجلات الصحية الورقية للمرضى وتحويلها إلى نظام إلكتروني موحد مع ضمان سرية البيانات',
                'desc_en' => 'Digital archiving of all paper-based patient health records and migration to a unified electronic system ensuring data confidentiality',
                'status' => TaskStatus::Completed, 'dept' => 5, 'prio' => 2, 'class' => ClassificationLevel::Confidential,
                'days' => 60, 'due' => 0, 'sla' => 'completed'],
            ['ar' => 'نظام إدارة الموارد البشرية', 'en' => 'HR Management System',
                'desc_ar' => 'تطبيق نظام متكامل لإدارة الموارد البشرية يشمل الإجازات والرواتب والتقييم الوظيفي للموظفين',
                'desc_en' => 'Implement an integrated HR management system covering leaves, payroll, and performance evaluations for employees',
                'status' => TaskStatus::Suspended, 'dept' => 2, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'days' => 45, 'due' => 10, 'sla' => 'breached'],
            ['ar' => 'تدقيق أمن المعلومات', 'en' => 'Information Security Audit',
                'desc_ar' => 'تدقيق شامل لأمن المعلومات في جميع أنظمة الوزارة وفق معايير ISO 27001',
                'desc_en' => 'Comprehensive information security audit of all ministry systems in accordance with ISO 27001 standards',
                'status' => TaskStatus::Draft, 'dept' => 4, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'days' => 2, 'due' => 90, 'sla' => null],
            ['ar' => 'منصة التواصل الداخلي', 'en' => 'Internal Communication Platform',
                'desc_ar' => 'تطوير منصة للتواصل الداخلي والتعاون بين موظفي الوزارة تشمل الرسائل الفورية والمشاركة في الملفات',
                'desc_en' => 'Develop an internal communication and collaboration platform for ministry staff including instant messaging and file sharing',
                'status' => TaskStatus::Active, 'dept' => 0, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'days' => 10, 'due' => 30, 'sla' => 'running'],
            ['ar' => 'تطبيق التنقل الذكي', 'en' => 'Smart Mobility Application',
                'desc_ar' => 'تطبيق جوال للتنقل الذكي للموظفين يشمل حجز المواصلات وتتبع الرحلات وإدارة المصروفات',
                'desc_en' => 'Mobile application for smart employee mobility including transport booking, trip tracking, and expense management',
                'status' => TaskStatus::Cancelled, 'dept' => 6, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'days' => 90, 'due' => 0, 'sla' => null],
            ['ar' => 'بوابة الموردين الإلكترونية', 'en' => 'Supplier E-Portal',
                'desc_ar' => 'بوابة إلكترونية لإدارة عقود الموردين والمشتريات الحكومية في وزارة الصحة',
                'desc_en' => 'Electronic portal for managing supplier contracts and government procurement at the Ministry of Health',
                'status' => TaskStatus::Active, 'dept' => 3, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'days' => 5, 'due' => 25, 'sla' => 'warning'],
            ['ar' => 'مركز البيانات الاحتياطي', 'en' => 'Backup Data Center',
                'desc_ar' => 'إنشاء مركز بيانات احتياطي لضمان استمرارية الأعمال واستعادة البيانات بعد الكوارث',
                'desc_en' => 'Establish a backup data center to ensure business continuity and disaster recovery',
                'status' => TaskStatus::Draft, 'dept' => 0, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'days' => 1, 'due' => 180, 'sla' => null],
            ['ar' => 'نظام الفواتير الإلكترونية', 'en' => 'E-Invoicing System',
                'desc_ar' => 'تطبيق نظام الفواتير الإلكترونية وفق متطلبات هيئة الزكاة والضريبة والجمارك',
                'desc_en' => 'Implement an electronic invoicing system in compliance with ZATCA requirements',
                'status' => TaskStatus::Active, 'dept' => 0, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'days' => 20, 'due' => 15, 'sla' => 'running'],
        ];

        $tasks = collect($taskSpecs)->map(function ($s, $idx) use ($blueprints, $priorities, $allUsers, $departments, $calendar, $slaPolicy) {
            $createdAt = now()->subDays($s['days']);
            $bp = $blueprints[$idx % count($blueprints)];

            $task = Task::create([
                'blueprint_id' => $bp->id,
                'priority_id' => $priorities[$s['prio']]->id,
                'title_ar' => $s['ar'],
                'title_en' => $s['en'],
                'description_ar' => $s['desc_ar'],
                'description_en' => $s['desc_en'],
                'classification_level' => $s['class'],
                'initiator_user_id' => $allUsers->random()->id,
                'status' => $s['status'],
                'due_date' => $s['due'] > 0 ? now()->addDays($s['due'])->toDateString() : null,
                'launched_at' => in_array($s['status'], [TaskStatus::Active, TaskStatus::Suspended, TaskStatus::Completed], true) ? $createdAt->copy()->addDay() : null,
                'suspended_at' => $s['status'] === TaskStatus::Suspended ? now()->subDays(3) : null,
                'suspension_reason' => $s['status'] === TaskStatus::Suspended ? 'تعليق بسبب نقص الموارد المالية' : null,
                'completed_at' => $s['status'] === TaskStatus::Completed ? $createdAt->copy()->addDays(20) : null,
                'cancelled_at' => $s['status'] === TaskStatus::Cancelled ? $createdAt->copy()->addDays(15) : null,
                'cancellation_reason' => $s['status'] === TaskStatus::Cancelled ? 'إلغاء بسبب تغير الأولويات' : null,
            ]);

            // Set timestamps
            Task::where('id', $task->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $task = $task->fresh();

            // Create stage instances for non-draft tasks
            if (in_array($s['status'], [TaskStatus::Active, TaskStatus::Suspended, TaskStatus::Completed], true)) {
                $bpStages = BlueprintStage::where('blueprint_id', $bp->id)->orderBy('sequence_order')->get();

                foreach ($bpStages as $bsIdx => $stage) {
                    $isCompleted = $s['status'] === TaskStatus::Completed;

                    $stageInstance = TaskStageInstance::create([
                        'task_id' => $task->id,
                        'blueprint_stage_id' => $stage->id,
                        'sequence_order' => $stage->sequence_order,
                        'owning_department_id' => $departments[$s['dept']]->id,
                        'completion_rule' => CompletionRule::AnyAssignee,
                        'status' => $isCompleted ? StageInstanceStatus::Completed : StageInstanceStatus::Active,
                        'entered_at' => $task->launched_at ?? $createdAt,
                        'exited_at' => $isCompleted ? $createdAt->copy()->addDays(min(20, $s['days'] - 5)) : null,
                    ]);

                    // Assignment for first stage
                    if ($bsIdx === 0) {
                        TaskStageAssignment::create([
                            'task_id' => $task->id,
                            'stage_instance_id' => $stageInstance->id,
                            'user_id' => $allUsers->random()->id,
                            'assignment_role' => AssignmentRole::Required,
                            'is_completed' => $isCompleted,
                            'assigned_at' => $task->launched_at ?? $createdAt,
                            'completed_at' => $isCompleted ? $createdAt->copy()->addDays(min(20, $s['days'] - 5)) : null,
                        ]);
                    }

                    // SLA timer for active stage (first stage only)
                    if ($s['sla'] && $bsIdx === 0) {
                        $deadlineBase = $task->launched_at ?? $createdAt;
                        $timer = [
                            'task_id' => $task->id,
                            'stage_instance_id' => $stageInstance->id,
                            'sla_policy_id' => $slaPolicy->id,
                            'working_calendar_id' => $calendar->id,
                            'started_at' => $deadlineBase,
                            'elapsed_before_pause' => 0,
                        ];

                        switch ($s['sla']) {
                            case 'breached':
                                $timer['status'] = SlaTimerStatus::Breached;
                                $timer['deadline_at'] = (clone $deadlineBase)->addDays(10);
                                $timer['warning_at'] = (clone $deadlineBase)->addDays(7);
                                break;
                            case 'warning':
                                $timer['status'] = SlaTimerStatus::Warning;
                                $timer['deadline_at'] = (clone $deadlineBase)->addDays(30);
                                $timer['warning_at'] = now()->subDays(2);
                                break;
                            case 'completed':
                                $timer['status'] = SlaTimerStatus::Completed;
                                $timer['deadline_at'] = (clone $deadlineBase)->addDays(30);
                                $timer['completed_at'] = (clone $deadlineBase)->addDays(20);
                                break;
                            default: // running (green)
                                $timer['status'] = SlaTimerStatus::Running;
                                $timer['deadline_at'] = (clone $deadlineBase)->addDays(30);
                                $timer['warning_at'] = (clone $deadlineBase)->addDays(22);
                                break;
                        }

                        SlaTimerInstance::create($timer);
                    }
                }
            }

            return $task;
        });

        // ---- 10. Notifications ----
        $notificationSpecs = [
            ['type' => 'stage_assignment', 'nType' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Task Assigned to You',
                'body_ar' => 'تم إسناد مهمة "تحديث نظام المعاملات" إليك في مرحلة "المراجعة"',
                'body_en' => 'Task "Transaction System Update" has been assigned to you in the Review stage',
                'task' => 0, 'read' => false, 'days' => 14],
            ['type' => 'sla_breach', 'nType' => NotificationType::SlaBreach,
                'title_ar' => 'اختراق SLA', 'title_en' => 'SLA Breach',
                'body_ar' => 'تم تجاوز المهلة الزمنية لمهمة "نظام إدارة الموارد البشرية" في مرحلة "الموافقة"',
                'body_en' => 'SLA has been breached for task "HR Management System" in the Approval stage',
                'task' => 3, 'read' => false, 'days' => 3],
            ['type' => 'sla_warning', 'nType' => NotificationType::SlaWarning,
                'title_ar' => 'تنبيه SLA', 'title_en' => 'SLA Warning',
                'body_ar' => 'يقترب موعد تسليم مهمة "تطوير منصة الخدمات" من الانتهاء',
                'body_en' => 'The deadline for task "Service Platform Development" is approaching',
                'task' => 1, 'read' => true, 'days' => 1],
            ['type' => 'task_completed', 'nType' => NotificationType::TaskCompleted,
                'title_ar' => 'اكتمال مهمة', 'title_en' => 'Task Completed',
                'body_ar' => 'تم اكتمال مهمة "أرشفة السجلات الصحية" بنجاح',
                'body_en' => 'Task "Health Records Archiving" has been completed successfully',
                'task' => 2, 'read' => true, 'days' => 40],
            ['type' => 'stage_assignment', 'nType' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Task Assigned to You',
                'body_ar' => 'تم إسناد مهمة "بوابة الموردين الإلكترونية" إليك في مرحلة "الإجراء"',
                'body_en' => 'Task "Supplier E-Portal" has been assigned to you in the Action stage',
                'task' => 7, 'read' => false, 'days' => 4],
            ['type' => 'sla_warning', 'nType' => NotificationType::SlaWarning,
                'title_ar' => 'تنبيه SLA', 'title_en' => 'SLA Warning',
                'body_ar' => 'مهلة مهمة "بوابة الموردين الإلكترونية" على وشك الانتهاء',
                'body_en' => 'The SLA for task "Supplier E-Portal" is about to expire',
                'task' => 7, 'read' => false, 'days' => 1],
            ['type' => 'stage_assignment', 'nType' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Task Assigned to You',
                'body_ar' => 'تم إسناد مهمة "منصة التواصل الداخلي" إليك في مرحلة "القرار"',
                'body_en' => 'Task "Internal Communication Platform" has been assigned to you in the Decision stage',
                'task' => 5, 'read' => true, 'days' => 7],
            ['type' => 'task_completed', 'nType' => NotificationType::TaskCompleted,
                'title_ar' => 'اكتمال مهمة', 'title_en' => 'Task Completed',
                'body_ar' => 'تم اكتمال مهمة "نظام الفواتير الإلكترونية" بنجاح',
                'body_en' => 'Task "E-Invoicing System" has been completed successfully',
                'task' => 9, 'read' => false, 'days' => 2],
        ];

        foreach ($notificationSpecs as $ns) {
            $createdAt = now()->subDays($ns['days']);
            $taskModel = $tasks[$ns['task']];

            $admin->notifications()->create([
                'id' => (string) Str::uuid7(),
                'type' => StageAssignmentReceivedNotification::class,
                'data' => [
                    'notification_type' => $ns['nType']->value,
                    'dedupe_key' => (string) Str::uuid7(),
                    'title_ar' => $ns['title_ar'],
                    'title_en' => $ns['title_en'],
                    'body_ar' => $ns['body_ar'],
                    'body_en' => $ns['body_en'],
                    'task_public_id' => $taskModel->public_id,
                    'action_url' => '/tasks/'.$taskModel->public_id,
                ],
                'read_at' => $ns['read'] ? (clone $createdAt)->addHour() : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        // ---- 11. Task Search Index ----
        foreach ($tasks as $task) {
            $completedNotes = TaskStageAssignment::where('task_id', $task->id)
                ->where('is_completed', true)
                ->get();

            if ($completedNotes->isNotEmpty()) {
                $notesAr = $completedNotes->map(fn ($a) => $a->completion_note_ar ?? 'مكتمل')->implode("\n");
                $notesEn = $completedNotes->map(fn ($a) => $a->completion_note_en ?? 'Completed')->implode("\n");

                TaskSearchIndex::updateOrCreate(
                    ['task_id' => $task->id],
                    ['notes_ar' => $notesAr, 'notes_en' => $notesEn],
                );
            }
        }

        $this->command?->info('Mock data seeded successfully!');
        $this->command?->info('  Users: admin@moh.test, ahmed@moh.test, sara@moh.test, mohammed@moh.test (password123)');
        $this->command?->info('  '.count($tasks).' tasks created');
        $this->command?->info('  '.count($notificationSpecs).' notifications created');
    }
}
