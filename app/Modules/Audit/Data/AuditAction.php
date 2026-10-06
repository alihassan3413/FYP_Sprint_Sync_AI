<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

enum AuditAction: string
{
    case WORKSPACE_CREATED = 'workspace.created';
    case WORKSPACE_RENAMED = 'workspace.renamed';
    case WORKSPACE_DELETED = 'workspace.deleted';
    case WORKSPACE_TIMEZONE_CHANGED = 'workspace.timezone_changed';

    case MEMBER_INVITED = 'member.invited';
    case MEMBER_REMOVED = 'member.removed';
    case MEMBER_ROLE_CHANGED = 'member.role_changed';

    case INVITE_LINK_GENERATED = 'invite_link.generated';
    case INVITE_LINK_REVOKED = 'invite_link.revoked';
    case INVITE_LINK_JOINED = 'invite_link.joined';

    case PROJECT_CREATED = 'project.created';
    case PROJECT_UPDATED = 'project.updated';
    case PROJECT_DELETED = 'project.deleted';
    case PROJECT_MEMBER_ADDED = 'project.member_added';
    case PROJECT_MEMBER_REMOVED = 'project.member_removed';
    case PROJECT_MEMBER_ROLE_CHANGED = 'project.member_role_changed';

    case TASK_CREATED = 'task.created';
    case TASK_UPDATED = 'task.updated';
    case TASK_DELETED = 'task.deleted';
    case TASK_MOVED = 'task.moved';
    case TASK_ASSIGNED = 'task.assigned';

    case SPRINT_CREATED = 'sprint.created';
    case SPRINT_UPDATED = 'sprint.updated';
    case SPRINT_DELETED = 'sprint.deleted';
    case SPRINT_STARTED = 'sprint.started';
    case SPRINT_COMPLETED = 'sprint.completed';

    case BOARD_COLUMN_CREATED = 'board_column.created';
    case BOARD_COLUMN_DELETED = 'board_column.deleted';
    case BOARD_COLUMN_REORDERED = 'board_column.reordered';

    case MEETING_SCHEDULED = 'meeting.scheduled';
    case MEETING_UPDATED = 'meeting.updated';
    case MEETING_CANCELLED = 'meeting.cancelled';

    case CLIENT_CREATED = 'client.created';
    case CLIENT_UPDATED = 'client.updated';
    case CLIENT_ARCHIVED = 'client.archived';
    case CLIENT_RESTORED = 'client.restored';
    case BILLING_PLAN_CREATED = 'billing_plan.created';
    case BILLING_PLAN_UPDATED = 'billing_plan.updated';
    case BILLING_PLAN_PAUSED = 'billing_plan.paused';
    case BILLING_PLAN_RESUMED = 'billing_plan.resumed';
    case INVOICE_GENERATED = 'invoice.generated';
    case INVOICE_UPDATED = 'invoice.updated';
    case INVOICE_READY = 'invoice.ready';
    case INVOICE_APPROVED = 'invoice.approved';
    case INVOICE_REAPPROVED = 'invoice.reapproved';
    case INVOICE_SEND_CANCELLED = 'invoice.send_cancelled';
    case INVOICE_ISSUED = 'invoice.issued';
    case INVOICING_UPDATED = 'invoicing.updated';
    case PAYMENT_RECORDED = 'payment.recorded';
    case PAYMENT_VOIDED = 'payment.voided';
    case MAIL_SETTINGS_SAVED = 'mail.settings_saved';
    case MAIL_TEST_SUCCEEDED = 'mail.test_succeeded';
    case MAIL_TEST_FAILED = 'mail.test_failed';
    case INVOICE_DELIVERY_SENT = 'invoice.delivery_sent';
    case INVOICE_DELIVERY_FAILED = 'invoice.delivery_failed';
    case INVOICE_DELIVERY_UNCERTAIN = 'invoice.delivery_uncertain';
    case INVOICE_DELIVERY_RETRIED = 'invoice.delivery_retried';
    case INVOICE_AUTOMATION_HELD = 'invoice.automation_held';
    case TIME_TIMER_STARTED = 'time.timer_started';
    case TIME_TIMER_STOPPED = 'time.timer_stopped';
    case TIME_MANUAL_ADDED = 'time.manual_added';
    case TIME_ADJUSTMENT_ADDED = 'time.adjustment_added';
    case TIME_HISTORICAL_ADDED = 'time.historical_added';
    case TIME_ENTRY_VOIDED = 'time.entry_voided';
    case TIME_POLICY_CHANGED = 'time.policy_changed';
    case TIME_SCHEDULE_CHANGED = 'time.schedule_changed';
    case TIME_HOLIDAY_ADDED = 'time.holiday_added';
    case TIME_HOLIDAY_REMOVED = 'time.holiday_removed';
    case TIME_LEAVE_ADDED = 'time.leave_added';
    case TIME_LEAVE_REMOVED = 'time.leave_removed';
    case TIME_IDLE_KEPT = 'time.idle_kept';
    case TIME_ACTIVITY_FLAGGED = 'time.activity_flagged';
    case TIME_REVIEW_RESOLVED = 'time.review_resolved';
    case INVOICE_HOURS_REFRESHED = 'invoice.hours_refreshed';
    case INVOICE_HOURS_OVERRIDDEN = 'invoice.hours_overridden';
    case EXPENSE_RECORDED = 'expense.recorded';
    case EXPENSE_VOIDED = 'expense.voided';
    case EXPENSE_RECURRING_SAVED = 'expense.recurring_saved';
    case EXPENSE_RECURRING_STOPPED = 'expense.recurring_stopped';
    case FX_RATE_ENTERED = 'fx.rate_entered';
    case FINANCE_REPORTING_CURRENCY_CHANGED = 'finance.reporting_currency_changed';

    case PERSON_CREATED = 'person.created';
    case PERSON_UPDATED = 'person.updated';
    case PERSON_USER_LINKED = 'person.user_linked';
    case PERSON_USER_UNLINKED = 'person.user_unlinked';
    case DEPARTMENT_CREATED = 'department.created';

    case ACCOUNT_PROFILE_UPDATED = 'account.profile_updated';
    case ACCOUNT_PASSWORD_CHANGED = 'account.password_changed';
    case ACCOUNT_AVATAR_UPDATED = 'account.avatar_updated';
    case ACCOUNT_AVATAR_REMOVED = 'account.avatar_removed';
    case ACCOUNT_DELETED = 'account.deleted';
    case ACCOUNT_DESKTOP_SIGNED_IN = 'account.desktop_signed_in';
    case ACCOUNT_DESKTOP_SIGNED_OUT = 'account.desktop_signed_out';

    /**
     * Billing entries name clients and amounts, so they are only shown to
     * people with finance access (see SearchAuditLogAction).
     */
    public const BILLING_CATEGORY = 'Billing';

    /**
     * People entries name staff and their logins, so they are only shown to
     * people with people access.
     */
    public const PEOPLE_CATEGORY = 'Team profiles';

    /** Who worked how long: owner-only like Team profiles. */
    public const TIME_CATEGORY = 'Time';

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return ['Workspace', 'Team', 'Projects', 'Tasks', 'Meetings', self::BILLING_CATEGORY, self::PEOPLE_CATEGORY, self::TIME_CATEGORY];
    }

    public function category(): string
    {
        return match (true) {
            str_starts_with($this->value, 'workspace.') => 'Workspace',
            str_starts_with($this->value, 'member.'), str_starts_with($this->value, 'invite_link.') => 'Team',
            str_starts_with($this->value, 'project.'), str_starts_with($this->value, 'sprint.') => 'Projects',
            str_starts_with($this->value, 'task.'), str_starts_with($this->value, 'board_column.') => 'Tasks',
            str_starts_with($this->value, 'meeting.') => 'Meetings',
            str_starts_with($this->value, 'client.'), str_starts_with($this->value, 'billing_plan.'), str_starts_with($this->value, 'invoice.'), str_starts_with($this->value, 'invoicing.'), str_starts_with($this->value, 'payment.'), str_starts_with($this->value, 'mail.'), str_starts_with($this->value, 'expense.'), str_starts_with($this->value, 'fx.'), str_starts_with($this->value, 'finance.') => self::BILLING_CATEGORY,
            str_starts_with($this->value, 'person.'), str_starts_with($this->value, 'department.') => self::PEOPLE_CATEGORY,
            str_starts_with($this->value, 'time.') => self::TIME_CATEGORY,
            str_starts_with($this->value, 'account.') => 'Account',
        };
    }

    public function isGlobal(): bool
    {
        return str_starts_with($this->value, 'account.');
    }

    /**
     * @return array<int, string>
     */
    public static function valuesForCategory(string $category): array
    {
        return array_values(array_map(
            fn (self $action) => $action->value,
            array_filter(self::cases(), fn (self $action) => $action->category() === $category),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::WORKSPACE_CREATED => 'Workspace created',
            self::WORKSPACE_RENAMED => 'Workspace renamed',
            self::WORKSPACE_DELETED => 'Workspace deleted',
            self::WORKSPACE_TIMEZONE_CHANGED => 'Workspace timezone changed',
            self::MEMBER_INVITED => 'Member invited',
            self::MEMBER_REMOVED => 'Member removed',
            self::MEMBER_ROLE_CHANGED => 'Workspace role changed',
            self::INVITE_LINK_GENERATED => 'Invite link generated',
            self::INVITE_LINK_REVOKED => 'Invite link revoked',
            self::INVITE_LINK_JOINED => 'Joined via invite link',
            self::PROJECT_CREATED => 'Project created',
            self::PROJECT_UPDATED => 'Project updated',
            self::PROJECT_DELETED => 'Project deleted',
            self::PROJECT_MEMBER_ADDED => 'Project member added',
            self::PROJECT_MEMBER_REMOVED => 'Project member removed',
            self::PROJECT_MEMBER_ROLE_CHANGED => 'Project role changed',
            self::TASK_CREATED => 'Task created',
            self::TASK_UPDATED => 'Task updated',
            self::TASK_DELETED => 'Task deleted',
            self::TASK_MOVED => 'Task moved',
            self::TASK_ASSIGNED => 'Task assigned',
            self::SPRINT_CREATED => 'Sprint created',
            self::SPRINT_UPDATED => 'Sprint updated',
            self::SPRINT_DELETED => 'Sprint deleted',
            self::SPRINT_STARTED => 'Sprint started',
            self::SPRINT_COMPLETED => 'Sprint completed',
            self::BOARD_COLUMN_CREATED => 'Board column created',
            self::BOARD_COLUMN_DELETED => 'Board column deleted',
            self::BOARD_COLUMN_REORDERED => 'Board columns reordered',
            self::MEETING_SCHEDULED => 'Meeting scheduled',
            self::MEETING_UPDATED => 'Meeting updated',
            self::MEETING_CANCELLED => 'Meeting cancelled',
            self::CLIENT_CREATED => 'Client added',
            self::CLIENT_UPDATED => 'Client updated',
            self::CLIENT_ARCHIVED => 'Client archived',
            self::CLIENT_RESTORED => 'Client restored',
            self::BILLING_PLAN_CREATED => 'Recurring invoice created',
            self::BILLING_PLAN_UPDATED => 'Recurring invoice updated',
            self::BILLING_PLAN_PAUSED => 'Recurring invoice paused',
            self::BILLING_PLAN_RESUMED => 'Recurring invoice resumed',
            self::INVOICE_GENERATED => 'Invoice prepared',
            self::INVOICE_UPDATED => 'Invoice edited',
            self::INVOICE_READY => 'Invoice ready to review',
            self::INVOICE_APPROVED => 'Invoice approved',
            self::INVOICE_REAPPROVED => 'Invoice approved again',
            self::INVOICE_SEND_CANCELLED => 'Invoice sending cancelled',
            self::INVOICE_ISSUED => 'Invoice issued',
            self::INVOICING_UPDATED => 'Invoicing details updated',
            self::PAYMENT_RECORDED => 'Payment recorded',
            self::PAYMENT_VOIDED => 'Payment voided',
            self::MAIL_SETTINGS_SAVED => 'Email delivery settings saved',
            self::MAIL_TEST_SUCCEEDED => 'Test email sent',
            self::MAIL_TEST_FAILED => 'Test email failed',
            self::INVOICE_DELIVERY_SENT => 'Invoice emailed',
            self::INVOICE_DELIVERY_FAILED => 'Invoice email failed',
            self::INVOICE_DELIVERY_UNCERTAIN => 'Invoice email needs checking',
            self::INVOICE_DELIVERY_RETRIED => 'Invoice email retried',
            self::INVOICE_AUTOMATION_HELD => 'Automatic sending paused for review',
            self::TIME_TIMER_STARTED => 'Timer started',
            self::TIME_TIMER_STOPPED => 'Timer stopped',
            self::TIME_MANUAL_ADDED => 'Time added',
            self::TIME_ADJUSTMENT_ADDED => 'Time adjusted',
            self::TIME_HISTORICAL_ADDED => 'Previous hours added',
            self::TIME_ENTRY_VOIDED => 'Time entry voided',
            self::TIME_POLICY_CHANGED => 'Time policy changed',
            self::TIME_SCHEDULE_CHANGED => 'Work schedule changed',
            self::TIME_HOLIDAY_ADDED => 'Holiday added',
            self::TIME_HOLIDAY_REMOVED => 'Holiday removed',
            self::TIME_LEAVE_ADDED => 'Leave added',
            self::TIME_LEAVE_REMOVED => 'Leave removed',
            self::TIME_IDLE_KEPT => 'Idle time kept as work',
            self::TIME_ACTIVITY_FLAGGED => 'Activity flagged for review',
            self::TIME_REVIEW_RESOLVED => 'Time review resolved',
            self::INVOICE_HOURS_REFRESHED => 'Invoice recorded hours refreshed',
            self::INVOICE_HOURS_OVERRIDDEN => 'Billed hours changed',
            self::EXPENSE_RECORDED => 'Expense recorded',
            self::EXPENSE_VOIDED => 'Expense voided',
            self::EXPENSE_RECURRING_SAVED => 'Recurring expense saved',
            self::EXPENSE_RECURRING_STOPPED => 'Recurring expense stopped',
            self::FX_RATE_ENTERED => 'Exchange rate entered',
            self::FINANCE_REPORTING_CURRENCY_CHANGED => 'Reporting currency changed',
            self::PERSON_CREATED => 'Person added',
            self::PERSON_UPDATED => 'Person updated',
            self::PERSON_USER_LINKED => 'Person linked to user',
            self::PERSON_USER_UNLINKED => 'Person unlinked from user',
            self::DEPARTMENT_CREATED => 'Department created',
            self::ACCOUNT_PROFILE_UPDATED => 'Profile updated',
            self::ACCOUNT_PASSWORD_CHANGED => 'Password changed',
            self::ACCOUNT_DESKTOP_SIGNED_IN => 'Signed in to SprintSync Desktop',
            self::ACCOUNT_DESKTOP_SIGNED_OUT => 'Signed out of SprintSync Desktop',
            self::ACCOUNT_AVATAR_UPDATED => 'Profile picture updated',
            self::ACCOUNT_AVATAR_REMOVED => 'Profile picture removed',
            self::ACCOUNT_DELETED => 'Account deleted',
        };
    }
}
