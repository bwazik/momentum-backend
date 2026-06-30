<?php

namespace App\Modules\Audit\Enums;

enum AuditEntityType: int
{
    case Task = 1;
    case StageInstance = 2;
    case SubStageInstance = 3;
    case User = 4;
    case Position = 5;
    case Department = 6;
    case Blueprint = 7;
    case Document = 8;
    case Escalation = 9;
    case SlaTimerInstance = 10;
    case FollowUpAction = 11;
    case Comment = 12;
    case HelpArticle = 13;
    case OnboardingJourney = 14;
    case Tenant = 15;
    case PlatformAdmin = 16;
    case Impersonation = 17;
    case WorkingCalendar = 18;
    case PublicHoliday = 19;
    case AuthorityGrade = 20;
    case PositionAssignment = 21;
    case Delegation = 22;
    case MonitoringScopeGrant = 23;
    case AuditGrant = 24;
    case CapabilityGrant = 25;
    case StageType = 26;
    case SlaPolicy = 27;
    case BlueprintCategory = 28;
    case BlueprintStage = 29;
    case BlueprintSubStage = 30;
    case BlueprintTransition = 31;

    public function name(): string
    {
        return match ($this) {
            self::Task => 'task',
            self::StageInstance => 'stage_instance',
            self::SubStageInstance => 'sub_stage_instance',
            self::User => 'user',
            self::Position => 'position',
            self::Department => 'department',
            self::Blueprint => 'blueprint',
            self::Document => 'document',
            self::Escalation => 'escalation',
            self::SlaTimerInstance => 'sla_timer_instance',
            self::FollowUpAction => 'follow_up_action',
            self::Comment => 'comment',
            self::HelpArticle => 'help_article',
            self::OnboardingJourney => 'onboarding_journey',
            self::Tenant => 'tenant',
            self::PlatformAdmin => 'platform_admin',
            self::Impersonation => 'impersonation',
            self::WorkingCalendar => 'working_calendar',
            self::PublicHoliday => 'public_holiday',
            self::AuthorityGrade => 'authority_grade',
            self::PositionAssignment => 'position_assignment',
            self::Delegation => 'delegation',
            self::MonitoringScopeGrant => 'monitoring_scope_grant',
            self::AuditGrant => 'audit_grant',
            self::CapabilityGrant => 'capability_grant',
            self::StageType => 'stage_type',
            self::SlaPolicy => 'sla_policy',
            self::BlueprintCategory => 'blueprint_category',
            self::BlueprintStage => 'blueprint_stage',
            self::BlueprintSubStage => 'blueprint_sub_stage',
            self::BlueprintTransition => 'blueprint_transition',
        };
    }
}
