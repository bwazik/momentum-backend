<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentCardinality;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Enums\BlueprintScope;
use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\FollowUp\Enums\FollowUpActionType;
use App\Modules\FollowUp\Models\FollowUpAction;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Models\UserPositionAssignment;
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
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use App\Modules\Tracking\Models\Escalation;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MockDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Department::count() > 2) {
            $this->command?->info('Mock data already exists, skipping.');

            return;
        }

        $this->command?->info('Seeding realistic mock data...');

        // ──────────────────────────────────────────────
        // 1. DEPARTMENTS (hierarchical)
        // ──────────────────────────────────────────────
        $depts = collect();
        $rawDepts = [
            ['ar' => 'مكتب الوزير', 'en' => 'Minister\'s Office', 'parent' => null],
            ['ar' => 'ديوان الوزارة', 'en' => 'Ministry Bureau', 'parent' => 0],
            ['ar' => 'الإدارة العامة لتقنية المعلومات', 'en' => 'IT General Administration', 'parent' => 1],
            ['ar' => 'إدارة تطوير الأنظمة', 'en' => 'Systems Development', 'parent' => 2],
            ['ar' => 'إدارة البنية التحتية', 'en' => 'Infrastructure', 'parent' => 2],
            ['ar' => 'الإدارة العامة للموارد البشرية', 'en' => 'HR General Administration', 'parent' => 1],
            ['ar' => 'إدارة التوظيف', 'en' => 'Recruitment', 'parent' => 5],
            ['ar' => 'إدارة التدريب والتطوير', 'en' => 'Training & Development', 'parent' => 5],
            ['ar' => 'الإدارة العامة للمالية', 'en' => 'Finance General Administration', 'parent' => 1],
            ['ar' => 'إدارة الميزانية', 'en' => 'Budget', 'parent' => 8],
            ['ar' => 'إدارة المشتريات', 'en' => 'Procurement', 'parent' => 8],
            ['ar' => 'الإدارة العامة للشؤون القانونية', 'en' => 'Legal Affairs', 'parent' => 1],
            ['ar' => 'الإدارة العامة للصحة العامة', 'en' => 'Public Health', 'parent' => 1],
            ['ar' => 'الإدارة العامة لسلسلة الإمدادات', 'en' => 'Supply Chain', 'parent' => 1],
            ['ar' => 'إدارة التواصل المؤسسي', 'en' => 'Corporate Communications', 'parent' => 1],
        ];
        foreach ($rawDepts as $i => $d) {
            $parentId = $d['parent'] !== null ? $depts[$d['parent']]->id : null;
            $depts->push(Department::create([
                'parent_department_id' => $parentId,
                'name_ar' => $d['ar'],
                'name_en' => $d['en'],
                'is_active' => true,
            ]));
        }

        // ──────────────────────────────────────────────
        // 2. AUTHORITY GRADES
        // ──────────────────────────────────────────────
        AuthorityGrade::where('id', '>', 0)->forceDelete();
        $grades = collect();
        $gradeData = [
            ['ar' => 'مدير عام', 'en' => 'Director General'],
            ['ar' => 'نائب مدير عام', 'en' => 'Deputy Director General'],
            ['ar' => 'مدير إدارة', 'en' => 'Department Manager'],
            ['ar' => 'رئيس قسم', 'en' => 'Section Head'],
            ['ar' => 'أخصائي أول', 'en' => 'Senior Specialist'],
            ['ar' => 'أخصائي', 'en' => 'Specialist'],
        ];
        foreach ($gradeData as $i => $g) {
            $grades->push(AuthorityGrade::create(['rank' => $i + 1, 'name_ar' => $g['ar'], 'name_en' => $g['en']]));
        }

        // ──────────────────────────────────────────────
        // 3. POSITIONS
        // ──────────────────────────────────────────────
        $positions = collect();
        $posData = [
            // [dept_idx, title_ar, title_en, grade_idx, is_dept_head, reports_to_idx_or_false]
            [2, 'مدير عام تقنية المعلومات', 'IT Director General', 0, true, false],
            [2, 'نائب مدير عام تقنية المعلومات', 'Deputy IT Director General', 1, false, 0],
            [3, 'مدير تطوير الأنظمة', 'Systems Development Manager', 2, true, false],
            [4, 'مدير البنية التحتية', 'Infrastructure Manager', 2, true, false],
            [3, 'رئيس محللي النظم', 'Senior Systems Analyst', 3, false, 2],
            [4, 'مهندس شبكات', 'Network Engineer', 3, false, 4],
            [5, 'مدير عام الموارد البشرية', 'HR Director General', 0, true, false],
            [6, 'رئيس قسم التوظيف', 'Recruitment Section Head', 3, true, false],
            [7, 'أخصائي تدريب', 'Training Specialist', 5, false, 6],
            [8, 'مدير عام المالية', 'Finance Director General', 0, true, false],
            [9, 'مدير الميزانية', 'Budget Manager', 3, true, false],
            [10, 'رئيس قسم المشتريات', 'Procurement Section Head', 3, true, false],
            [11, 'مدير عام الشؤون القانونية', 'Legal Director General', 0, true, false],
            [11, 'مستشار قانوني', 'Legal Consultant', 4, false, 12],
            [12, 'مدير عام الصحة العامة', 'Public Health Director General', 0, true, false],
            [13, 'مدير عام سلسلة الإمدادات', 'Supply Chain Director General', 0, true, false],
            [14, 'مدير التواصل المؤسسي', 'Communications Manager', 3, true, false],
            [1, 'نائب الوزير', 'Deputy Minister', 0, true, false],
            [9, 'محاسب أول', 'Senior Accountant', 4, false, 10],
        ];
        foreach ($posData as $p) {
            $reportsTo = $p[5] !== false ? $positions[$p[5]] ?? null : null;
            $positions->push(Position::create([
                'department_id' => $depts[$p[0]]->id,
                'title_ar' => $p[1],
                'title_en' => $p[2],
                'reports_to_position_id' => $reportsTo?->id,
                'authority_grade_id' => $grades[$p[3]]->id,
                'is_department_head' => $p[4],
                'is_active' => true,
            ]));
        }

        // ──────────────────────────────────────────────
        // 4. USERS (10 realistic Saudi users)
        // ──────────────────────────────────────────────
        $tenant = tenant();
        $slug = $tenant?->slug ?? 'central';
        $admin = User::where('email', 'admin@'.$slug.'.test')->firstOrFail();

        $userSpecs = [
            ['ar' => 'نورة القحطاني', 'en' => 'Noura Al-Qahtani', 'pos' => 0],
            ['ar' => 'عبدالله العتيبي', 'en' => 'Abdullah Al-Otaibi', 'pos' => 1],
            ['ar' => 'سارة الدوسري', 'en' => 'Sara Al-Dosari', 'pos' => 2],
            ['ar' => 'فيصل الزهراني', 'en' => 'Faisal Al-Zahrani', 'pos' => 3],
            ['ar' => 'هند الشمري', 'en' => 'Hind Al-Shammari', 'pos' => 4],
            ['ar' => 'عمر السلمي', 'en' => 'Omar Al-Sulami', 'pos' => 6],
            ['ar' => 'ريم الحربي', 'en' => 'Reem Al-Harbi', 'pos' => 7],
            ['ar' => 'خالد الغامدي', 'en' => 'Khalid Al-Ghamdi', 'pos' => 9],
            ['ar' => 'منال الجهني', 'en' => 'Manal Al-Juhani', 'pos' => 12],
            ['ar' => 'تركي المطيري', 'en' => 'Turki Al-Mutairi', 'pos' => 14],
            ['ar' => 'نايف البقمي', 'en' => 'Naif Al-Baqmi', 'pos' => 15],
            ['ar' => 'مشاعل الشهراني', 'en' => 'Mashaal Al-Shahrani', 'pos' => 16],
            ['ar' => 'يوسف الزهراني', 'en' => 'Yousef Al-Zahrani', 'pos' => 17],
            ['ar' => 'فاطمة الأنصاري', 'en' => 'Fatima Al-Ansari', 'pos' => 18],
        ];
        $allUsers = collect([$admin]);
        foreach ($userSpecs as $u) {
            $user = User::create([
                'name_ar' => $u['ar'],
                'name_en' => $u['en'],
                'email' => strtolower(str_replace(' ', '.', $u['en'])).'@'.$slug.'.test',
                'password' => 'password123',
                'account_type' => AccountType::INTERNAL_USER,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            UserPositionAssignment::create([
                'user_id' => $user->id,
                'position_id' => $positions[$u['pos']]->id,
                'is_primary' => true,
                'started_at' => now()->subMonths(rand(3, 12)),
            ]);
            $allUsers->push($user);
        }

        // ──────────────────────────────────────────────
        // 5. CAPABILITY GRANTS — admin gets ALL capabilities
        // ──────────────────────────────────────────────
        $grantReason = 'Seed data: admin full capabilities';
        $grantReasonUser = 'Seed data: base capabilities';

        $allCaps = Capability::all();
        foreach ($allCaps as $cap) {
            UserCapabilityGrant::create([
                'user_id' => $admin->id,
                'capability_id' => $cap->id,
                'scope_type' => ScopeType::TENANT,
                'granted_by_user_id' => $admin->id,
                'granted_at' => now(),
                'reason' => $grantReason,
            ]);
        }

        // Give IT director and HR director org view
        $orgView = Capability::where('key', 'task.view.organization')->first();
        foreach ([$allUsers[1], $allUsers[6]] as $u) {
            UserCapabilityGrant::create([
                'user_id' => $u->id,
                'capability_id' => $orgView->id,
                'scope_type' => ScopeType::TENANT,
                'granted_by_user_id' => $admin->id,
                'granted_at' => now(),
                'reason' => $grantReasonUser,
            ]);
        }

        // ──────────────────────────────────────────────
        // 6. WORKING CALENDAR
        // ──────────────────────────────────────────────
        $calendar = WorkingCalendar::factory()->default()->create([
            'name_ar' => 'التقويم الرسمي',
            'name_en' => 'Official Calendar',
        ]);

        // ──────────────────────────────────────────────
        // 7. BLUEPRINT CATEGORIES
        // ──────────────────────────────────────────────
        $cats = collect();
        $catData = [
            ['ar' => 'تقنية المعلومات', 'en' => 'Information Technology'],
            ['ar' => 'الموارد البشرية', 'en' => 'Human Resources'],
            ['ar' => 'المالية والقانون', 'en' => 'Finance & Legal'],
            ['ar' => 'الصحة والخدمات', 'en' => 'Health & Services'],
        ];
        foreach ($catData as $i => $c) {
            $cats->push(BlueprintCategory::create([
                'name_ar' => $c['ar'], 'name_en' => $c['en'],
                'display_order' => $i + 1, 'is_active' => true,
            ]));
        }

        // ──────────────────────────────────────────────
        // 8. SLA POLICIES (3 tiers)
        // ──────────────────────────────────────────────
        $slaFast = SlaPolicy::factory()->create([
            'name_ar' => 'SLA سريع', 'name_en' => 'Fast SLA',
            'sla_value' => 3, 'sla_unit' => SlaUnit::Days, 'warning_threshold_percentage' => 70,
        ]);
        $slaMedium = SlaPolicy::factory()->create([
            'name_ar' => 'SLA متوسط', 'name_en' => 'Medium SLA',
            'sla_value' => 7, 'sla_unit' => SlaUnit::Days, 'warning_threshold_percentage' => 75,
        ]);
        $slaLong = SlaPolicy::factory()->create([
            'name_ar' => 'SLA طويل', 'name_en' => 'Long SLA',
            'sla_value' => 14, 'sla_unit' => SlaUnit::Days, 'warning_threshold_percentage' => 80,
        ]);

        // ──────────────────────────────────────────────
        // 9. BLUEPRINTS WITH STAGES & SUB-STAGES
        // ──────────────────────────────────────────────
        $stageTypes = StageType::pluck('id', 'name_en');
        $blueprints = collect();

        $bpDefs = [
            // [0] تطوير نظام إلكتروني — IT, 4 stages, stage 2 has sub-stages
            [
                'cat' => 0, 'ar' => 'تطوير نظام إلكتروني', 'en' => 'E-System Development',
                'dept' => 2, 'scope' => BlueprintScope::Organization,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'تحليل المتطلبات', 'en' => 'Requirements Analysis',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 0, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Review', 'ar' => 'التصميم والتطوير', 'en' => 'Design & Development',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple,
                        'subs' => [
                            ['ar' => 'تصميم النظام', 'en' => 'System Design', 'req' => true, 'sla' => 0],
                            ['ar' => 'النمذجة الأولية', 'en' => 'Prototyping', 'req' => false, 'sla' => 0],
                        ]],
                    ['type' => 'Approval', 'ar' => 'الاختبار والتحقق', 'en' => 'Testing & Verification',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 0, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Decision', 'ar' => 'الإطلاق', 'en' => 'Launch',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [1] تحديث البنية التحتية — IT, 3 stages
            [
                'cat' => 0, 'ar' => 'تحديث البنية التحتية', 'en' => 'Infrastructure Upgrade',
                'dept' => 2, 'scope' => BlueprintScope::Organization,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'تقييم الاحتياجات', 'en' => 'Needs Assessment',
                        'assign' => AssignmentType::SpecificPosition, 'sla' => 0, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Review', 'ar' => 'تخطيط التنفيذ', 'en' => 'Implementation Planning',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Approval', 'ar' => 'الموافقة والشراء', 'en' => 'Approval & Procurement',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [2] توظيف كوادر جديدة — HR, 4 stages
            [
                'cat' => 1, 'ar' => 'توظيف كوادر جديدة', 'en' => 'Recruitment',
                'dept' => 5, 'scope' => BlueprintScope::Department,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'استلام الطلبات', 'en' => 'Receive Applications',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 0, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Review', 'ar' => 'فرز وترشيح', 'en' => 'Screening & Shortlisting',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Approval', 'ar' => 'المقابلات', 'en' => 'Interviews',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Decision', 'ar' => 'التعيين', 'en' => 'Appointment',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [3] إعداد الميزانية — Finance, 4 stages
            [
                'cat' => 2, 'ar' => 'إعداد الميزانية السنوية', 'en' => 'Annual Budget Preparation',
                'dept' => 8, 'scope' => BlueprintScope::Organization,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'جمع البيانات', 'en' => 'Data Collection',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 2, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Review', 'ar' => 'مراجعة وموازنة', 'en' => 'Review & Balancing',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 1, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Approval', 'ar' => 'اعتماد الميزانية', 'en' => 'Budget Approval',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Decision', 'ar' => 'الإقرار النهائي', 'en' => 'Final Sign-off',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [4] مراجعة العقود — Legal, 3 stages
            [
                'cat' => 2, 'ar' => 'مراجعة العقود', 'en' => 'Contract Review',
                'dept' => 11, 'scope' => BlueprintScope::Organization,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'الاستلام والتصنيف', 'en' => 'Receive & Classify',
                        'assign' => AssignmentType::SpecificPosition, 'sla' => 0, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Review', 'ar' => 'المراجعة القانونية', 'en' => 'Legal Review',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Decision', 'ar' => 'إصدار التوصية', 'en' => 'Issue Recommendation',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [5] حملة توعية صحية — Health, 4 stages
            [
                'cat' => 3, 'ar' => 'حملة توعية صحية', 'en' => 'Health Awareness Campaign',
                'dept' => 12, 'scope' => BlueprintScope::Organization,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'التخطيط', 'en' => 'Planning',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 2, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Review', 'ar' => 'إعداد المواد', 'en' => 'Material Preparation',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple,
                        'subs' => [
                            ['ar' => 'تصميم المواد التوعوية', 'en' => 'Design Awareness Materials', 'req' => true, 'sla' => 1],
                            ['ar' => 'المراجعة اللغوية', 'en' => 'Linguistic Review', 'req' => true, 'sla' => 1],
                            ['ar' => 'اعتماد المحتوى', 'en' => 'Content Approval', 'req' => true, 'sla' => 1],
                        ]],
                    ['type' => 'Action', 'ar' => 'التنفيذ', 'en' => 'Execution',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 0, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Approval', 'ar' => 'التقييم', 'en' => 'Evaluation',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [6] برنامج تدريب الموظفين — HR, 3 stages, stage 2 has sub-stages
            [
                'cat' => 1, 'ar' => 'برنامج تدريب الموظفين', 'en' => 'Employee Training Program',
                'dept' => 7, 'scope' => BlueprintScope::Department,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'تحديد الاحتياجات', 'en' => 'Needs Identification',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 0, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Review', 'ar' => 'تصميم البرنامج', 'en' => 'Program Design',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Single,
                        'subs' => [
                            ['ar' => 'اختيار المدربين', 'en' => 'Select Trainers', 'req' => true, 'sla' => 1],
                            ['ar' => 'إعداد الجدول', 'en' => 'Schedule Preparation', 'req' => true, 'sla' => 1],
                        ]],
                    ['type' => 'Decision', 'ar' => 'التنفيذ والتقييم', 'en' => 'Execution & Evaluation',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
            // [7] المشتريات — Supply Chain, 3 stages
            [
                'cat' => 2, 'ar' => 'إجراءات المشتريات', 'en' => 'Procurement Process',
                'dept' => 13, 'scope' => BlueprintScope::Organization,
                'stages' => [
                    ['type' => 'Action', 'ar' => 'طلب الشراء', 'en' => 'Purchase Request',
                        'assign' => AssignmentType::SpecificPosition, 'sla' => 0, 'card' => AssignmentCardinality::Single],
                    ['type' => 'Review', 'ar' => 'مراجعة العروض', 'en' => 'Bid Review',
                        'assign' => AssignmentType::ManualAtLaunch, 'sla' => 1, 'card' => AssignmentCardinality::Multiple],
                    ['type' => 'Decision', 'ar' => 'الترسية', 'en' => 'Award',
                        'assign' => AssignmentType::DepartmentHead, 'sla' => 2, 'card' => AssignmentCardinality::Single],
                ],
            ],
        ];

        $slaPolicies = [$slaFast, $slaMedium, $slaLong];

        $bpDescAr = [
            'نموذج معتمد لتطوير أنظمة تقنية المعلومات وفق أفضل الممارسات في إدارة المشاريع',
            'إجراءات تنفيذ مشاريع البنية التحتية بدءاً من تقييم الاحتياجات وصولاً إلى الشراء والتنفيذ',
            'عملية توظيف متكاملة تغطي استلام الطلبات والفرز والترشيح والمقابلات والتعيين',
            'إعداد الميزانية السنوية للوزارة وفق الإجراءات المالية المعتمدة',
            'المراجعة القانونية للعقود والمذكرات القانونية ورفع التوصيات',
            'إطلاق وتنفيذ الحملات التوعوية للقطاع الصحي',
            'تصميم وتنفيذ برامج تدريبية لتطوير مهارات الموظفين',
            'إجراءات الشراء والتعاقد مع الموردين وفق اللوائح التنظيمية',
        ];
        $bpDescEn = [
            'Approved workflow for IT systems development following project management best practices',
            'Infrastructure project execution from needs assessment through procurement and deployment',
            'End-to-end recruitment process covering applications, screening, interviews, and appointment',
            'Annual ministry budget preparation following approved financial procedures and deadlines',
            'Legal review of contracts, agreements, and legal memos with recommendations',
            'Planning and executing public health awareness campaigns across the sector',
            'Design and delivery of employee training programs for skills development',
            'Procurement and contracting procedures in compliance with regulatory framework',
        ];
        $stageDesc = [
            0 => [
                'جمع وتحليل متطلبات المستخدمين وإعداد وثيقة المواصفات الفنية',
                'تصميم وتطوير النظام وفق المواصفات المعتمدة مع النمذجة الأولية',
                'اختبار النظام والتحقق من مطابقته للمتطلبات وإعداد تقرير الاختبار',
                'الإطلاق النهائي للنظام ونقله للبيئة الإنتاجية',
            ],
            1 => [
                'تقييم البنية التحتية الحالية وتحديد الاحتياجات',
                'إعداد خطة التنفيذ وجدولة الأعمال وتوزيع المهام',
                'الموافقة على خطة التنفيذ واعتماد المشتريات المطلوبة',
            ],
            2 => [
                'استلام طلبات المتقدمين وتصنيفها حسب المؤهلات',
                'فرز الطلبات وترشيح المرشحين الأوائل للمقابلات',
                'إجراء المقابلات الشخصية وتقييم المرشحين',
                'اعتماد التعيين وإصدار قرارات التوظيف',
            ],
            3 => [
                'جمع بيانات الميزانية من جميع الإدارات',
                'مراجعة وموازنة التقديرات المالية المقدمة',
                'اعتماد الميزانية من قبل الجهات المختصة',
                'الإقرار النهائي للميزانية ورفعها',
            ],
            4 => [
                'استلام العقود والاتفاقيات القانونية وتصنيفها حسب الأولوية',
                'المراجعة القانونية للشروط والأحكام',
                'إصدار التوصية القانونية النهائية',
            ],
            5 => [
                'التخطيط للحملة وتحديد الأهداف والفئات المستهدفة',
                'إعداد المواد التوعوية والإعلانية',
                'تنفيذ الحملة في المواقع والمنصات المحددة',
                'تقييم نتائج الحملة وقياس الأثر',
            ],
            6 => [
                'تحديد الاحتياجات التدريبية بالتعاون مع الإدارات',
                'تصميم البرنامج التدريبي واختيار المدربين',
                'تنفيذ البرنامج وتقييم نتائجه',
            ],
            7 => [
                'استلام طلبات الشراء والتأكد من مطابقتها للمواصفات',
                'مراجعة عروض الموردين وتحليلها',
                'اعتماد الترسية وإصدار أمر الشراء',
            ],
        ];

        $bpIdx = 0;
        foreach ($bpDefs as $m) {
            $bp = Blueprint::factory()->create([
                'category_id' => $cats[$m['cat']]->id,
                'name_ar' => $m['ar'],
                'name_en' => $m['en'],
                'description_ar' => $bpDescAr[$bpIdx],
                'description_en' => $bpDescEn[$bpIdx],
                'scope' => $m['scope'],
                'department_id' => $depts[$m['dept']]->id,
                'created_by_user_id' => $admin->id,
            ]);

            $bpStages = collect();
            foreach ($m['stages'] as $si => $s) {
                $slaId = $s['sla'] !== null ? $slaPolicies[$s['sla']]->id : null;
                $stage = BlueprintStage::factory()->create([
                    'blueprint_id' => $bp->id,
                    'stage_type_id' => $stageTypes[$s['type']],
                    'sla_policy_id' => $slaId,
                    'name_ar' => $s['ar'],
                    'name_en' => $s['en'],
                    'description_ar' => ($stageDesc[$bpIdx][$si] ?? $s['ar'].' - '.$m['ar']),
                    'description_en' => ($stageDesc[$bpIdx][$si] ?? $s['en'].' - '.$m['en']),
                    'sequence_order' => $si + 1,
                    'assignment_type' => $s['assign'],
                    'assignment_cardinality' => $s['card'],
                    'completion_rule' => $s['card'] === AssignmentCardinality::Multiple
                        ? CompletionRule::AnyAssignee
                        : CompletionRule::LeadAssignee,
                    'assigned_department_id' => $depts[$m['dept']]->id,
                ]);
                $bpStages->push($stage);

                if (isset($s['subs'])) {
                    foreach ($s['subs'] as $subIdx => $sub) {
                        $subData = [
                            'name_ar' => $sub['ar'],
                            'name_en' => $sub['en'],
                            'description_ar' => ($stageDesc[$bpIdx][$si] ?? $s['ar'].' - '.$m['ar']).' - '.$sub['ar'],
                            'description_en' => ($stageDesc[$bpIdx][$si] ?? $s['en'].' - '.$m['en']).' - '.$sub['en'],
                            'sequence_order' => $subIdx + 1,
                            'is_required' => $sub['req'],
                            'assignment_type' => AssignmentType::ManualAtLaunch,
                            'assignment_cardinality' => AssignmentCardinality::Multiple,
                            'completion_rule' => CompletionRule::AnyAssignee,
                        ];
                        if (isset($sub['sla'])) {
                            $subData['sla_policy_id'] = $slaPolicies[$sub['sla']]->id;
                        }
                        $stage->subStages()->create($subData);
                    }
                }
            }
            $blueprints->push(['bp' => $bp, 'stages' => $bpStages]);
            $bpIdx++;
        }

        // ──────────────────────────────────────────────
        // 10. TASKS (20 tasks — every realistic scenario)
        // ──────────────────────────────────────────────
        $priorities = TaskPriority::all();
        $tasks = collect();

        // Helper: create stage instance with assignments
        $createStageInst = function (
            Task $task, BlueprintStage $bpStage, int $status, int $owningDeptIdx,
            ?string $enteredAt, ?string $exitedAt,
            Collection $assignees, int $leadIdx = 0,
            ?string $completionNoteAr = null, ?string $completionNoteEn = null,
        ) use ($depts) {
            $stageInst = TaskStageInstance::create([
                'task_id' => $task->id,
                'blueprint_stage_id' => $bpStage->id,
                'sequence_order' => $bpStage->sequence_order,
                'owning_department_id' => $depts[$owningDeptIdx]->id,
                'completion_rule' => $bpStage->completion_rule->value,
                'status' => $status,
                'entered_at' => $enteredAt,
                'exited_at' => $exitedAt,
                'completion_note' => $status === StageInstanceStatus::Completed->value ? ($completionNoteAr ?? null) : null,
            ]);

            foreach ($assignees as $a => $user) {
                TaskStageAssignment::create([
                    'task_id' => $task->id,
                    'stage_instance_id' => $stageInst->id,
                    'user_id' => $user->id,
                    'assignment_role' => $a === $leadIdx ? AssignmentRole::Lead : AssignmentRole::Required,
                    'is_completed' => $status === StageInstanceStatus::Completed->value,
                    'assigned_at' => $enteredAt,
                    'completed_at' => $status === StageInstanceStatus::Completed->value ? $exitedAt : null,
                    'completion_note_ar' => $status === StageInstanceStatus::Completed->value ? ($completionNoteAr ?? 'تم الإنجاز') : null,
                    'completion_note_en' => $status === StageInstanceStatus::Completed->value ? ($completionNoteEn ?? 'Completed') : null,
                ]);
            }

            return $stageInst;
        };

        // Helper: create sub-stage instances
        $createSubStageInsts = function (
            Task $task, TaskStageInstance $stageInst, Collection $bpSubStages,
            int $status, string $enteredAt, ?string $exitedAt,
            Collection $assignees,
        ) {
            $instances = collect();
            $isCompleted = $status === SubStageInstanceStatus::Completed->value;
            foreach ($bpSubStages as $ssi => $ss) {
                // Sequential progression: only sub-stage 0 is active, rest are pending
                $subStatus = $isCompleted
                    ? SubStageInstanceStatus::Completed->value
                    : ($ssi === 0 ? SubStageInstanceStatus::Active->value : SubStageInstanceStatus::Pending->value);
                $ssEntered = match (true) {
                    $isCompleted => $enteredAt,
                    $ssi === 0 => $enteredAt,
                    default => null,
                };
                $ssExited = $isCompleted ? $exitedAt : null;
                $inst = TaskSubStageInstance::create([
                    'task_id' => $task->id,
                    'parent_stage_instance_id' => $stageInst->id,
                    'blueprint_sub_stage_id' => $ss->id,
                    'sequence_order' => $ss->sequence_order,
                    'is_required' => $ss->is_required,
                    'completion_rule' => $ss->completion_rule->value,
                    'status' => $subStatus,
                    'entered_at' => $ssEntered,
                    'exited_at' => $ssExited,
                ]);
                foreach ($assignees as $a => $user) {
                    TaskStageAssignment::create([
                        'task_id' => $task->id,
                        'stage_instance_id' => $stageInst->id,
                        'sub_stage_instance_id' => $inst->id,
                        'user_id' => $user->id,
                        'assignment_role' => AssignmentRole::Required,
                        'is_completed' => $isCompleted,
                        'assigned_at' => $ssEntered ?? $enteredAt,
                        'completed_at' => $ssExited,
                    ]);
                }
                $instances->push($inst);
            }

            return $instances;
        };

        // Helper: create SLA timer for stage or sub-stage
        $createSlaTimer = function (
            Task $task, TaskStageInstance $stageInst, SlaPolicy $slaPolicy,
            SlaTimerStatus $status, string $startedAt, int $deadlineDays,
            ?TaskSubStageInstance $subStageInst = null,
        ) use ($calendar) {
            $start = new \DateTime($startedAt);
            $deadline = (clone $start)->modify("+{$deadlineDays} days");
            $warningAt = (clone $deadline)->modify('-'.round($deadlineDays * (100 - $slaPolicy->warning_threshold_percentage) / 100).' days');

            $data = [
                'task_id' => $task->id,
                'stage_instance_id' => $subStageInst ? null : $stageInst->id,
                'sub_stage_instance_id' => $subStageInst?->id,
                'sla_policy_id' => $slaPolicy->id,
                'working_calendar_id' => $calendar->id,
                'status' => $status->value,
                'started_at' => $startedAt,
                'deadline_at' => $deadline->format('Y-m-d H:i:s'),
                'warning_at' => $status !== SlaTimerStatus::Breached ? $warningAt->format('Y-m-d H:i:s') : null,
                'elapsed_before_pause' => 0,
            ];

            if ($status === SlaTimerStatus::Breached) {
                $data['warning_at'] = $warningAt->format('Y-m-d H:i:s');
            }

            return SlaTimerInstance::create($data);
        };

        // ── TASK DEFINITIONS ──
        // times are relative to "now" for consistency
        // User index reference:
        //   0 = admin (System Admin)    1 = Noura Al-Qahtani     2 = Abdullah Al-Otaibi
        //   3 = Sara Al-Dosari          4 = Faisal Al-Zahrani    5 = Hind Al-Shammari
        //   6 = Omar Al-Sulami          7 = Reem Al-Harbi        8 = Khalid Al-Ghamdi
        //   9 = Manal Al-Juhani        10 = Turki Al-Mutairi
        //  11 = Naif Al-Baqmi          12 = Mashaal Al-Shahrani 13 = Yousef Al-Zahrani
        //  14 = Fatima Al-Ansari
        $now = now();
        $taskDefs = [
            // [0] Active — Stage 1 (Action), Critical, Confidential, SLA running, 3 assignees
            [
                'bp' => 0, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'تطوير نظام التراخيص الإلكترونية',
                'title_en' => 'E-Licensing System Development',
                'desc_ar' => 'تطوير نظام متكامل لإدارة إصدار وتجديد التراخيص المهنية لجميع القطاعات في الوزارة، يشمل النظام لوحات تحكم للمستخدمين وإدارة الطلبات والتقارير',
                'desc_en' => 'Develop an integrated system for managing professional license issuance and renewal across all ministry sectors, including user dashboards, request management, and reports',
                'initiator' => 1,
                'status' => TaskStatus::Active,
                'created_days_ago' => 10, 'due_days_from_now' => 20,
                'stages' => [
                    [0, StageInstanceStatus::Active->value, 2, 'now-10d',
                        null, [1, 2, 4], 0, 'sla' => ['policy' => 0, 'status' => SlaTimerStatus::Running, 'deadline_days' => 14]],
                ],
            ],
            // [1] Active — Stage 1 (Action), Urgent, Internal, SLA breached, 2 assignees
            [
                'bp' => 1, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'تحديث شبكة البيانات الرئيسية',
                'title_en' => 'Core Network Upgrade',
                'desc_ar' => 'تحديث البنية التحتية لشبكة البيانات الرئيسية في مبنى الوزارة، يشمل ذلك استبدال أجهزة التوجيه والمحولات ورفع سعة الربط إلى 10 جيجابت',
                'desc_en' => 'Upgrade the core data network infrastructure in the ministry building, including replacing routers and switches and increasing link capacity to 10 Gbps',
                'initiator' => 0,
                'status' => TaskStatus::Active,
                'created_days_ago' => 5, 'due_days_from_now' => 2,
                'stages' => [
                    [0, StageInstanceStatus::Active->value, 2, 'now-5d',
                        null, [4, 3], 0, 'sla' => ['policy' => 0, 'status' => SlaTimerStatus::Breached, 'deadline_days' => 3]],
                ],
                'has_escalation' => true,
            ],
            // [2] Active — Stage 2 (Review), Critical, Public, SLA running, returned once, 4 assignees
            [
                'bp' => 0, 'prio' => 0, 'class' => ClassificationLevel::Public,
                'title_ar' => 'نظام إدارة الوثائق الإلكترونية',
                'title_en' => 'Electronic Document Management System',
                'desc_ar' => 'بناء نظام لإدارة الوثائق الإلكترونية يؤمن التخزين والتصنيف والاسترجاع للوثائق الإدارية مع صلاحيات وصول متدرجة',
                'desc_en' => 'Build an electronic document management system that provides secure storage, classification, and retrieval of administrative documents with tiered access permissions',
                'initiator' => 3,
                'status' => TaskStatus::Active,
                'created_days_ago' => 20, 'due_days_from_now' => 15,
                'stages' => [
                    // Stage 1 completed
                    [0, StageInstanceStatus::Completed->value, 2, 'now-20d', 'now-15d', [1, 3, 5, 4], 0],
                    // Stage 2 active — has sub-stages
                    [1, StageInstanceStatus::Active->value, 2, 'now-15d', null, [1, 2, 5, 4], 2,
                        'sla' => ['policy' => 1, 'status' => SlaTimerStatus::Running, 'deadline_days' => 7]],
                    // Stage 1 was returned before (create a return instance)
                ],
                'extra_return' => true,
            ],
            // [3] Active — Stage 3 (Approval), Routine, Internal, SLA breached, 1 assignee
            [
                'bp' => 4, 'prio' => 2, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'مراجعة عقود الموردين السنوية',
                'title_en' => 'Annual Supplier Contract Review',
                'desc_ar' => 'مراجعة جميع العقود المبرمة مع الموردين للعام الحالي والتأكد من مطابقتها للأنظمة واللوائح، وتقديم التوصيات بشأن التجديد أو التعديل',
                'desc_en' => 'Review all contracts signed with suppliers for the current year, ensure compliance with regulations, and provide recommendations for renewal or amendment',
                'initiator' => 8,
                'status' => TaskStatus::Active,
                'created_days_ago' => 15, 'due_days_from_now' => -3,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 11, 'now-15d', 'now-11d', [8], 0],
                    [1, StageInstanceStatus::Completed->value, 11, 'now-11d', 'now-8d', [8, 9], 0],
                    [2, StageInstanceStatus::Active->value, 11, 'now-8d', null, [9, 13], 0,
                        'sla' => ['policy' => 0, 'status' => SlaTimerStatus::Breached, 'deadline_days' => 3]],
                ],
                'has_escalation' => true,
            ],
            // [4] Active — Stage 2 (Review), Urgent, Confidential, SLA warning, 2 assignees
            [
                'bp' => 2, 'prio' => 1, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'توظيف قيادات الصف الثاني',
                'title_en' => 'Second-Tier Leadership Recruitment',
                'desc_ar' => 'عملية توظيف مستهدفة لشغل مناصب قيادية في الإدارات العامة، تشمل الإعلان والفرز والمقابلات والتقييم',
                'desc_en' => 'Targeted recruitment process to fill leadership positions in general administrations, including announcement, screening, interviews, and evaluation',
                'initiator' => 6,
                'status' => TaskStatus::Active,
                'created_days_ago' => 8, 'due_days_from_now' => 5,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 5, 'now-8d', 'now-6d', [6], 0],
                    [1, StageInstanceStatus::Active->value, 5, 'now-6d', null, [7, 6], 0,
                        'sla' => ['policy' => 1, 'status' => SlaTimerStatus::Warning, 'deadline_days' => 7]],
                ],
            ],
            // [5] Active — Stage 1 (Action), Routine, Public, 2 assignees, no SLA
            [
                'bp' => 6, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'title_ar' => 'برنامج تدريب الموظفين الجدد',
                'title_en' => 'New Employee Training Program',
                'desc_ar' => 'تصميم وتنفيذ برنامج تدريبي شامل للموظفين الجدد في الوزارة يشمل التعريف بالهيكل التنظيمي والأنظمة والإجراءات',
                'desc_en' => 'Design and implement a comprehensive training program for new ministry employees covering organizational structure, systems, and procedures',
                'initiator' => 0,
                'status' => TaskStatus::Active,
                'created_days_ago' => 3, 'due_days_from_now' => 25,
                'stages' => [
                    [0, StageInstanceStatus::Active->value, 7, 'now-3d', null, [6, 7], 0],
                ],
            ],
            // [6] Active — Stage 3 (Decision), Critical, Confidential, SLA running, multi-assignee
            [
                'bp' => 3, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'إعداد الميزانية التشغيلية',
                'title_en' => 'Operational Budget Preparation',
                'desc_ar' => 'إعداد الميزانية التشغيلية للوزارة للعام المالي القادم بناءً على الخطط الاستراتيجية ورفعها للاعتماد',
                'desc_en' => 'Prepare the ministry operational budget for the next fiscal year based on strategic plans and submit for approval',
                'initiator' => 9,
                'status' => TaskStatus::Active,
                'created_days_ago' => 25, 'due_days_from_now' => 10,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 8, 'now-25d', 'now-20d', [10, 14], 0],
                    [1, StageInstanceStatus::Completed->value, 8, 'now-20d', 'now-15d', [9], 0],
                    [2, StageInstanceStatus::Completed->value, 8, 'now-15d', 'now-10d', [9, 10], 0],
                    [3, StageInstanceStatus::Active->value, 8, 'now-10d', null, [9, 13], 0,
                        'sla' => ['policy' => 2, 'status' => SlaTimerStatus::Running, 'deadline_days' => 14]],
                ],
            ],
            // [7] Active — Stage 2 (Review), Public, Health campaign, 3 assignees
            [
                'bp' => 5, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'title_ar' => 'حملة التوعية بمرض السكري',
                'title_en' => 'Diabetes Awareness Campaign',
                'desc_ar' => 'إطلاق حملة توعوية شاملة عن مرض السكري تشمل فعاليات توعوية وفحوصات مجانية ومطبوعات تثقيفية تستهدف 5000 مستفيد',
                'desc_en' => 'Launch a comprehensive diabetes awareness campaign including awareness events, free screenings, and educational materials targeting 5,000 beneficiaries',
                'initiator' => 9,
                'status' => TaskStatus::Active,
                'created_days_ago' => 12, 'due_days_from_now' => 20,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 12, 'now-12d', 'now-8d', [10, 12], 0],
                    [1, StageInstanceStatus::Active->value, 12, 'now-8d', null, [10, 11, 12], 0,
                        'sla' => ['policy' => 1, 'status' => SlaTimerStatus::Running, 'deadline_days' => 7]],
                ],
            ],
            // [8] Active — Stage 1, Urgent, Internal, SLA warning, single assignee
            [
                'bp' => 7, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'توريد أجهزة للخوادم',
                'title_en' => 'Server Equipment Procurement',
                'desc_ar' => 'توريد وتركيب خوادم جديدة لمركز البيانات الرئيسي وفق المواصفات الفنية المعتمدة',
                'desc_en' => 'Supply and install new servers for the main data center according to approved technical specifications',
                'initiator' => 3,
                'status' => TaskStatus::Active,
                'created_days_ago' => 6, 'due_days_from_now' => 0,
                'stages' => [
                    [0, StageInstanceStatus::Active->value, 13, 'now-6d', null, [4], 0,
                        'sla' => ['policy' => 0, 'status' => SlaTimerStatus::Warning, 'deadline_days' => 3]],
                ],
            ],
            // [9] Active — Stage 2 with sub-stages, large team
            [
                'bp' => 5, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'مبادرة الصحة المدرسية',
                'title_en' => 'School Health Initiative',
                'desc_ar' => 'مبادرة وطنية للكشف المبكر عن الأمراض بين طلاب المدارس في جميع مناطق المملكة',
                'desc_en' => 'National initiative for early disease detection among school students in all regions of the Kingdom',
                'initiator' => 9,
                'status' => TaskStatus::Active,
                'created_days_ago' => 18, 'due_days_from_now' => 30,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 12, 'now-18d', 'now-14d', [10, 0], 0],
                    [1, StageInstanceStatus::Active->value, 12, 'now-14d', null, [10, 0, 11], 0,
                        'sla' => ['policy' => 1, 'status' => SlaTimerStatus::Running, 'deadline_days' => 7]],
                ],
            ],
            // [10] Suspended — in Stage 1, Grey
            [
                'bp' => 1, 'prio' => 2, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'مشروع الحوسبة السحابية',
                'title_en' => 'Cloud Computing Project',
                'desc_ar' => 'نقل البنية التحتية لتقنية المعلومات إلى الحوسبة السحابية الحكومية، يشمل تقييم التطبيقات والترحيل والاختبار',
                'desc_en' => 'Migrate IT infrastructure to government cloud computing, including application assessment, migration, and testing',
                'initiator' => 0,
                'status' => TaskStatus::Suspended,
                'created_days_ago' => 14, 'due_days_from_now' => 30,
                'suspension_reason' => 'تعليق مؤقت لحين اعتماد الميزانية الإضافية',
                'stages' => [
                    [0, StageInstanceStatus::Active->value, 2, 'now-14d', null, [0, 3], 0],
                ],
            ],
            // [11] Suspended — in Stage 2
            [
                'bp' => 2, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'استقطاب الكفاءات النادرة',
                'title_en' => 'Rare Talent Acquisition',
                'desc_ar' => 'استقطاب كفاءات مهنية نادرة في المجال الصحي والتقني من خارج المملكة',
                'desc_en' => 'Recruit rare professional talents in the health and technical fields from outside the Kingdom',
                'initiator' => 6,
                'status' => TaskStatus::Suspended,
                'created_days_ago' => 20, 'due_days_from_now' => 20,
                'suspension_reason' => 'في انتظار موافقة الجهات العليا',
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 5, 'now-20d', 'now-16d', [6], 0],
                    [1, StageInstanceStatus::Active->value, 5, 'now-16d', null, [7], 0],
                ],
            ],
            // [12] Completed — 4 stages all done
            [
                'bp' => 3, 'prio' => 1, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'تسوية الحسابات الربعية',
                'title_en' => 'Quarterly Account Settlement',
                'desc_ar' => 'تسوية حسابات الوزارة للربع المالي الأول مع الجهات ذات العلاقة',
                'desc_en' => 'Settle the ministry accounts for the first financial quarter with relevant authorities',
                'initiator' => 9,
                'status' => TaskStatus::Completed,
                'created_days_ago' => 60, 'due_days_from_now' => -30,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 8, 'now-60d', 'now-50d', [10, 14], 0, 'جمع البيانات', 'Data collected'],
                    [1, StageInstanceStatus::Completed->value, 8, 'now-50d', 'now-40d', [9], 0, 'تمت المراجعة', 'Reviewed'],
                    [2, StageInstanceStatus::Completed->value, 8, 'now-40d', 'now-30d', [9, 10], 0, 'تم الاعتماد', 'Approved'],
                    [3, StageInstanceStatus::Completed->value, 8, 'now-30d', 'now-25d', [9, 13], 0, 'تم الإقرار النهائي', 'Final sign-off completed'],
                ],
            ],
            // [13] Completed — 3 stages, with sub-stages
            [
                'bp' => 0, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'title_ar' => 'نظام إدارة الطلبات الداخلية',
                'title_en' => 'Internal Requests Management System',
                'desc_ar' => 'تطوير نظام داخلي لإدارة الطلبات الإدارية بين الإدارات المختلفة في الوزارة',
                'desc_en' => 'Develop an internal system for managing administrative requests between different departments in the ministry',
                'initiator' => 0,
                'status' => TaskStatus::Completed,
                'created_days_ago' => 90, 'due_days_from_now' => -45,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 2, 'now-90d', 'now-75d', [1, 2], 0, 'تم تحليل المتطلبات', 'Requirements analyzed'],
                    [1, StageInstanceStatus::Completed->value, 2, 'now-75d', 'now-50d', [1, 2, 5], 0, 'تم التطوير', 'Development completed'],
                    [2, StageInstanceStatus::Completed->value, 2, 'now-50d', 'now-40d', [0], 0, 'اجتاز الاختبارات', 'Tests passed'],
                    [3, StageInstanceStatus::Completed->value, 2, 'now-40d', 'now-35d', [0], 0, 'تم الإطلاق', 'Launched successfully'],
                ],
            ],
            // [14] Completed — 3 stages, Legal
            [
                'bp' => 4, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'title_ar' => 'مراجعة لائحة المشتريات',
                'title_en' => 'Procurement Regulations Review',
                'desc_ar' => 'مراجعة وتحديث لائحة المشتريات الحكومية بما يتوافق مع الأنظمة الجديدة',
                'desc_en' => 'Review and update government procurement regulations to align with new regulations',
                'initiator' => 8,
                'status' => TaskStatus::Completed,
                'created_days_ago' => 45, 'due_days_from_now' => -10,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 11, 'now-45d', 'now-38d', [9], 0, 'تم التصنيف', 'Classified'],
                    [1, StageInstanceStatus::Completed->value, 11, 'now-38d', 'now-20d', [9, 0], 0, 'تمت المراجعة', 'Reviewed'],
                    [2, StageInstanceStatus::Completed->value, 11, 'now-20d', 'now-15d', [9], 0, 'تم إصدار التوصية', 'Recommendation issued'],
                ],
            ],
            // [15] Cancelled — Stage 1, reason: change in priorities
            [
                'bp' => 7, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'توريد أجهزة اتصال مشفرة',
                'title_en' => 'Encrypted Communication Equipment',
                'desc_ar' => 'توريد أجهزة اتصال مشفرة للاستخدامات الحساسة في الوزارة',
                'desc_en' => 'Supply encrypted communication equipment for sensitive use in the ministry',
                'initiator' => 0,
                'status' => TaskStatus::Cancelled,
                'created_days_ago' => 30, 'due_days_from_now' => null,
                'cancellation_reason' => 'إلغاء المشروع بسبب تغير الأولويات الاستراتيجية للوزارة',
                'stages' => [
                    [0, StageInstanceStatus::Active->value, 13, 'now-30d', 'now-25d', [4], 0],
                ],
            ],
            // [16] Cancelled — Draft cancelled before launch
            [
                'bp' => 6, 'prio' => 2, 'class' => ClassificationLevel::Public,
                'title_ar' => 'برنامج تطوير المهارات القيادية',
                'title_en' => 'Leadership Skills Development Program',
                'desc_ar' => 'برنامج تطويري لتنمية المهارات القيادية لرؤساء الأقسام والإدارات',
                'desc_en' => 'A development program to enhance leadership skills for section and department heads',
                'initiator' => 6,
                'status' => TaskStatus::Cancelled,
                'created_days_ago' => 40, 'due_days_from_now' => null,
                'cancellation_reason' => 'ألغي المشروع قبل الإطلاق لعدم توفر الميزانية',
                'stages' => [],
            ],
            // [17] Draft — not launched yet
            [
                'bp' => 0, 'prio' => 2, 'class' => ClassificationLevel::Internal,
                'title_ar' => 'نظام التقارير الذكية',
                'title_en' => 'Smart Reports System',
                'desc_ar' => 'مسودة مشروع نظام تقارير ذكي يستخدم تقنيات الذكاء الاصطناعي لتحليل بيانات الأداء',
                'desc_en' => 'Draft project for a smart reporting system using AI technologies to analyze performance data',
                'initiator' => 0,
                'status' => TaskStatus::Draft,
                'created_days_ago' => 2, 'due_days_from_now' => 120,
                'stages' => [],
            ],
            // [18] Draft — not launched yet
            [
                'bp' => 5, 'prio' => 1, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'حملة التوعية بالأمن الصحي',
                'title_en' => 'Health Security Awareness Campaign',
                'desc_ar' => 'مسودة خطة حملة توعوية حول الأمن الصحي والاستعداد للطوارئ',
                'desc_en' => 'Draft plan for a health security awareness campaign and emergency preparedness',
                'initiator' => 9,
                'status' => TaskStatus::Draft,
                'created_days_ago' => 1, 'due_days_from_now' => 90,
                'stages' => [],
            ],
            // [19] Active — Stage 2, SLA breached, multi-dept
            [
                'bp' => 3, 'prio' => 0, 'class' => ClassificationLevel::Confidential,
                'title_ar' => 'تدقيق الميزانية السرية',
                'title_en' => 'Confidential Budget Audit',
                'desc_ar' => 'تدقيق شامل للميزانية السرية للوزارة للكشف عن أي تجاوزات مالية',
                'desc_en' => 'Comprehensive audit of the ministry confidential budget to detect any financial irregularities',
                'initiator' => 0,
                'status' => TaskStatus::Active,
                'created_days_ago' => 12, 'due_days_from_now' => -2,
                'stages' => [
                    [0, StageInstanceStatus::Completed->value, 8, 'now-12d', 'now-8d', [14], 0],
                    [1, StageInstanceStatus::Active->value, 8, 'now-8d', null, [9], 0,
                        'sla' => ['policy' => 0, 'status' => SlaTimerStatus::Breached, 'deadline_days' => 3]],
                ],
                'has_escalation' => true,
            ],
        ];

        // Process task definitions
        foreach ($taskDefs as $td) {
            $bpData = $blueprints[$td['bp']];
            $bp = $bpData['bp'];
            $bpStages = $bpData['stages'];
            $createdAt = $now->copy()->subDays($td['created_days_ago']);
            $launchedAt = in_array($td['status'], [TaskStatus::Active, TaskStatus::Suspended, TaskStatus::Completed])
                ? $createdAt->copy()->addHours(2)
                : null;

            $task = Task::create([
                'blueprint_id' => $bp->id,
                'priority_id' => $priorities[$td['prio']]->id,
                'title_ar' => $td['title_ar'],
                'title_en' => $td['title_en'],
                'description_ar' => $td['desc_ar'],
                'description_en' => $td['desc_en'],
                'classification_level' => $td['class'],
                'initiator_user_id' => $allUsers[$td['initiator']]->id,
                'status' => $td['status'],
                'due_date' => $td['due_days_from_now'] !== null
                    ? ($td['due_days_from_now'] >= 0 ? $now->copy()->addDays($td['due_days_from_now'])->toDateString()
                        : $now->copy()->addDays($td['due_days_from_now'])->toDateString())
                    : null,
                'launched_at' => $launchedAt,
                'suspended_at' => $td['status'] === TaskStatus::Suspended
                    ? $createdAt->copy()->addDays(3) : null,
                'suspension_reason' => $td['suspension_reason'] ?? null,
                'completed_at' => $td['status'] === TaskStatus::Completed
                    ? $createdAt->copy()->addDays(25) : null,
                'cancelled_at' => $td['status'] === TaskStatus::Cancelled
                    ? $createdAt->copy()->addDays(5) : null,
                'cancellation_reason' => $td['cancellation_reason'] ?? null,
            ]);
            Task::where('id', $task->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
            $task = $task->fresh();

            // Create stage instances
            $activeStageIdx = null;
            foreach ($td['stages'] as $si) {
                $stageIdx = $si[0];
                $status = $si[1];
                $deptIdx = $si[2];
                $enteredAtStr = $si[3];
                $exitedAtStr = $si[4];
                $assigneeIndices = $si[5];
                $leadIdx = $si[6] ?? 0;

                // Parse date strings
                $enteredAt = $this->parseDateStr($enteredAtStr, $now, $createdAt, $launchedAt);
                $exitedAt = $exitedAtStr ? $this->parseDateStr($exitedAtStr, $now, $createdAt, $launchedAt) : null;

                // Get the BP stage
                $bpStage = $bpStages[$stageIdx];
                $assignees = collect($assigneeIndices)->map(fn ($idx) => $allUsers[$idx]);

                // Check for completion note
                $noteAr = $si[9] ?? null;
                $noteEn = $si[10] ?? null;

                $stageInst = $createStageInst(
                    $task, $bpStage, $status, $deptIdx,
                    $enteredAt, $exitedAt, $assignees, $leadIdx,
                    $noteAr, $noteEn,
                );

                // Create sub-stage instances if the BP stage has them and this is an active/completed stage
                $bpSubStages = $bpStage->subStages;
                $createdSubStageInsts = collect();
                if ($bpSubStages->isNotEmpty() && $status !== StageInstanceStatus::Pending->value) {
                    $createdSubStageInsts = $createSubStageInsts(
                        $task, $stageInst, $bpSubStages, $status === StageInstanceStatus::Completed->value
                            ? SubStageInstanceStatus::Completed->value
                            : SubStageInstanceStatus::Active->value,
                        $enteredAt, $exitedAt, $assignees,
                    );
                }

                // Create SLA timer if configured
                if (isset($si['sla'])) {
                    $slaCfg = $si['sla'];
                    $slaPol = $slaPolicies[$slaCfg['policy']];
                    $slaStatus = $slaCfg['status'];
                    $deadlineDays = $slaCfg['deadline_days'];

                    $createSlaTimer($task, $stageInst, $slaPol, $slaStatus, $enteredAt, $deadlineDays);

                    foreach ($createdSubStageInsts as $subInst) {
                        if ($subInst->status !== SubStageInstanceStatus::Active->value) {
                            continue;
                        }
                        $bpSubStage = $bpSubStages->firstWhere('id', $subInst->blueprint_sub_stage_id);
                        if ($bpSubStage && $bpSubStage->sla_policy_id) {
                            $createSlaTimer($task, $stageInst, $slaPolicies[$slaCfg['policy']], $slaStatus, $subInst->entered_at ?? $enteredAt, $deadlineDays, $subInst);
                        }
                    }
                }

                // Track active stage for escalation below
                if ($status === StageInstanceStatus::Active->value) {
                    $activeStageIdx = $stageIdx;
                }
            }

            // Create returned stage instance for task 2
            if (($td['extra_return'] ?? false) && $bpStages->count() > 0) {
                $bpStage = $bpStages[0];
                $returnBase = $launchedAt ?? $createdAt;
                $returnEntered = (clone $returnBase)->addDays(2);
                $returnExited = (clone $returnBase)->addDays(3);
                $returnedInst = TaskStageInstance::create([
                    'task_id' => $task->id,
                    'blueprint_stage_id' => $bpStage->id,
                    'sequence_order' => $bpStage->sequence_order,
                    'owning_department_id' => $depts[2]->id,
                    'completion_rule' => $bpStage->completion_rule->value,
                    'status' => StageInstanceStatus::Returned,
                    'entered_at' => $returnEntered,
                    'exited_at' => $returnExited,
                    'return_reason' => 'نقص في المتطلبات المقدمة، طلب إعادة تحليل / Incomplete requirements, re-analysis requested',
                ]);
                TaskStageAssignment::create([
                    'task_id' => $task->id,
                    'stage_instance_id' => $returnedInst->id,
                    'user_id' => $allUsers[$td['stages'][0][5][0]]->id ?? $allUsers[0]->id,
                    'assignment_role' => AssignmentRole::Lead,
                    'is_completed' => true,
                    'assigned_at' => $returnEntered,
                    'completed_at' => $returnExited,
                ]);
            }

            // Create escalations for breached SLA tasks
            if ($td['has_escalation'] ?? false) {
                $breachedTimer = SlaTimerInstance::where('task_id', $task->id)
                    ->where('status', SlaTimerStatus::Breached)
                    ->first();
                if ($breachedTimer) {
                    Escalation::create([
                        'task_id' => $task->id,
                        'stage_instance_id' => $breachedTimer->stage_instance_id,
                        'sla_timer_instance_id' => $breachedTimer->id,
                        'escalation_type' => EscalationType::AutoSlaBreach,
                        'escalated_to_user_id' => $allUsers[0]->id,
                        'escalated_by_user_id' => $allUsers[0]->id,
                        'reason' => 'تجاوز المهلة الزمنية المحددة للمرحلة دون إنجاز',
                        'status' => EscalationStatus::Open,
                        'created_at' => $now->copy()->subDays(1),
                        'updated_at' => $now->copy()->subDays(1),
                    ]);
                }
            }

            $tasks->push($task);
        }

        // ──────────────────────────────────────────────
        // 11. FOLLOW-UP ACTIONS (for active tasks)
        // ──────────────────────────────────────────────
        $actionReasons = [
            ['ar' => 'متابعة مع المسؤول المباشر لتسريع الإنجاز', 'en' => 'Follow-up with direct supervisor to expedite completion'],
            ['ar' => 'تذكير بتسليم المخرجات المطلوبة', 'en' => 'Reminder to deliver required outputs'],
            ['ar' => 'طلب تقرير مرحلي عن سير العمل', 'en' => 'Request progress report on work status'],
        ];
        $taskIds = $tasks->whereIn('status', [TaskStatus::Active, TaskStatus::Suspended])->take(5);
        foreach ($taskIds as $task) {
            $reason = $actionReasons[array_rand($actionReasons)];
            FollowUpAction::create([
                'task_id' => $task->id,
                'user_id' => $allUsers->random()->id,
                'action_type' => rand(0, 1) === 0 ? FollowUpActionType::PhoneCall : FollowUpActionType::Email,
                'note_ar' => $reason['ar'],
                'note_en' => $reason['en'],
                'contact_name' => $allUsers->random()->name_en,
                'created_at' => $now->copy()->subDays(rand(1, 5)),
                'updated_at' => $now->copy()->subDays(rand(1, 5)),
            ]);
        }

        // ──────────────────────────────────────────────
        // 12. NOTIFICATIONS
        // ──────────────────────────────────────────────
        $notifSpecs = [
            ['user' => 1, 'type' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Task Assigned to You',
                'body_ar' => 'تم إسناد مهمة "نظام التراخيص الإلكترونية" إليك', 'body_en' => 'Task "E-Licensing System" has been assigned to you',
                'task' => 0, 'read' => false, 'days' => 5],
            ['user' => 0, 'type' => NotificationType::SlaWarning,
                'title_ar' => 'تنبيه مهلة زمنية', 'title_en' => 'SLA Warning',
                'body_ar' => 'مهلة مهمة "تحديث شبكة البيانات" على وشك الانتهاء', 'body_en' => 'SLA for task "Core Network Upgrade" is about to expire',
                'task' => 1, 'read' => false, 'days' => 1],
            ['user' => 0, 'type' => NotificationType::SlaBreach,
                'title_ar' => 'اختراق المهلة الزمنية', 'title_en' => 'SLA Breached',
                'body_ar' => 'تم تجاوز المهلة الزمنية لمهمة "مراجعة عقود الموردين"', 'body_en' => 'SLA has been breached for task "Supplier Contract Review"',
                'task' => 3, 'read' => false, 'days' => 2],
            ['user' => 0, 'type' => NotificationType::TaskCompleted,
                'title_ar' => 'اكتمال مهمة', 'title_en' => 'Task Completed',
                'body_ar' => 'تم اكتمال مهمة "تسوية الحسابات الربعية" بنجاح', 'body_en' => 'Task "Quarterly Account Settlement" completed successfully',
                'task' => 12, 'read' => true, 'days' => 30],
            ['user' => 6, 'type' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Task Assigned to You',
                'body_ar' => 'تم إسناد مهمة "توظيف القيادات" إليك في مرحلة الفرز', 'body_en' => 'Task "Leadership Recruitment" assigned to you in screening stage',
                'task' => 4, 'read' => false, 'days' => 4],
            ['user' => 0, 'type' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Stage Assigned',
                'body_ar' => 'تم إسناد مرحلة جديدة في مهمة "إعداد الميزانية" إليك', 'body_en' => 'New stage assigned in task "Budget Preparation"',
                'task' => 6, 'read' => true, 'days' => 12],
            ['user' => 9, 'type' => NotificationType::SlaWarning,
                'title_ar' => 'تنبيه مهلة زمنية', 'title_en' => 'SLA Warning',
                'body_ar' => 'يقترب موعد تسليم مهمة "الميزانية التشغيلية"', 'body_en' => 'Deadline approaching for "Operational Budget" task',
                'task' => 6, 'read' => false, 'days' => 3],
            ['user' => 1, 'type' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'إعادة إسناد', 'title_en' => 'Re-assignment',
                'body_ar' => 'أعيد إسناد مهمة "التراخيص الإلكترونية" إليك بعد التعديلات', 'body_en' => 'Task "E-Licensing System" re-assigned after revision',
                'task' => 0, 'read' => false, 'days' => 2],
            ['user' => 0, 'type' => NotificationType::TaskCancelled,
                'title_ar' => 'إلغاء مهمة', 'title_en' => 'Task Cancelled',
                'body_ar' => 'تم إلغاء مهمة "أجهزة الاتصال المشفرة"', 'body_en' => 'Task "Encrypted Communication Equipment" has been cancelled',
                'task' => 15, 'read' => true, 'days' => 25],
            ['user' => 3, 'type' => NotificationType::StageAssignmentReceived,
                'title_ar' => 'تم إسناد مهمة إليك', 'title_en' => 'Task Assigned',
                'body_ar' => 'تم إسناد مهمة "توريد أجهزة الخوادم" إليك', 'body_en' => 'Task "Server Equipment Procurement" assigned to you',
                'task' => 8, 'read' => false, 'days' => 3],
        ];

        foreach ($notifSpecs as $ns) {
            $notifCreatedAt = $now->copy()->subDays($ns['days']);
            $taskModel = $tasks[$ns['task']];

            $allUsers[$ns['user']]->notifications()->create([
                'id' => (string) Str::uuid7(),
                'type' => StageAssignmentReceivedNotification::class,
                'data' => [
                    'notification_type' => $ns['type']->value,
                    'dedupe_key' => (string) Str::uuid7(),
                    'title_ar' => $ns['title_ar'],
                    'title_en' => $ns['title_en'],
                    'body_ar' => $ns['body_ar'],
                    'body_en' => $ns['body_en'],
                    'task_public_id' => $taskModel->public_id,
                    'action_url' => '/tasks/'.$taskModel->public_id,
                ],
                'read_at' => $ns['read'] ? (clone $notifCreatedAt)->addHour() : null,
                'created_at' => $notifCreatedAt,
                'updated_at' => $notifCreatedAt,
            ]);
        }

        // ──────────────────────────────────────────────
        // 13. TASK SEARCH INDEX
        // ──────────────────────────────────────────────
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

        $userEmails = $allUsers->slice(1)->map(fn ($u) => $u->email)->implode(', ');
        $this->command?->info('Mock data seeded successfully!');
        $this->command?->info('  Users: admin@'.$slug.'.test, '.$userEmails.' (password123)');
        $this->command?->info('  '.$tasks->count().' tasks created');
        $this->command?->info('  '.count($notifSpecs).' notifications created');
    }

    private function parseDateStr(string $str, Carbon $now, Carbon $createdAt, ?Carbon $launchedAt): string
    {
        $map = [
            'now' => $now,
            'now-10d' => (clone $now)->subDays(10),
            'now-8d' => (clone $now)->subDays(8),
            'now-6d' => (clone $now)->subDays(6),
            'now-5d' => (clone $now)->subDays(5),
            'now-4d' => (clone $now)->subDays(4),
            'now-3d' => (clone $now)->subDays(3),
            'now-2d' => (clone $now)->subDays(2),
            'now-1d' => (clone $now)->subDays(1),
            'now-11d' => (clone $now)->subDays(11),
            'now-12d' => (clone $now)->subDays(12),
            'now-14d' => (clone $now)->subDays(14),
            'now-15d' => (clone $now)->subDays(15),
            'now-16d' => (clone $now)->subDays(16),
            'now-18d' => (clone $now)->subDays(18),
            'now-20d' => (clone $now)->subDays(20),
            'now-25d' => (clone $now)->subDays(25),
            'now-30d' => (clone $now)->subDays(30),
            'now-35d' => (clone $now)->subDays(35),
            'now-38d' => (clone $now)->subDays(38),
            'now-40d' => (clone $now)->subDays(40),
            'now-45d' => (clone $now)->subDays(45),
            'now-50d' => (clone $now)->subDays(50),
            'now-60d' => (clone $now)->subDays(60),
            'now-75d' => (clone $now)->subDays(75),
            'now-90d' => (clone $now)->subDays(90),
        ];

        return isset($map[$str]) ? $map[$str]->format('Y-m-d H:i:s') : $str;
    }
}
