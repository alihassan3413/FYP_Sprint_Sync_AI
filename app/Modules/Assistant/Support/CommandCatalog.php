<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Data\AssistantCommandData;

final class CommandCatalog
{
    /**
     * @var array<string, array{label: string, description: string, category: string, keywords: array<int, string>, template: string}>
     */
    private const ENTRIES = [
        'get_guide' => [
            'label' => 'Teach me how to use SprintSync',
            'description' => 'A guided walkthrough, or the answer to one "how do I" question.',
            'category' => 'Learn',
            'keywords' => ['help', 'guide', 'teach', 'train', 'learn', 'how', 'how do i', 'tutorial', 'walkthrough', 'onboard', 'start', 'new', 'explain', 'show me', 'what can i do', 'documentation', 'manual', 'lost', 'confused'],
            'template' => 'Teach me how to use SprintSync',
        ],
        'create_workspace' => [
            'label' => 'Create a workspace',
            'description' => 'Start a new workspace for a separate team or client.',
            'category' => 'Workspace',
            'keywords' => ['workspace', 'create', 'new', 'add', 'start', 'set up', 'team', 'organisation', 'organization'],
            'template' => 'Create a new workspace called ',
        ],
        'invite_user' => [
            'label' => 'Invite someone',
            'description' => 'Send a workspace invitation by email.',
            'category' => 'Workspace',
            'keywords' => ['invite', 'add', 'member', 'user', 'people', 'person', 'email', 'join', 'teammate', 'colleague', 'hire'],
            'template' => 'Invite ',
        ],
        'get_workspace_info' => [
            'label' => 'Who is in this workspace',
            'description' => 'List members, pending invitations and roles.',
            'category' => 'Workspace',
            'keywords' => ['members', 'team', 'who', 'roles', 'people', 'invitations', 'pending', 'workspace', 'list', 'show', 'staff'],
            'template' => 'Who is in this workspace?',
        ],
        'add_project_member' => [
            'label' => 'Add someone to a project',
            'description' => 'Give an existing workspace member access to a project.',
            'category' => 'Projects',
            'keywords' => ['project', 'member', 'add', 'access', 'assign', 'people', 'team', 'grant', 'permission'],
            'template' => 'Add  to the project ',
        ],
        'list_projects' => [
            'label' => 'List projects',
            'description' => 'See the projects you have access to.',
            'category' => 'Projects',
            'keywords' => ['projects', 'list', 'show', 'all', 'my', 'what', 'see', 'browse'],
            'template' => 'What projects do I have?',
        ],
        'create_project' => [
            'label' => 'Create a project',
            'description' => 'Start a new project with a board ready to use.',
            'category' => 'Projects',
            'keywords' => ['project', 'create', 'new', 'add', 'start', 'make', 'set up', 'board'],
            'template' => 'Create a project called ',
        ],
        'find_tasks' => [
            'label' => 'Find tasks',
            'description' => 'Search tasks by words, project, assignee or status.',
            'category' => 'Tasks',
            'keywords' => ['task', 'find', 'search', 'look', 'show', 'list', 'my', 'open', 'overdue', 'assigned', 'todo', 'work'],
            'template' => 'Find tasks about ',
        ],
        'create_task' => [
            'label' => 'Create a task',
            'description' => 'Add a task, optionally assigned and with a due date.',
            'category' => 'Tasks',
            'keywords' => ['task', 'create', 'new', 'add', 'make', 'todo', 'ticket', 'issue', 'work', 'item'],
            'template' => 'Create a task to ',
        ],
        'update_task' => [
            'label' => 'Update a task',
            'description' => 'Reassign, move, rename, or set a due date or sprint.',
            'category' => 'Tasks',
            'keywords' => ['task', 'update', 'change', 'edit', 'move', 'assign', 'reassign', 'rename', 'due', 'date', 'done', 'complete', 'status', 'column'],
            'template' => 'Update the task ',
        ],
        'comment_on_task' => [
            'label' => 'Comment on a task',
            'description' => 'Post a comment under your own name.',
            'category' => 'Tasks',
            'keywords' => ['comment', 'task', 'reply', 'note', 'say', 'post', 'message', 'update', 'discuss', 'feedback'],
            'template' => 'Comment on the task ',
        ],
        'delete_task' => [
            'label' => 'Delete a task',
            'description' => 'Permanently remove a task and its comments.',
            'category' => 'Tasks',
            'keywords' => ['task', 'delete', 'remove', 'destroy', 'get rid', 'drop', 'erase'],
            'template' => 'Delete the task ',
        ],
        'manage_sprint' => [
            'label' => 'Plan, start or close a sprint',
            'description' => 'Create a sprint, start it, or complete it.',
            'category' => 'Sprints',
            'keywords' => ['sprint', 'plan', 'start', 'begin', 'close', 'complete', 'finish', 'end', 'create', 'new', 'iteration'],
            'template' => 'Plan a sprint for ',
        ],
        'get_sprint_report' => [
            'label' => 'Sprint status',
            'description' => 'How the sprint is going, what is left, and the burndown.',
            'category' => 'Sprints',
            'keywords' => ['sprint', 'status', 'report', 'progress', 'burndown', 'velocity', 'track', 'standup', 'left', 'remaining', 'health'],
            'template' => 'How is the current sprint going?',
        ],
        'schedule_meeting' => [
            'label' => 'Schedule a meeting',
            'description' => 'Book a meeting and invite people by email.',
            'category' => 'Meetings',
            'keywords' => ['meeting', 'schedule', 'book', 'arrange', 'set up', 'call', 'standup', 'retro', 'invite', 'calendar', 'new'],
            'template' => 'Schedule a meeting on ',
        ],
        'list_meetings' => [
            'label' => 'List meetings',
            'description' => 'See upcoming or past meetings.',
            'category' => 'Meetings',
            'keywords' => ['meeting', 'meetings', 'list', 'show', 'upcoming', 'past', 'next', 'when', 'calendar', 'schedule'],
            'template' => 'What meetings are coming up?',
        ],
        'edit_meeting' => [
            'label' => 'Change a meeting',
            'description' => 'Move it, rename it, or change who is invited.',
            'category' => 'Meetings',
            'keywords' => ['meeting', 'edit', 'change', 'update', 'move', 'reschedule', 'rename', 'invite', 'time'],
            'template' => 'Reschedule the meeting ',
        ],
        'cancel_meeting' => [
            'label' => 'Cancel a meeting',
            'description' => 'Call a meeting off for everyone.',
            'category' => 'Meetings',
            'keywords' => ['meeting', 'cancel', 'call off', 'delete', 'remove', 'drop', 'scrap'],
            'template' => 'Cancel the meeting ',
        ],
        'get_analytics' => [
            'label' => 'How are we doing',
            'description' => 'Completion rate, overdue work, and progress by project.',
            'category' => 'Insights',
            'keywords' => ['analytics', 'progress', 'how', 'doing', 'stats', 'report', 'overdue', 'completion', 'rate', 'behind', 'performance', 'metrics', 'overview', 'summary'],
            'template' => 'How are we doing?',
        ],
    ];

    /**
     * @param  array<int, AssistantTool>  $tools
     * @return array<int, AssistantCommandData>
     */
    public function forTools(array $tools): array
    {
        $commands = [];

        foreach ($tools as $tool) {
            $entry = self::ENTRIES[$tool->name()] ?? null;

            if ($entry === null) {
                continue;
            }

            $commands[] = new AssistantCommandData(
                name: $tool->name(),
                label: $entry['label'],
                description: $entry['description'],
                category: $entry['category'],
                keywords: $entry['keywords'],
                template: $entry['template'],
                requires_confirmation: $tool->requiresConfirmation(),
            );
        }

        return $commands;
    }

    /**
     * @return array<int, string>
     */
    public static function describedToolNames(): array
    {
        return array_keys(self::ENTRIES);
    }
}
