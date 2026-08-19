<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

/**
 * Capabilities a client (UserRole::CLIENT) can be granted inside the projects
 * they are a member of. They are stored alongside workspace permissions in the
 * WorkspaceRole permissions JSON, and only ever apply to client members.
 */
enum ClientPermission: string
{
    case BoardView = 'client.board.view';
    case TasksComment = 'client.tasks.comment';
    case TasksRequest = 'client.tasks.request';
    case TasksClose = 'client.tasks.close';
    case MeetingsView = 'client.meetings.view';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::BoardView => 'View project board & sprints',
            self::TasksComment => 'Comment on tasks',
            self::TasksRequest => 'Request tasks',
            self::TasksClose => 'Close tasks',
            self::MeetingsView => 'View meetings',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BoardView => 'See the tasks, columns and sprints of their projects.',
            self::TasksComment => 'Leave comments on tasks in their projects.',
            self::TasksRequest => 'Create tasks, which land in the default column for the team to triage.',
            self::TasksClose => 'Move a task into a done column. They can never move it back.',
            self::MeetingsView => 'See the meetings scheduled on their projects.',
        };
    }

    /**
     * What a client gets when no client role has been attached to them yet: read-only.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::BoardView->value => true,
            self::TasksComment->value => false,
            self::TasksRequest->value => false,
            self::TasksClose->value => false,
            self::MeetingsView->value => true,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $permissions
     * @return array<string, bool>
     */
    public static function normalise(?array $permissions): array
    {
        $normalised = [];

        foreach (self::cases() as $permission) {
            $normalised[$permission->value] = ($permissions[$permission->value] ?? false) === true;
        }

        return $normalised;
    }
}
