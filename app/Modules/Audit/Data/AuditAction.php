<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

enum AuditAction: string
{
    case WORKSPACE_CREATED = 'workspace.created';
    case WORKSPACE_RENAMED = 'workspace.renamed';
    case WORKSPACE_DELETED = 'workspace.deleted';

    case MEMBER_INVITED = 'member.invited';
    case MEMBER_REMOVED = 'member.removed';
    case MEMBER_ROLE_CHANGED = 'member.role_changed';

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
    case BOARD_COLUMN_CREATED = 'board_column.created';
    case BOARD_COLUMN_DELETED = 'board_column.deleted';
    case BOARD_COLUMN_REORDERED = 'board_column.reordered';

    case MEETING_SCHEDULED = 'meeting.scheduled';
    case MEETING_UPDATED = 'meeting.updated';
    case MEETING_CANCELLED = 'meeting.cancelled';

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return ['Workspace', 'Team', 'Projects', 'Tasks', 'Meetings'];
    }

    public function category(): string
    {
        return match (true) {
            str_starts_with($this->value, 'workspace.') => 'Workspace',
            str_starts_with($this->value, 'member.') => 'Team',
            str_starts_with($this->value, 'project.') => 'Projects',
            str_starts_with($this->value, 'task.'), str_starts_with($this->value, 'board_column.') => 'Tasks',
            str_starts_with($this->value, 'meeting.') => 'Meetings',
        };
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
            self::MEMBER_INVITED => 'Member invited',
            self::MEMBER_REMOVED => 'Member removed',
            self::MEMBER_ROLE_CHANGED => 'Workspace role changed',
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
            self::BOARD_COLUMN_CREATED => 'Board column created',
            self::BOARD_COLUMN_DELETED => 'Board column deleted',
            self::BOARD_COLUMN_REORDERED => 'Board columns reordered',
            self::MEETING_SCHEDULED => 'Meeting scheduled',
            self::MEETING_UPDATED => 'Meeting updated',
            self::MEETING_CANCELLED => 'Meeting cancelled',
        };
    }
}
