<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

/**
 * The training curriculum: what a person needs to learn to go from knowing
 * nothing about SprintSync to running a team in it.
 *
 * Lessons are ordered. Each one is gated to an audience so nobody is taught a
 * feature their role will refuse them, and each carries example phrasings so
 * the reader can practise the thing immediately by asking for it.
 */
final class GuideLibrary
{
    public const AUDIENCE_EVERYONE = 'everyone';

    public const AUDIENCE_MEMBER = 'member';

    public const AUDIENCE_CLIENT = 'client';

    public const AUDIENCE_PROJECT_MANAGER = 'project_manager';

    public const AUDIENCE_INVITE = 'invite';

    public const AUDIENCE_CREATE_PROJECTS = 'create_projects';

    public const AUDIENCE_MANAGE_ROLES = 'manage_roles';

    public const STAGE_START = 'Getting started';

    public const STAGE_DAILY = 'Daily work';

    public const STAGE_TEAM = 'Running a team';

    public const STAGE_ADMIN = 'Administration';

    /**
     * @var array<string, array{title: string, stage: string, audience: string, summary: string, steps: array<int, string>, say: array<int, string>, where: string|null, keywords: array<int, string>}>
     */
    private const LESSONS = [
        'orientation' => [
            'title' => 'How it all fits together',
            'stage' => self::STAGE_START,
            'audience' => self::AUDIENCE_EVERYONE,
            'summary' => 'The five things everything else is built out of: workspaces, projects, boards, sprints and meetings.',
            'steps' => [
                'A workspace is one team, company or client kept separate from the others. You can belong to several and switch between them.',
                'Inside a workspace you create projects. Each project has its own board, its own sprints, its own meetings and its own member list.',
                'Work items are tasks. A task lives in a board column, can have an assignee and a due date, and carries its own comment thread.',
                'A sprint is a fixed date range of committed work inside one project. Tasks are grouped into it so progress can be measured.',
                'Workspace membership and project membership are separate. Being in the workspace does not put you on a project — someone has to add you.',
                'Everything you can do by clicking, you can also do by asking the assistant in plain language.',
            ],
            'say' => ['What projects do I have?', 'Who is in this workspace?'],
            'where' => null,
            'keywords' => ['overview', 'basics', 'start', 'concepts', 'structure', 'what is', 'how does', 'fit', 'together', 'introduction', 'new', 'beginner'],
        ],
        'assistant-basics' => [
            'title' => 'Talking to the assistant',
            'stage' => self::STAGE_START,
            'audience' => self::AUDIENCE_EVERYONE,
            'summary' => 'How to drive the whole product by typing or speaking instead of clicking.',
            'steps' => [
                'Open the assistant from the button in the bottom corner of any page. It follows you around the app.',
                'Type in plain language. "Create a task to fix the checkout bug, assign it to Sara, due Friday" is a complete instruction — no syntax to learn.',
                'Press / in the input to search every command you are allowed to use. Search matches natural phrasing, so "want to start a new sprint" finds the sprint command. Picking one fills the box; it does not run anything.',
                'Tap the microphone to speak instead of typing, and the reply can be read back aloud.',
                'Anything that creates, changes or deletes shows you a confirmation card first. Nothing is written until you press Confirm.',
                'The assistant is only handed the tools your role allows. If you are not permitted to do something, there is no tool there for it to call.',
                'It works on what it can see. If you ask about a project you are not a member of, it will tell you it has no access rather than guess.',
            ],
            'say' => ['What can you do?', 'Teach me how to use SprintSync'],
            'where' => 'The assistant button, bottom corner of any page',
            'keywords' => ['assistant', 'ai', 'chat', 'talk', 'voice', 'speak', 'microphone', 'command', 'slash', 'palette', 'confirm', 'how to use', 'agent'],
        ],
        'workspaces' => [
            'title' => 'Workspaces and switching between them',
            'stage' => self::STAGE_START,
            'audience' => self::AUDIENCE_EVERYONE,
            'summary' => 'Creating a workspace, and moving between the ones you belong to.',
            'steps' => [
                'Create a separate workspace for each team, company or client you want kept apart. Nothing leaks between them.',
                'Whoever creates a workspace is its owner. The owner cannot be removed or demoted by anyone.',
                'Switch workspaces from the workspace menu at the top of the sidebar. Every page you see is scoped to the workspace you are currently in.',
                'You can be an admin in one workspace and a plain member — or a client — in another. Your role is per workspace, not global.',
            ],
            'say' => ['Create a workspace called Acme', 'What workspace am I in?'],
            'where' => 'Workspace menu, top of the sidebar',
            'keywords' => ['workspace', 'create', 'switch', 'change', 'new', 'organisation', 'organization', 'company', 'tenant', 'separate'],
        ],
        'client-basics' => [
            'title' => 'What you can do here as a client',
            'stage' => self::STAGE_START,
            'audience' => self::AUDIENCE_CLIENT,
            'summary' => 'You are an external guest on specific projects. Here is exactly what that means.',
            'steps' => [
                'You see only the projects you have been added to. Other projects in this workspace are invisible to you, and so is the team roster.',
                'What you can do inside those projects is set by the team: viewing the board and sprints, commenting on tasks, requesting tasks, closing tasks, and viewing meetings.',
                'If you can request tasks, what you create lands in the first column for the team to triage. It is a request, not an assignment.',
                'If you can close tasks, you can move work into a done column — but never back out of one.',
                'Ask the assistant what you have been granted at any time, and it will tell you precisely.',
            ],
            'say' => ['What am I allowed to do here?', 'Show me my projects'],
            'where' => null,
            'keywords' => ['client', 'guest', 'external', 'customer', 'permission', 'allowed', 'access', 'what can i do', 'limits'],
        ],
        'invite-team' => [
            'title' => 'Inviting people and choosing their role',
            'stage' => self::STAGE_START,
            'audience' => self::AUDIENCE_INVITE,
            'summary' => 'Getting colleagues and clients into the workspace, at the right level.',
            'steps' => [
                'Invite by email from the Team page, or just ask the assistant to do it.',
                'Pick a base role. Admin manages members, roles, projects and workspace settings. Member works inside the projects they are added to. Client is an external guest.',
                'You can attach a custom role on top of the base role to grant specific permissions. Create those in workspace settings first.',
                'You can also share a join link instead of emailing individual invitations.',
                'An invitation only joins someone to the workspace. You still have to add them to each project before they can be assigned work.',
            ],
            'say' => ['Invite sara@acme.com as a member', 'Show me the pending invitations'],
            'where' => 'Team',
            'keywords' => ['invite', 'add', 'member', 'people', 'colleague', 'email', 'join', 'link', 'role', 'hire', 'team'],
        ],
        'projects' => [
            'title' => 'Creating projects and adding people to them',
            'stage' => self::STAGE_DAILY,
            'audience' => self::AUDIENCE_CREATE_PROJECTS,
            'summary' => 'Projects are the container for a board, its sprints and its meetings.',
            'steps' => [
                'Create a project with just a name. Its board and default columns are set up for you — there is nothing to configure first.',
                'Add workspace members to the project. Until you do, they cannot see it or be assigned anything in it.',
                'Each project member is either a manager or a member. Managers run sprints, schedule meetings, edit any task and manage the board columns.',
                'Add a client to a project the same way. What they can do once inside is governed by their client role, not by the project role.',
            ],
            'say' => ['Create a project called Website Revamp', 'Add Sara to the Website Revamp project'],
            'where' => 'Projects',
            'keywords' => ['project', 'create', 'new', 'add', 'member', 'manager', 'setup', 'start'],
        ],
        'board' => [
            'title' => 'Reading and working the board',
            'stage' => self::STAGE_DAILY,
            'audience' => self::AUDIENCE_EVERYONE,
            'summary' => 'Where the work actually lives, and how to move it.',
            'steps' => [
                'The board shows the project\'s tasks as cards in lists. Drag a card to move it between lists.',
                'Click a card to open its detail: description, assignee, due date, sprint, and its comment thread.',
                'Comment on a task to keep the discussion attached to the work instead of scattered across email.',
                'The board updates live. When a teammate moves a card, it moves on your screen without a refresh.',
                'Everyone assigned or involved gets notified when a task is assigned, moved or commented on.',
            ],
            'say' => ['Find my open tasks', 'Comment on the checkout bug task: still failing on Safari'],
            'where' => 'Projects → open a project',
            'keywords' => ['board', 'kanban', 'list', 'lists', 'column', 'card', 'drag', 'move', 'comment', 'detail', 'live', 'realtime'],
        ],
        'tasks' => [
            'title' => 'Creating and changing tasks',
            'stage' => self::STAGE_DAILY,
            'audience' => self::AUDIENCE_MEMBER,
            'summary' => 'The full lifecycle of a work item, by hand or by asking.',
            'steps' => [
                'Create a task with a title. If you are only on one project it goes there; otherwise name the project and it will be matched.',
                'Assign it by first name — the name is matched against the people on that project, so you do not need their email address.',
                'Give it a due date in plain language. "Friday" and "next Tuesday" are resolved for you.',
                'To change an existing task, describe it rather than quoting it exactly. "The UI UX task" will find "UI/UX modification".',
                'Updating only changes the fields you mention. Everything you leave out keeps its current value.',
                'Deleting a task is permanent and takes its comments with it. Marking something done is not the same as deleting it.',
            ],
            'say' => [
                'Create a task to fix the login redirect, assign it to Sara, due Friday',
                'Move the checkout bug task to Done',
                'Find tasks about billing',
            ],
            'where' => 'Projects → open a project',
            'keywords' => ['task', 'create', 'add', 'assign', 'due', 'date', 'update', 'change', 'edit', 'delete', 'move', 'ticket', 'issue', 'todo'],
        ],
        'board-columns' => [
            'title' => 'Setting up board lists',
            'stage' => self::STAGE_DAILY,
            'audience' => self::AUDIENCE_PROJECT_MANAGER,
            'summary' => 'Shaping the board lists to match how your team actually works.',
            'steps' => [
                'Every project starts with a sensible default set of lists. Rename them to match your process.',
                'Add lists for the stages you really have — a review or QA list, for instance — and reorder them by dragging.',
                'Mark the lists that mean "finished". Completion percentages, sprint health and the archive all key off that flag, and a client who can close tasks can only move work into one.',
                'Deleting a list asks what should happen to the tasks inside it. Work is never silently lost.',
            ],
            'say' => ['What projects do I have?'],
            'where' => 'Projects → open a project → board settings',
            'keywords' => ['board lists', 'lists', 'list', 'board columns', 'column', 'status', 'workflow', 'stage', 'reorder', 'rename', 'done', 'setup', 'customise', 'customize'],
        ],
        'meetings' => [
            'title' => 'Finding and joining meetings',
            'stage' => self::STAGE_DAILY,
            'audience' => self::AUDIENCE_EVERYONE,
            'summary' => 'Meetings are attached to a project, not floating in a calendar.',
            'steps' => [
                'Meetings belong to a project, so you see the ones on projects you are a member of.',
                'Each meeting has a title, a time, an agenda and a participant list, and carries a join link.',
                'Upcoming and past meetings are listed separately. Past ones keep their transcript once it exists.',
                'You are emailed when a meeting you are invited to is scheduled, changed or cancelled.',
            ],
            'say' => ['What meetings are coming up?', 'Show me past meetings'],
            'where' => 'Projects → open a project → Meetings',
            'keywords' => ['meeting', 'join', 'call', 'standup', 'retro', 'upcoming', 'past', 'schedule', 'calendar', 'link'],
        ],
        'meetings-manage' => [
            'title' => 'Scheduling and changing meetings',
            'stage' => self::STAGE_TEAM,
            'audience' => self::AUDIENCE_PROJECT_MANAGER,
            'summary' => 'Booking a meeting, moving it, and calling it off.',
            'steps' => [
                'Schedule against a project with a title, a date and time, a duration and an optional agenda.',
                'Add participants by email address. Everyone listed gets an invitation with the link, the time and the agenda.',
                'Say the time however you like — "Thursday at 4" is resolved against today\'s date for you.',
                'Editing changes only what you mention, with one exception: supplying a participant list replaces the whole list, so include everyone who stays invited.',
                'Cancelling deletes the meeting for everyone and cannot be undone. If you only want it moved, edit it instead.',
            ],
            'say' => [
                'Schedule a sprint review on Thursday at 4pm for the Website Revamp project',
                'Reschedule the sprint review to Friday at 2pm',
            ],
            'where' => 'Projects → open a project → Meetings',
            'keywords' => ['schedule', 'book', 'meeting', 'arrange', 'reschedule', 'move', 'cancel', 'invite', 'participant', 'agenda', 'duration'],
        ],
        'sprints' => [
            'title' => 'Planning, starting and closing a sprint',
            'stage' => self::STAGE_TEAM,
            'audience' => self::AUDIENCE_PROJECT_MANAGER,
            'summary' => 'The three states a sprint moves through, and what each one commits you to.',
            'steps' => [
                'Plan a sprint by creating it with a name and a date range. It starts out as planned, and you fill it with tasks.',
                'Starting it commits whatever is in it as the sprint scope. Only one sprint runs per project at a time.',
                'While it runs, add work to it by putting new tasks straight into the current sprint.',
                'Completing it freezes the numbers and counts towards velocity. Unfinished work carries over to the backlog or to the next planned sprint — nothing is dropped.',
                'Creating a sprint does not start it. Those are two deliberate steps.',
            ],
            'say' => [
                'Plan a sprint for the Website Revamp project',
                'Start the current sprint',
                'Close the sprint',
            ],
            'where' => 'Projects → open a project → Sprints',
            'keywords' => ['start a sprint', 'close a sprint', 'plan a sprint', 'sprint', 'plan', 'start', 'begin', 'close', 'complete', 'finish', 'iteration', 'cycle', 'scope', 'carry over', 'velocity'],
        ],
        'sprint-reports' => [
            'title' => 'Reading sprint health',
            'stage' => self::STAGE_TEAM,
            'audience' => self::AUDIENCE_MEMBER,
            'summary' => 'How SprintSync decides a sprint is in trouble before it is too late.',
            'steps' => [
                'A sprint report compares how much scope is done against how much of the time has gone.',
                'That gives it a verdict: on track, at risk, off track, overdue, or completed. Falling ten points of scope behind the calendar marks it at risk; twenty-five marks it off track.',
                'Lead with the verdict and the number behind it. "At risk, four of twelve done with three days left" tells the team more than a percentage on its own.',
                'Ask for the burndown when the trend matters more than today\'s snapshot.',
                'It is read-only. Ask as often as you like — it is the fastest standup you will run.',
            ],
            'say' => ['How is the current sprint going?', 'Show me the burndown for this sprint'],
            'where' => 'Projects → open a project → Sprints',
            'keywords' => ['sprint', 'report', 'status', 'health', 'progress', 'burndown', 'velocity', 'standup', 'behind', 'on track', 'at risk', 'remaining'],
        ],
        'analytics' => [
            'title' => 'Analytics across projects',
            'stage' => self::STAGE_TEAM,
            'audience' => self::AUDIENCE_MEMBER,
            'summary' => 'The wider picture: completion, overdue work, and who is carrying what.',
            'steps' => [
                'Analytics covers completion rate, open and overdue counts, a breakdown by board column and by assignee, and a per-project summary.',
                'Narrow it to one project, or ask about your own workload rather than the team\'s.',
                'It reports current state, not a date range. There is no "last month" filter — the numbers are as of now.',
                'You only ever see what your role allows, so the same question can honestly return different numbers for different people.',
                'Use analytics for the overall picture and a sprint report for one sprint\'s pace. They answer different questions.',
            ],
            'say' => ['How are we doing?', 'How am I doing?', 'What is overdue?'],
            'where' => 'Analytics',
            'keywords' => ['analytics', 'stats', 'metrics', 'report', 'progress', 'overdue', 'completion', 'performance', 'dashboard', 'overview', 'how are we doing'],
        ],
        'transcripts' => [
            'title' => 'Meeting recordings and transcripts',
            'stage' => self::STAGE_TEAM,
            'audience' => self::AUDIENCE_PROJECT_MANAGER,
            'summary' => 'Turning a recording into a searchable transcript on the meeting.',
            'steps' => [
                'Upload the recording to the meeting once it has happened. SprintSync does not host the call itself, so the audio comes from whatever you recorded it with.',
                'Transcription runs automatically in the background shortly after a meeting completes. You do not have to trigger it.',
                'If the audio was poor, the transcript is flagged as low confidence so nobody treats a bad guess as a record.',
                'If it fails outright you are notified, and you can retry it or paste a transcript in by hand.',
                'Finished transcripts stay on the meeting and are searchable from the archive.',
            ],
            'say' => ['Show me past meetings'],
            'where' => 'Projects → open a project → Meetings → a past meeting',
            'keywords' => ['transcript', 'transcribe', 'recording', 'audio', 'speech', 'text', 'meeting', 'notes', 'upload', 'retry'],
        ],
        'notifications' => [
            'title' => 'Notifications and keeping them useful',
            'stage' => self::STAGE_DAILY,
            'audience' => self::AUDIENCE_EVERYONE,
            'summary' => 'What SprintSync tells you about, and how to turn down the parts you do not want.',
            'steps' => [
                'The bell in the header carries unread notifications: task assignments, task movements, comments, and meeting lifecycle events.',
                'Meeting invitations, changes and cancellations are emailed as well as shown in the app.',
                'Set your preferences per type and per channel, so you can keep in-app notices for comments but only take email for meetings.',
                'Preferences are yours alone. Changing them affects nothing for anyone else.',
            ],
            'say' => [],
            'where' => 'Settings → Notifications',
            'keywords' => ['notification', 'alert', 'email', 'bell', 'unread', 'preference', 'mute', 'turn off', 'subscribe'],
        ],
        'roles-permissions' => [
            'title' => 'Custom roles and permissions',
            'stage' => self::STAGE_ADMIN,
            'audience' => self::AUDIENCE_MANAGE_ROLES,
            'summary' => 'Granting specific abilities on top of a base role.',
            'steps' => [
                'There are two layers. Base roles — owner, admin, member, client — set the floor. Custom roles you create grant named permissions on top.',
                'Workspace permissions cover viewing, creating and deleting projects, and inviting, removing and re-roling members.',
                'Create the role in workspace settings first, then attach it when inviting someone or when editing an existing member.',
                'A custom role attached to a client carries client permissions instead, which apply only inside the projects that client is on.',
                'Ask the assistant which roles exist and what each one grants rather than guessing — it reads the real definitions.',
            ],
            'say' => ['What custom roles exist?', 'Who is in this workspace?'],
            'where' => 'Workspace settings → Roles',
            'keywords' => ['custom role', 'permissions', 'role', 'permission', 'grant', 'admin', 'control', 'security', 'restrict', 'allow'],
        ],
        'clients' => [
            'title' => 'Giving a client access safely',
            'stage' => self::STAGE_ADMIN,
            'audience' => self::AUDIENCE_MANAGE_ROLES,
            'summary' => 'Letting a customer see the work without exposing the workspace.',
            'steps' => [
                'Invite them with the client base role. A client never sees the team roster, workspace settings, analytics, the audit log, or any project they are not on.',
                'Inviting a client gives them no project at all. You must then add them to each project you want them to see.',
                'Choose what they can do with a client role: view the board and sprints, comment on tasks, request tasks, close tasks, view meetings.',
                'With no client role attached they are read-only by default. Nothing is granted by accident.',
                'Clients cannot be assigned tasks, cannot be project managers, and can never be given workspace permissions.',
                'A client who can close tasks can only move work into a done column, never back out of one.',
            ],
            'say' => ['Invite client@acme.com as a client', 'Add them to the Website Revamp project'],
            'where' => 'Team, and Workspace settings → Roles',
            'keywords' => ['client access', 'customer access', 'give a client access', 'client', 'customer', 'external', 'guest', 'stakeholder', 'agency', 'share', 'restrict', 'read only', 'access'],
        ],
        'archive-audit' => [
            'title' => 'Archive and audit log',
            'stage' => self::STAGE_ADMIN,
            'audience' => self::AUDIENCE_MEMBER,
            'summary' => 'Finding what already happened.',
            'steps' => [
                'The archive is a searchable record of completed tasks and past meetings, so closing something does not bury it.',
                'The audit log records what changed in the workspace and its projects, and who changed it.',
                'The audit log is permission-gated — it is not something every member can open.',
                'Reach for the archive when you want the work itself, and the audit log when you want to know who did what.',
            ],
            'say' => [],
            'where' => 'Archive, and Workspace settings → Audit log',
            'keywords' => ['archive', 'audit', 'log', 'history', 'past', 'completed', 'search', 'who changed', 'record', 'trail'],
        ],
    ];

    /**
     * Curriculum order. Lessons are taught in this sequence.
     *
     * @return array<int, string>
     */
    public static function order(): array
    {
        return array_keys(self::LESSONS);
    }

    public static function has(string $slug): bool
    {
        return array_key_exists($slug, self::LESSONS);
    }

    /**
     * Every lesson this person should be taught, in curriculum order.
     *
     * @return array<int, string>
     */
    public static function slugsFor(GuideAudience $audience): array
    {
        return array_values(array_filter(
            self::order(),
            fn (string $slug) => $audience->admits(self::LESSONS[$slug]['audience']),
        ));
    }

    /**
     * The lesson body, plus where it sits in this person's own curriculum.
     *
     * @return array<string, mixed>
     */
    public static function lesson(string $slug, GuideAudience $audience): array
    {
        $lesson = self::LESSONS[$slug];
        $available = self::slugsFor($audience);
        $position = array_search($slug, $available, true);

        return [
            'topic' => $slug,
            'title' => $lesson['title'],
            'stage' => $lesson['stage'],
            'summary' => $lesson['summary'],
            'steps' => $lesson['steps'],
            'try_saying' => $lesson['say'],
            'where_in_the_app' => $lesson['where'],
            'lesson_number' => $position === false ? null : $position + 1,
            'of_total' => count($available),
            'next_topic' => $position === false ? null : ($available[$position + 1] ?? null),
        ];
    }

    /**
     * The table of contents, grouped by stage, for whoever is asking.
     *
     * @return array<int, array{stage: string, lessons: array<int, array{topic: string, title: string, summary: string}>}>
     */
    public static function curriculumFor(GuideAudience $audience): array
    {
        $stages = [];

        foreach (self::slugsFor($audience) as $slug) {
            $lesson = self::LESSONS[$slug];
            $stages[$lesson['stage']][] = [
                'topic' => $slug,
                'title' => $lesson['title'],
                'summary' => $lesson['summary'],
            ];
        }

        return array_map(
            fn (string $stage, array $lessons) => ['stage' => $stage, 'lessons' => $lessons],
            array_keys($stages),
            array_values($stages),
        );
    }

    /**
     * There are only a handful of lessons and their summaries share a lot of
     * vocabulary, so the generic floor lets far too much through. "Export to a
     * spreadsheet" should find nothing rather than the nearest paragraph that
     * happens to contain "to".
     */
    private const SEARCH_FLOOR = 45;

    /** Score gap within which two lessons count as equally good matches. */
    private const CLOSE_ENOUGH = 8;

    /**
     * Below this the best match is a guess, not an answer. Guesses are left in
     * honest score order so the caller can offer them as candidates.
     */
    private const STRONG = 60;

    /**
     * Lead-ins people put in front of the thing they actually want to know.
     * Left in, they dilute the score of every real word in the query.
     */
    /**
     * Words that carry no subject. Stripped from anywhere in the query, not
     * just the front, because "tell me how the board works" buries "board".
     */
    private const FILLER = [
        'a', 'an', 'the', 'i', 'me', 'my', 'we', 'us', 'our', 'you', 'your', 'it', 'is', 'are', 'am', 'be',
        'do', 'does', 'did', 'can', 'could', 'should', 'would', 'will', 'to', 'for', 'of', 'in', 'on', 'at',
        'with', 'and', 'or', 'but', 'how', 'what', 'when', 'where', 'why', 'who', 'which', 'this', 'that',
        'these', 'those', 'any', 'please', 'help', 'tell', 'show', 'teach', 'train', 'guide', 'walk',
        'explain', 'learn', 'about', 'want', 'need', 'know', 'get', 'make', 'let', 'lets', 'thing', 'things',
        'stuff', 'here', 'there', 'all', 'everything', 'someone', 'somebody', 'work', 'works', 'using', 'use',
        'give', 'gives', 'giving', 'put', 'go', 'goes', 'going',
    ];

    /**
     * Ranks the lessons this person may see against what they typed.
     *
     * @return array<int, array{item: string, score: int}>
     */
    public static function search(string $query, GuideAudience $audience): array
    {
        $query = self::stripFiller($query);

        if ($query === '') {
            return [];
        }

        /*
         * Keywords first, because rank() weights the first field highest and
         * the curated keyword list predicts intent better than a title does.
         * Summaries are excluded entirely: they name other features in passing
         * — orientation's mentions sprints, meetings and boards — which sends
         * half the queries to the wrong lesson.
         */
        $ranked = FuzzyMatcher::rank(
            $query,
            self::slugsFor($audience),
            fn (string $slug) => [
                implode(' ', self::LESSONS[$slug]['keywords']),
                self::LESSONS[$slug]['title'],
            ],
            self::SEARCH_FLOOR,
        );

        return self::preferFoundational($ranked);
    }

    /**
     * The curriculum runs general to specific, so when two lessons match about
     * equally well the earlier one is the better thing to teach. "The board"
     * should open the board lesson, not the one about configuring its columns.
     *
     * @param  array<int, array{item: string, score: int}>  $ranked
     * @return array<int, array{item: string, score: int}>
     */
    private static function preferFoundational(array $ranked): array
    {
        if ($ranked === [] || $ranked[0]['score'] < self::STRONG) {
            return $ranked;
        }

        $order = array_flip(self::order());
        $cutoff = $ranked[0]['score'] - self::CLOSE_ENOUGH;

        /*
         * Partitioned rather than sorted with a mixed comparator: ranking some
         * pairs by score and others by curriculum position is not a consistent
         * ordering, and usort quietly returns nonsense when given one.
         */
        $close = array_values(array_filter($ranked, fn (array $match) => $match['score'] >= $cutoff));
        $rest = array_values(array_filter($ranked, fn (array $match) => $match['score'] < $cutoff));

        usort($close, fn (array $a, array $b) => $order[$a['item']] <=> $order[$b['item']]);

        return [...$close, ...$rest];
    }

    /**
     * Reduces "how do I close a sprint" to "close sprint". People wrap the
     * thing they want in question words, and every one of them dilutes the
     * score of the words that actually carry the question.
     */
    private static function stripFiller(string $query): string
    {
        $normalised = FuzzyMatcher::normalise($query);

        $kept = array_values(array_filter(
            explode(' ', $normalised),
            fn (string $word) => $word !== '' && ! in_array($word, self::FILLER, true),
        ));

        /* "How are we doing?" is all filler. Better to match it than to give up. */
        return $kept === [] ? $normalised : implode(' ', $kept);
    }

    public static function titleOf(string $slug): string
    {
        return self::LESSONS[$slug]['title'];
    }
}
