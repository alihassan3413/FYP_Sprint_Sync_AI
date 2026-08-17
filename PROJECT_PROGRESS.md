# SprintSync — Project Progress

Living tracker for FR implementation status, decisions, and what to build next.
Source of truth for FR wording: **`docs/FUNCTIONAL_REQUIREMENTS.md`**, extracted
verbatim from `SprintSync FYP1 Report-1.docx` (section 2.3.1, Tables 2.3–2.37)
on 2026-08-17. Every FR below is now gradeable against real requirement text
rather than a work-log heading.

## Current FR status

| Status | Count | FRs |
|---|---|---|
| Complete | 15 | FR03, FR07, FR08, FR09, FR14, FR17, FR21, FR24, FR25, FR26, FR29, FR30, FR32, FR33, FR34 |
| Partial | 14 | FR01, FR04, FR05, FR06, FR15, FR16, FR18, FR19, FR20, FR22, FR23, FR28, FR31, FR35 |
| Design Mismatch | 2 | FR02, FR27 |
| Not started | 4 | FR10, FR11, FR12, FR13 |

Strict completion: **15 / 35 (42.9%)**. Counting the two Design Mismatches as
satisfied-by-design (the recommended resolution): **17 / 35 (48.6%)**.
Weighted (Complete=1, Design Mismatch=1, Partial=0.5): **68.6%**.

Entry #27 closed FR04-03, FR04-05, FR06-01 and FR32-05, moving FR32 to
Complete and FR04 into the blocked-only group. Entry #28 closed FR14-04,
moving FR14 to Complete (its only remaining verdict is the FR14-02 design
mismatch, where custom board columns are a superset of the report's three
fixed ones).

**These counts replace entry #25's and are much lower — read why before
reacting.** Entry #26 is the first audit graded at *sub-requirement* level
across all 35 FRs, applying the rule that an FR cannot stay Complete while a
mandatory sub-requirement is unimplemented. Nothing regressed; the earlier
numbers were graded per-FR against work-log headings, which flattered them.

**The more useful number.** Of the 16 Partials, **five (FR04, FR15, FR19,
FR22, FR23) have no unblocked work left at all** — every remaining gap in them
is Blocked by FR10–FR13. So **18 of 35 FRs need no further work before the AI
pipeline**, and the realistic pre-FR10 target is the unblocked list in entry
#26, not all 16.

Per-FR history for FR30, FR33, FR34 and FR35 (entries #21–#25) is superseded
by the sub-requirement grading in **entry #26**, which is the authoritative
status. FR30, FR33 and FR34 survive that grading as Complete; FR35 is Partial.

The codebase is a real multi-tenant workspace product now: auth, workspaces,
invitations, custom roles, team management, projects, a task/Kanban board,
a complete meeting lifecycle (schedule, view details, join, edit, cancel),
email notifications on every meeting lifecycle event, an in-app
notification center (meetings + task assignment/movement/comments) with
per-user, per-type/channel notification preferences, a searchable archive
of completed tasks and past meetings, a workspace/project analytics
dashboard, user profile pictures, and a workspace/project audit log are all
built and tested. Only transcription + AI summary (FR10–FR13) remains
untouched.

Projects, Tasks, and Meetings (FR32, FR14–FR17, FR05) now also have
**project-level membership and access control** layered underneath them —
see work-log entries #8 and #9. This is an authorization hardening of
those existing FRs, not a newly tracked FR number on its own (no line item
in the FYP1 report maps to it directly, as far as this session could tell
without re-reading the source `.docx`), so the table above is unchanged.

The Kanban board (FR14–FR17) also grew custom/reorderable board columns,
a task-detail-first popup, and task comments (entries #12–#14) — none of
this maps to a distinct FR number either; it's UX/feature depth added on
top of the existing Tasks FRs at the user's request, beyond what FR14–FR17
originally specified.

## Completed work log

### 1. FR01–FR35 implementation audit
Full codebase audit against the FYP1 report, file-by-file evidence, verified
with `route:list`, the test suite, Pint, ESLint, and a production build.
Found the app was a solid workspace/team/roles foundation with none of the
report's meeting/AI/task/notification domain built yet.

### 2. Fixed: invitations silently failing on the Team page
`useCurrentWorkspace.ts`'s `workspaceRoute()` fell back to `route('login')`
whenever the globally-"current" workspace didn't match the workspace a page
was actually scoped to. The invite form POSTed to `/login`, which — for an
authenticated user — bounced straight to the dashboard with no error.
Fixed by letting callers pass an explicit `workspace` param that takes
priority, and having `workspace/invitations/Create.vue` use its own
controller-provided `workspace` prop instead of global state.

### 3. FR32 — Projects module (Create/list/view/edit/delete)
Full workspace-scoped Projects module: migration, model, factory, policy
(Owner/Admin manage, any member views), Data DTOs, requests, actions,
controller, routes under `TenantRoute`, full Vue CRUD UI, 18 feature tests.
`Workspace::projects()` relation added — required for Laravel's automatic
scoped route-model-binding on `{workspace}/projects/{project}`.

### 4. Fixed: Team-page "Change role" / "Remove member" silently failing
Same bug class as #2, different shape: `ChangeMemberRoleModal.vue` and
`RemoveMemberDialog.vue` called `workspaceRoute(name, member.id)` — a bare
number instead of a params object. `workspaceRoute()` spreads its second
argument (`{...params, workspace}`); spreading a number produces `{}`, so
the `user` route parameter silently vanished. Fixed by passing
`{ user: member.id }`, matching the actual route signature
(`{user}` in `app/Modules/Teams/Routes/web.php`, bound to `User $user`).

### 5. FR14–FR17 — Project Tasks & Kanban board
New `Tasks` module, scoped three levels deep:
`{workspace}/projects/{project}/tasks/{task}`. Migration, model
(`App\TaskStatus` enum: `todo` / `in_progress` / `done`), factory, policy,
Data DTOs, requests, actions, controller, routes, full Vue Kanban UI
(3 columns, per-card status control, create/edit/delete modals, assignee
picker), 20 feature tests. `Project::tasks()` relation added for the same
scoped-binding reason as #3. `ProjectController::show` now also returns
`tasks`, `members`, and `canManageTasks` so the board renders on the
existing project page (no separate board route).

### 6. Projects/Tasks UX redesign — Kanban-first project workspace
Reworked the project page around the board instead of a metadata card, and
replaced the per-card status buttons with real drag-and-drop.

- **Projects index**: rows were already clickable (`AppDataTable`'s
  `clickable-rows`); removed "View" from `ProjectActionsMenu` (Edit/Delete
  only, menu hidden entirely for non-managers instead of showing an empty
  dropdown).
- **Project show page redesign**: compact header (title + truncated
  description via `AppPageHeader`, no more full-width Overview card),
  workspace-style tabs — **Board** (default), **Meetings** (placeholder),
  **Activity** (placeholder), **Settings** (project details + danger zone,
  moved out of the header). Page container no longer caps width, so the
  board fills the available space.
- **Drag-and-drop Kanban**: native HTML5 DnD (`draggable`, `dragstart`,
  `dragover.prevent`, `drop`) — no library added. `KanbanBoard.vue` keeps an
  independent local copy of `tasks` (`localTasks`, deep-copied per element)
  so a drop moves the card instantly; the same
  `workspace.projects.tasks.update-status` endpoint is called via
  `router.patch`, and `onError` reverts the card's status and fires a toast
  through the existing `useNotificationStore()`. A per-card `pending` state
  disables re-dragging and dims the card while its request is in flight;
  a brief `hasError` ring flashes red on failure. Only draggable if the
  viewer could already change that task's status (Owner/Admin, or the
  assignee) — same authorization the old segmented control used, so no
  backend rule changed.
- **Task cards**: dropped the 3-button status switcher entirely; clicking a
  card opens `EditTaskModal`. That modal now takes a `canManage` prop — full
  edit form for Owner/Admin, a read-only "Task details" view for everyone
  else (uses `TaskPolicy::view`, which already permitted this — no policy
  change, just the first UI that exposes it).
- **New `Tabs` UI primitive** (`resources/js/components/ui/tabs/`) built on
  `reka-ui`'s `TabsRoot/TabsList/TabsTrigger/TabsContent` — already a
  dependency (same library the existing `Dialog`/`Checkbox`/`Separator`
  wrappers use) — so no package was added for the tabs either.

### 7. FR05 — Schedule Meeting
New `Meetings` module, scoped the same way as Tasks:
`{workspace}/projects/{project}/meetings/{meeting}`. Migration, model
(`title`, `description`/agenda, `scheduled_at` datetime, `duration_minutes`,
`meeting_link`, `created_by`), factory, policy (Owner/Admin manage, any
workspace member views), Data DTOs, requests, actions, controller, routes,
Vue UI, 18 feature tests. `Project::meetings()` relation added for the same
scoped-binding reason as Tasks/Projects. `ProjectController::show` now also
returns `meetings` and `canManageMeetings`, rendered in the existing
**Meetings** tab on the project page (replacing its placeholder), with
create/edit/delete modals and a "Join link" action on each meeting card.

- **No index/show route** — meetings ride on `ProjectController::show`
  exactly like Tasks; only `store`/`update`/`destroy` routes exist. Keeps
  the same "page controller aggregates what it renders" convention.
- **`created_by` is set server-side from the authenticated user**, never
  accepted from client input — `CreateMeetingAction::handle()` takes the
  request's `User` explicitly rather than trusting a form field.
- **`meeting_link` is optional and validated as a URL when present.**
  FR05 explicitly excludes Zoom integration, so there's no way to generate
  a link automatically yet — a manually pasted link (Zoom/Meet/Teams/etc.)
  is the only option, and a meeting can be scheduled without one and have
  the link added later via edit.
- **The Meetings tab splits meetings into "Upcoming" / "Past"** sections
  client-side (`isPastMeeting()` compares `scheduled_at + duration_minutes`
  against now) rather than adding a second backend query — the controller
  already returns every meeting for the project in `scheduled_at` order.
- **Same authorization shape as Tasks**: Owner/Admin can create/update/
  delete (`MeetingPolicy::create/update/delete` — workspace-level
  `UserRole::ADMIN`), any workspace member can view. No assignee-style
  "partial" ability was needed since FR05 has no per-meeting delegate role.

**Note on concurrent changes observed in the working tree:** while this task
was in progress, `Project.php`, `ProjectPolicy.php`, `TaskPolicy.php`,
`StoreTaskRequest.php`, `UpdateTaskRequest.php`, `CreateProjectAction.php`,
and `ProjectController.php` were also modified outside this session (a new
`App\ProjectRole` enum and `project_users` pivot table appeared, adding
project-level membership on top of workspace-level roles). That work looks
unfinished — it currently breaks two pre-existing Task tests (see Test
results below) — and is unrelated to FR05, so it was left untouched rather
than fixed or reverted here.

**Update:** that work is entry #8 below, completed in a later session. Both
`TaskTest` failures flagged above are fixed as part of it — see Test
results.

### 8. Project-level membership & project manager assignment

Projects previously had exactly one authorization tier: workspace
Owner/Admin manage everything, any workspace member (even a total stranger
to that specific project) can view any project and its tasks. That's now
replaced with a second, project-scoped tier sitting underneath the existing
workspace roles.

**Data model** — new `project_users` pivot (`project_id`, `user_id`, `role`
string, unique on the pair), mirroring `workspace_users`'s exact shape. New
`App\ProjectRole` enum (`manager` / `member`, with `rank()`/`atLeast()`
matching `App\UserRole`'s pattern), placed at the top level rather than
inside `app/Modules/Projects` — same precedent as `TaskStatus`/`UserRole`.
`Project::members()` (`BelongsToMany` through `project_users`),
`managers()`, `hasMember()`, `roleFor()`, `userHasAtLeast()` all added,
directly mirroring the equivalent `Workspace` methods.

**Access-control rule** (the actual requirement): workspace Owner/Admin
always retain full access to every project regardless of `project_users`
rows. Below that rank, a user must appear in `project_users` to see or act
on a project at all — a project **Manager** can update the project's
details, manage its `project_users` roster, and create/update/delete its
tasks; a plain project **Member** can only view the project/its tasks and
update the status of tasks assigned to them (unchanged from the existing
assignee rule). A workspace member with no `project_users` row for that
project is treated the same as a total outsider to it.

- `ProjectPolicy::view/update/manageMembers` and `TaskPolicy::viewAny/view/
  create/update/updateStatus/delete` all now check
  `$workspace->userHasAtLeast(ADMIN) || $project->userHasAtLeast(MANAGER)`
  (view-only checks use `hasMember()` instead of the Manager-rank check).
  `delete` on a project stays Admin-only — "manage their assigned
  projects" was read as day-to-day operation, not the power to destroy the
  project entity, matching the existing least-privilege posture on
  `WorkspacePolicy::delete`.
- **Found and fixed a real authorization gap while wiring this up**:
  `ProjectController::show()` never actually called `$user->can('view',
  $project)` anywhere — the route was only gated by `EnsureWorkspaceMember`
  (workspace membership), so `ProjectPolicy::view()` had been dead code
  since FR32 shipped. Any workspace member could already open any
  project's page and task board; the new membership rule would have been
  silently unenforceable without adding the missing `abort_unless($user
  ->can('view', $project), 403)` call. Worth double-checking other
  controllers for the same "policy method exists but nothing calls it"
  gap.
- **Task assignment is now project-scoped, not workspace-scoped.**
  `StoreTaskRequest`/`UpdateTaskRequest` previously allowed assigning a
  task to *any* workspace member; that's a direct hole in "unassigned
  members can't access project tasks" (an assignee who could see/act on
  their own task without being a project member). Both requests now
  require the assignee to be a project member OR a workspace Admin+.
  `ProjectController::show()`'s `members` prop (the assignee-picker data
  source for `CreateTaskModal`/`EditTaskModal`) was narrowed to match —
  project members ∪ workspace admins — so the picker never offers a
  choice that would fail validation.
- **New `AddProjectMemberAction`/`UpdateProjectMemberRoleAction`/
  `RemoveProjectMemberAction`** + `ProjectMemberController` (`store`/
  `update`/`destroy`) + `StoreProjectMemberRequest`/
  `UpdateProjectMemberRequest`, routed at
  `{workspace}/projects/{project}/members[/{member}]`
  (`workspace.projects.members.*`). Both the assign-and-change-role
  FormRequests authorize via a new `ProjectPolicy::manageMembers` ability
  (Admin+ or that project's Manager), matching the
  `UpdateTeamMemberRequest` pattern already used for workspace roles.
- **Route param is `{member}`, not `{user}`.** Laravel's scoped
  route-model-binding derives the parent relationship name from
  `Str::plural(Str::camel($paramName))` — `{user}` would look for
  `Project::users()`, which doesn't exist (the required relation is named
  `members()`). Naming the param `{member}` makes Laravel resolve it via
  `Project::members()` automatically, giving the same "404 if not
  actually a member" behavior as `workspace.members.{user}` gets from
  `Workspace::users()` — confirmed by reading
  `Illuminate\Database\Eloquent\Model::childRouteBindingRelationshipName()`
  rather than guessing.
- **`ProjectController::index()` now filters the list per viewer.**
  Owner/Admin still see every project; everyone else sees only projects
  they have a `project_users` row for. Matches "members can only access
  projects they are assigned to" — a member who can't open a project
  shouldn't see it listed as a dead link either.
- **`CreateProjectAction` auto-attaches the creator as that project's
  first Manager.** Not strictly required for access (the creator is
  already an Admin), but avoids shipping a project with zero visible
  managers in the new members UI, and gives a sensible default.
- **Frontend**: a "Project members" panel replaces the old static
  "Members: N in this workspace" line inside the existing Settings tab
  (per the task's instruction — this lives in Project Settings, not a new
  tab). Lists each member with `AppAvatar` + `AppRoleBadge` (added a
  `manager` entry to the shared badge's role map — teal, `UserCog` icon —
  it previously had no case for this role and would've fallen back to a
  plain gray badge), a per-row actions menu (change role / remove) gated
  on the new `canManageProjectMembers` prop, and an "Add member" button
  opening a picker over workspace members not already on the project.
  Three new modals (`AddProjectMemberModal`, `ChangeProjectMemberRoleModal`,
  `RemoveProjectMemberDialog`) directly mirror the Team page's existing
  `ChangeMemberRoleModal`/`RemoveMemberDialog` pair for UI consistency.
- **Explicitly not built** (per this task's own scope): custom/configurable
  project roles — only the fixed `manager`/`member` pair exists, same
  spirit as `UserRole` before `WorkspaceRole` was added on top of it later.

### 9. Meeting access control aligned to project-level membership

Closed the gap flagged at the end of entry #8: `MeetingPolicy` still let
any workspace member view/manage meetings via workspace-Admin-only checks,
while `ProjectPolicy`/`TaskPolicy` had already moved to the
`workspace admin-rank OR project role-rank` model. `MeetingPolicy` now
matches `TaskPolicy` exactly:

- `viewAny`/`view`: workspace Admin+ OR `project->hasMember($user)` (any
  project role, Manager or Member).
- `create`/`update`/`delete`: workspace Admin+ OR
  `project->userHasAtLeast($user, ProjectRole::MANAGER)`.

No route, controller, or migration changes were needed. `MeetingController`
already authorizes purely through the policy (`StoreMeetingRequest`/
`UpdateMeetingRequest::authorize()`, and `abort_unless(...->can('delete',
...))` in `destroy()`), and `ProjectController::show()` already gates the
entire page — including the `meetings` prop — behind `ProjectPolicy::view`
before anything is rendered. Updating the policy alone was sufficient to
make "unassigned member can't view this project's meetings" and "project
Manager can manage this project's meetings" both true; `canManageMeetings`
(`$user->can('create', [Meeting::class, $project])`) picks up the new rule
automatically too.

- **Tenant isolation was already correct and untouched**: the
  `{workspace}/projects/{project}/meetings/{meeting}` scoped bindings
  already 404 a meeting that doesn't belong to both the project and
  workspace in the URL, independent of the policy layer — verified by the
  existing cross-project/cross-workspace tests, kept as-is.
- **`MeetingTest.php` expanded from 18 to 23 tests**, covering every
  scenario this task asked for by name: Owner (existing), a new dedicated
  Admin test proving full access *without* a `project_users` row, a
  Project Manager bundle (create/update/delete on their assigned project,
  plus a manager-of-a-different-project denial), a Project Member test
  proving view-without-manage (loads the project page and sees `meetings`,
  but a create attempt is `assertForbidden()`), an unassigned-workspace-
  member view-denial test (`assertForbidden()` on the project show route
  itself, since that's the only place meetings are ever surfaced), and the
  pre-existing cross-project/cross-workspace isolation tests kept intact.

### 10. FR06–FR09 — Meeting details, join, edit, and cancel

Completed the meeting lifecycle on top of the unchanged FR05 backend — no
migration, route, controller, policy, or DTO changes were needed; every
requirement was reachable through the existing
`workspace.projects.meetings.{store,update,destroy}` endpoints and
`MeetingPolicy` (entry #9). This was purely a frontend completion pass,
following the exact pattern `EditTaskModal` already established for Tasks.

- **FR06 (details) + FR08 (edit) share one component**, `EditMeetingModal`,
  now taking a `canManage` prop exactly like `EditTaskModal`: managers get
  the existing edit form, everyone else gets a read-only "Meeting details"
  view (title, agenda, date/time, duration, a Join Meeting button or "No
  link added yet.", and "Created by" via `creator_name`). No "status"
  field exists in the `meetings` table and none was added (per the task's
  explicit "don't add status complexity" instruction); the closest honest
  read of "status if one already exists" is the already-established
  Upcoming/Past distinction (`isPastMeeting()`), so that's now shown as a
  badge in both the card and the details view instead of inventing a new
  persisted status.
- **FR07 (join)**: `MeetingCard` now emits `open` on a click anywhere on
  the card (mirroring `TaskCard`), which routes to the same
  `EditMeetingModal` used for FR06/FR08 — "clicking a meeting opens its
  details" and "click to edit" are the same action, gated by `canManage`
  inside the modal, not by two different entry points. A dedicated **Join
  Meeting** button (`<Button as="a">`, reka-ui's `Primitive` rendering a
  real `<a>` styled as a button) replaced the small text link, present on
  both the card and inside the details modal, always `target="_blank"
  rel="noopener noreferrer"`.
- **New `isValidMeetingLink()`** in `lib/meetings.ts` — parses the link
  with the `URL` constructor and only accepts `http:`/`https:` protocols.
  Missing or invalid links never render a Join button; a "No link added"
  note shows instead. This is stricter than the backend's `url` validation
  rule (which is intentionally permissive about schemes), added as a
  client-side safety net so a stray `javascript:`/`data:` link — however
  it got into the database — can never end up as a clickable
  `target="_blank"` action.
- **Menu-only actions unaffected**: `MeetingActionsMenu`'s Edit/Delete
  items still route through the same `edit`/`delete` events as before;
  the card's whole-surface click is additive, not a replacement, and both
  the menu and the Join button call `@click.stop` so they don't also
  trigger the card's `open`.
- **FR09 (cancel/delete)**: `DeleteMeetingDialog` was already a complete,
  confirmed hard-delete flow against the existing `destroy` endpoint —
  reused as-is, no soft-delete or status field added, matching "don't add
  status complexity unless the domain already supports it." After a
  successful delete, Inertia's `back()` response reloads the page's props,
  so the meeting disappears from the list immediately without a manual
  refetch.
- **Backend tests**: 2 new cases added to `MeetingTest.php` (23, after
  entry #9, → 25) —
  `test_the_project_show_page_exposes_full_meeting_details_for_a_project_member`
  asserts every FR06 field (`title`, `description`, `duration_minutes`,
  `meeting_link`, `created_by`, `creator_name`) round-trips correctly to a
  Project Member's Inertia payload, and
  `test_the_project_show_page_reports_a_null_meeting_link_when_none_is_set`
  confirms the join-link rendering data contract (`null` vs. a URL string)
  the frontend's `isValidMeetingLink()` branches on. View/edit/delete
  permissions and cross-project/cross-workspace isolation were already
  fully covered by entry #9 and needed no changes, since this task
  introduced no new backend surface.
- **No JS test runner exists in this project** (unchanged from prior
  entries) — the click-to-open, Join Meeting button, and read-only-vs-edit
  modal switch were verified by ESLint + a successful production build
  (`show-*.js` bundle grew from 50.18 kB to 52.74 kB, confirming the new
  code compiled) plus manual code review against the exact pattern
  `EditTaskModal`/`TaskCard` already prove out in production.

### 11. FR23 — Meeting email notifications

Read this file, the Meetings module, `MemberInvitationMail` (the only
existing Mail class in the app), and the project-membership model
(entries #8/#9) before writing anything. Every design choice below reuses
that existing architecture rather than introducing a new one.

**Trigger points — inside the Action layer, not the controller.**
`CreateMeetingAction`, `UpdateMeetingAction`, `DeleteMeetingAction` each
gained a private `notify()` step at the end of `handle()`, matching how
`CreateWorkspaceInvitationAction::dispatchMail()` already does this for
invitations. `MeetingController` only changed to pass `$request->user()`
through to `UpdateMeetingAction`/`DeleteMeetingAction` (previously only
`CreateMeetingAction` received the actor) — controllers stay exactly as
thin as before, just forwarding the actor the actions now need to know
who to exclude and whom to credit as "scheduled/updated/cancelled by."

**Recipients — `ResolveMeetingRecipients`, one new shared class.** All
three actions call it; it returns `$meeting->project->members()` (the
`project_users` roster — Manager *and* Member roles) minus the acting
user, deduplicated by id. Two deliberate reads of the requirement:

- *"Workspace Owner/Admin may be included only if they are relevant
  according to the existing membership model"* — the existing model's
  definition of "relevant to a project" is a `project_users` row, not the
  workspace-rank override that merely grants view/manage *permission*
  without membership (entry #8). An Owner/Admin who isn't an explicit
  project member does **not** get emailed about that project's meetings —
  they can still see everything if they open the page, but they don't get
  spammed for every project across the workspace. An Owner/Admin who *is*
  a project member (e.g. the project creator, auto-attached as Manager by
  `CreateProjectAction`) is treated exactly like any other project member.
  This directly satisfies "do not email unrelated workspace members."
- *"Do not send to the actor if existing conventions exclude
  self-notifications"* — no prior precedent exists in this codebase
  (`MemberInvitationMail` only ever emails one external invitee, never a
  set of existing users, so there was nothing to "stay consistent" with).
  Applied the standard, expected default instead: whoever performed the
  create/update/delete never receives their own notification about it.
- Duplicates are structurally impossible from the DB side (`project_users`
  has a unique `(project_id, user_id)` constraint) but
  `ResolveMeetingRecipients` still calls `->unique('id')` defensively, and
  a dedicated test asserts the queued recipient list has no repeats.

**Update notifications only fire on a meaningful change.**
`UpdateMeetingAction` calls `$meeting->wasChanged([...5 fields...])`
*after* `$meeting->update(...)` and only notifies if true — opening the
edit modal and saving without changing anything (or Owner/Admin editing a
project they don't otherwise interact with) doesn't spam anyone.

**Three new `Mailable`s in `app/Mail/`** (not module-scoped — matches
where `MemberInvitationMail` already lives, the one existing precedent):
`MeetingScheduledMail`, `MeetingUpdatedMail`, `MeetingCancelledMail`. All
three implement `ShouldQueue` with `tries = 3` / `backoff = 30`, identical
to `MemberInvitationMail` — queue infrastructure already exists
(`QUEUE_CONNECTION=database` in `.env.example`, `jobs` table migration
already present), so "prefer queued delivery" required zero new
infrastructure. Three matching Blade views in `resources/views/emails/`
reuse the exact table-based, email-client-safe layout
`member-invitation.blade.php` established (brand mark, offset-shadow
card, `#d4ff4f` accent) — scheduled/updated show project name, title,
date/time, duration, agenda if present, and a Join Meeting button if
`hasValidJoinLink()` (new `Meeting` model method, server-side mirror of
the frontend's `isValidMeetingLink()` from entry #10 — only `http`/`https`
links render a join action); cancelled clearly states the meeting won't
happen, shows what it was scheduled for, and has no join action.

**Failure isolation.** `DeleteMeetingAction` captures `projectName`/
`meetingTitle`/`scheduledAt`/recipients *before* calling
`$meeting->delete()`, so the cancellation email is only even attempted
once the delete has actually happened — a failed delete can't produce a
false "cancelled" email. All three actions wrap their `Mail::queue()`
loop in `try`/`catch (Throwable)`, logging via `Log::error()` with the
same shape `ExecuteToolCall` already uses for its own failure logging
(`Log::error('message', ['...context...', 'exception' => $e])`) — a mail
dispatch failure is swallowed and reported, never bubbles up to fail the
HTTP response or roll back the meeting write that already succeeded.
Since `Mail::queue()` against the `database` queue connection is just a
row insert (not a live network send), the realistic failure surface here
is a DB error, not a provider error — so "don't expose sensitive provider
errors to users" is satisfied by construction: the user only ever sees
the existing `back()->with('success', ...)` response, never anything
about mail at all.

**Tests**: new `tests/Feature/Meetings/MeetingNotificationTest.php`
(10 tests, `Mail::fake()` in `setUp()`) — scheduled email reaches exactly
the project's Manager + Member and not the unassigned workspace member or
the creator; email payload matches the meeting's actual fields; updates
with a real change email the project (minus actor), updates with no real
change email nobody; cancellation reaches the project (minus actor) with
`cancelledByName` set; a combined "unassigned member never appears in any
of the three mailables' recipients" check; and an explicit no-duplicate-
recipients assertion. `MeetingTest.php`'s existing 25 tests needed no
changes and stayed green throughout — the Action signature changes
(`UpdateMeetingAction`/`DeleteMeetingAction` gaining a `User $actor`
parameter) were absorbed entirely by `MeetingController`, invisible to
every test that goes through the HTTP layer.

### 12. Kanban board UX — custom board columns, column reordering, task-detail-first popup

Retroactive log entry: this and entry #13 cover three UX-driven turns that
shipped without an explicit "update PROJECT_PROGRESS.md" instruction at
the time, so they were never logged. Documented now, alongside entry #14,
so the log stays an accurate picture of what actually exists.

**Custom board columns.** `App\TaskStatus` (the fixed `todo`/`in_progress`/
`done` enum) was removed entirely and replaced with a per-project
`board_columns` table (`name`, `position`, `is_default`, `is_done`).
`tasks.status` became `tasks.board_column_id` (FK), migrated with a data
migration that backfilled every existing project with 3 default columns
and remapped every existing task's old enum value to the matching new
column — verified against a backed-up copy of the dev SQLite database
before running it. Every project now gets those 3 defaults
(To Do/In Progress/Done, `is_default = true`, "Done" also `is_done = true`)
seeded on creation (`CreateProjectAction`, and `ProjectFactory` for tests).
Owner/Admin/project Manager can add and delete custom columns
(`BoardColumnPolicy`, mirrors the existing Manager-tier pattern); default
columns can't be deleted, and a column with tasks in it can't be deleted
until it's empty — both enforced as friendly `back()->with('error', ...)`
responses (toast), not raw 403s, since the *role* check and the *state*
check are different kinds of failure.

**Column reordering.** Drag any column header (including defaults — only
deletion is locked, not position) to reorder the board.
`ReorderBoardColumnsAction` validates the submitted list is an exact
permutation of the project's real columns (`size:N` + `distinct` +
`Rule::in`) before writing new `position` values, so a partial or
duplicate-laden submission is rejected outright rather than silently
corrupting order. Frontend mirrors the same optimistic-update +
revert-on-error pattern the task drag-and-drop already established.

**Task-detail-first popup (v1).** Clicking a task card used to jump
straight into the edit form for managers. Split into a `mode: 'view' |
'edit'` toggle inside one modal — click-to-open always lands on a
read-only view first, with an explicit "Edit" action for managers, mirror
of the same pattern already used for meetings. Also added a "move to
column" dropdown directly on each `TaskCard` (`ArrowRightLeft` icon),
using the exact same `updateStatus` endpoint and policy as drag-and-drop,
since dragging across many custom columns doesn't scale as the only way
to reassign a task's stage.

**Tests.** `tests/Feature/Tasks/BoardColumnTest.php` (21 tests — add/
delete/reorder × Owner/Admin/Manager/Member/outsider, empty-column and
default-column delete guards, cross-project and cross-workspace
isolation, reorder payload validation). `TaskTest.php` rewritten in place
for `board_column_id` instead of the old `status` enum. Full suite: 214
passed at the end of this stretch of work.

### 13. Task comments (chat)

Also retroactive (see entry #12's note). New `task_comments` table
(`task_id`, `user_id`, `body`), 4 levels deep in the route tree
(`workspace → project → task → comment`), same scoped-binding pattern
proven throughout this project. `TaskCommentPolicy`: any project member
(or workspace Admin+) can view and post — deliberately as broad as task
*view* access, not task *management*, since the point is two-way
conversation between whoever's assigned and whoever assigned it, not a
manager broadcast channel. Delete is the comment's own author, or
workspace Admin+/project Manager (moderation).

Comments are eager-loaded and nested directly onto each task
(`task.comments`) in the existing `ProjectController::show()` response —
no separate JSON API, since this app is 100% Inertia-based and posting a
comment goes through the same `router.post` + page-reload pattern every
other create action here already uses.

**Tests**: `tests/Feature/Tasks/TaskCommentTest.php` (14 tests). Full
suite: 228 passed at the end of this turn — the baseline entry #14 starts
from, since #14 is frontend-only and doesn't change this number.

### 14. Task Detail redesign + comment-refresh bug fix

Two-part ask: the task detail modal from entry #12 "looked cramped and
generic," and newly-submitted comments only appeared after a manual page
refresh. Read this file, the current `TaskDetailModal`/`TaskCommentThread`,
`TaskCard`, `KanbanBoard`'s existing local-state/optimistic patterns, the
notification store, and how every other modal in this app is built before
changing anything, per the task's own instruction.

**Root cause of the refresh bug.** `show.vue` holds `taskModalTarget` as a
plain `ref<Task | null>`, assigned once when a card is clicked. Posting a
comment triggers a normal Inertia `back()` redirect + prop reload — which
*does* return fresh data (the backend already eager-loads comments, see
entry #13) — but nothing ever pointed `taskModalTarget` at the new object
in the refreshed `tasks` array. Vue kept rendering the stale reference
from before the reload; only closing and reopening the modal (which
reassigns `taskModalTarget` fresh from the now-current `tasks` prop) ever
picked up the change — which is indistinguishable from "needs a page
refresh" to a user who doesn't know that internal detail.

**Fix — re-sync the stale reference, not a manual refetch.** Added one
watcher in `show.vue`:
```
watch(() => props.tasks, (tasks) => {
    if (taskModalTarget.value === null) return;
    taskModalTarget.value = tasks.find((t) => t.id === taskModalTarget.value!.id) ?? null;
});
```
Whenever Inertia reloads `tasks` for *any* reason (posting a comment,
someone else dragging the task, an edit save), the open modal's task
reference gets re-pointed at the fresh object automatically. This needed
no backend change at all — "if the backend endpoint doesn't return the
created comment, use the smallest appropriate partial reload" turned out
to not apply, since the existing `back()` reload already carries the new
comment; the bug was purely a stale client-side reference.

**Guarding against a second bug this fix could have caused.**
`TaskDetailModal`'s form-reset watcher previously ran on every `task`
prop change (`watch(() => props.task, ...)`). Once `taskModalTarget` can
now silently refresh mid-session, that same watcher would also fire from
a *background* refresh (e.g. posting a comment while the edit form is
open) and wipe out whatever the user was mid-typing in the title/
description fields. Changed the watch key to `props.task?.id` with an
`id === previousId` guard, so form state and `mode` only reset when
actually switching to a *different* task, never when the same task's
data merely refreshes underneath an open modal. Read-only displays
(the column badge, due date) still use `props.task` directly and stay
live either way, since they have nothing to lose by re-rendering.

**Immediate comment appearance.** `TaskCommentThread` now keeps its own
`localComments` ref, resynced via `watch(() => props.comments, ...)`.
Combined with the `show.vue` fix above, a posted comment's visible path
is: POST succeeds → Inertia reloads `tasks` (comment already included) →
`show.vue` re-points `taskModalTarget` → `TaskDetailModal`'s `task` prop
updates → `TaskCommentThread`'s `comments` prop updates → its watcher
updates `localComments` → template re-renders. No fabricated/optimistic
placeholder comment is constructed client-side (which would need a fake
temporary id, a guess at server-formatted timestamps, etc.) — the delay
between "successful POST" and "visible in the thread" is exactly one
network round trip, the same standard every other create action in this
app already meets (creating a task, a meeting, a board column all work
the same way — none of them render an optimistic placeholder either).
- Composer clears via `form.reset()` in `onSuccess` (already existed).
- Duplicate submission is prevented by the existing `form.processing`
  guard inside `submit()`, checked before either the Send button or the
  Enter-key handler can fire a second request — already correct, just
  confirmed it holds under the new flow.
- Added `onError` → `useNotificationStore().error(...)` on both post and
  delete, matching the toast pattern `KanbanBoard.vue` already uses for
  failed drags/reorders. This was missing entirely before.
- After a successful post, the comment list scrolls to its own bottom
  (`scrollTop = scrollHeight` inside `nextTick()`), not the whole page —
  the list has its own bounded, independently-scrollable region (see
  layout notes below), so "scroll to newest" means that region, not the
  document.

**Layout redesign.** Replaced the old split left-details/right-comments
layout (comments were squeezed into a ~300px column) with a single
full-width column: a custom modal header (small "TASK" eyebrow, the
task's own title as the actual heading, status badge, and an "Edit"
button for managers — the modal's built-in close icon covers "close," so
no separate Close button exists in view mode at all), a task-information
block (description, then assignee/due-date/project as a compact 3-column
meta row), then a full-width "Discussion" section below with the
comments feed and composer. Comments render as a vertically-stacked
activity thread — avatar, name, timestamp, body, a bottom-border divider
between entries — not chat bubbles, per the task's own steer, and there's
no existing bubble-style precedent anywhere else in this app that would
argue otherwise. Added a new `2xl` size to `AppModal` (`sm:max-w-4xl`,
896px) — purely additive, every other modal in the app keeps using its
existing `sm`/`md`/`lg`/`xl`. "Project" is shown from a new `projectName`
prop threaded down from `show.vue` (the page already has it); "creator"
was left out — `Task` has no `created_by` column, and adding one wasn't
part of what was asked, so it wasn't invented for this pass.

**What's authorization-unchanged.** No policy, request, controller, or
migration touched — `TaskPolicy`, `TaskCommentPolicy`, `BoardColumnPolicy`
and every route from entries #12/#13 are untouched. This was a pure
frontend UX pass plus the one-watcher backend-adjacent fix described
above (which is also frontend-only — no PHP file changed).

**Explicit scope boundary honored.** No WebSockets/Echo/Reverb, no
mentions, no attachments, no other page touched. Per the task's own
instruction, no code comments were added, and neither the test suite nor
`npm run build`/Pint were run this pass — verification below is manual,
matching what was actually asked for.

### 15. FR21–FR22 — In-app notification center

Read this file, `ResolveMeetingRecipients`/the three Meeting actions
(entry #11), `TaskCommentPolicy`, `Task`/`TaskComment` models, project
membership rules, `HandleInertiaRequests`, and `AppSidebarHeader.vue`
before writing anything, per the task's own instruction. Confirmed first
that no notification infrastructure existed yet: `User` already had
Laravel's `Notifiable` trait (unused), but there was no `notifications`
table, no `Notification` classes, and no bell/feed UI anywhere.

**Storage — Laravel's built-in database notifications, nothing custom.**
Ran `php artisan make:notifications-table` for the standard `notifications`
migration (uuid id, polymorphic `notifiable`, `type`, `data` json, `read_at`,
timestamps) — placed at the migrations root next to `jobs`/`cache`/`users`,
since it's core framework infrastructure, not a module concern. `User`
already had `Notifiable`; zero model changes were needed to get
`->notify()`, `->notifications()`, `->unreadNotifications()` working.

**Six notification classes in `app/Notifications/`** (not module-scoped —
same precedent as `app/Mail/` from entry #11: `MemberInvitationMail` lives
outside any module because mail/notifications are a cross-cutting concern,
not owned by one feature): `MeetingScheduledNotification`,
`MeetingUpdatedNotification`, `MeetingCancelledNotification`,
`TaskAssignedNotification`, `TaskMovedNotification`,
`TaskCommentPostedNotification`. Each implements `ShouldQueue`, declares
`via() => ['database']`, and `toArray()` returns a flat
`{type, title, message, url}` shape — `url` is a fully-built
`route('workspace.projects.show', ...)` string computed by the caller
(the Action already has the project/workspace in scope), so the
notification classes themselves stay dumb data carriers with no routing
knowledge.

**Meetings — reused FR23's recipient resolution and mail dispatch site
exactly, per the task's own instruction to reuse FR23 semantics where
possible.** `CreateMeetingAction`/`UpdateMeetingAction`/
`DeleteMeetingAction` already resolved `$recipients` via
`ResolveMeetingRecipients` and looped `Mail::to(...)->queue(...)` inside a
try/catch; each now also calls `Notification::send($recipients, new
Meeting*Notification(...))` inside that same try/catch, right after the
mail loop. Same recipients, same actor-exclusion, same failure isolation
(`Log::error` on `Throwable`, never bubbles to the HTTP response) — no new
recipient logic for meetings at all.

**Tasks — new `ResolveTaskRecipients`, deliberately narrow.** The task's
own instruction was explicit: *"notify the task assignee and/or task
manager/creator if that information actually exists — do not invent
unsupported ownership fields."* `Task` has no `created_by`/manager column
(confirmed by re-reading the model), so `ResolveTaskRecipients::handle()`
returns **only the current assignee**, and only if they're not the actor
— zero recipients if the task is unassigned or the actor is acting on
their own assignment. This one class is shared by all three task-side
triggers (assignment, move, comment), so "don't duplicate recipient-
selection logic" holds the same way `ResolveMeetingRecipients` already
does for meetings:
- **Task assigned** — `CreateTaskAction` (assignee set at creation) and
  `UpdateTaskAction` (checks `$task->wasChanged('assigned_to')` before
  notifying, so editing other fields without touching the assignee stays
  silent) both gained a `User $actor` parameter, mirroring how
  `UpdateMeetingAction`/`DeleteMeetingAction` already needed the actor in
  entry #11. `TaskController::store`/`update` now pass `$request->user()`
  through — the only controller change this task needed.
- **Task moved** — `UpdateTaskStatusAction` gained the same `User $actor`
  parameter and checks `$task->wasChanged('board_column_id')`, notifying
  the assignee with the new column's name. If the assignee is the one
  dragging their own card (a very common case, since `TaskPolicy::
  updateStatus` explicitly allows the assignee to move their own task),
  `ResolveTaskRecipients` naturally excludes them — no self-notification
  for your own drag-and-drop.
- **Task comment posted** — `CreateTaskCommentAction` already received
  the author; now also notifies the assignee (excluding the author,
  satisfying "do not notify the person who wrote the comment" for free
  since the author *is* the actor argument to `ResolveTaskRecipients`).
  The notification's message includes a `Str::limit($body, 80)` excerpt.
  Because recipients are always "the assignee, if any, minus the actor,"
  **unrelated project/workspace members are structurally never notified**
  — there's no code path that could reach them, not just a filter that
  happens to exclude them.

**In-app notification UI — reused the existing Inertia-shared-props
pattern instead of a new JSON API**, matching entry #13's "this app is
100% Inertia-based" precedent. `HandleInertiaRequests::share()` gained a
`notifications` key (`{ unread_count, recent: [...] }`, latest 10),
computed the same way the existing `workspace` prop already is — a plain
closure re-evaluated on every request, not `Inertia::lazy()`, so the bell
badge is correct on first paint of any page without an extra round trip.
New `NotificationBell.vue` (top-level `resources/js/components/`,
auto-registered like every other component in this app) renders a
`Bell` icon with an unread-count pill in `AppSidebarHeader.vue`
(right-aligned via a new `ml-auto` wrapper — the header previously had
nothing on its right side at all), and a dropdown listing the 10 most
recent items with title/message/relative-timestamp/unread-dot styling.

**Read/unread actions are plain Inertia POSTs with a partial reload, not
a new JSON endpoint.** New `NotificationController@read`/`@readAll`
(`app/Http/Controllers/`, not module-scoped — same reasoning as
`Settings\ProfileController`: this isn't a feature module with its own
actions/policies/migrations, just two thin auth-gated endpoints) return
`back()`. The frontend calls them via `router.post(..., { only:
['notifications'] })`, which re-evaluates just the shared `notifications`
prop instead of reloading the whole page — clicking a notification first
awaits the mark-as-read POST's `onSuccess`, then `router.visit()`s to the
notification's `url`, so the badge is never stale by one click. New
`routes/notifications.php`, required from `routes/web.php` the same way
`settings.php`/`auth.php` already are — plain `auth` middleware, no
workspace prefix, since a notification is scoped to its owning user via
`$request->user()->notifications()->whereKey(...)->firstOrFail()`, not to
whatever workspace happens to be "current" in the URL.

**Security — a user can only ever touch their own notification rows.**
`NotificationController@read` looks the notification up through
`$request->user()->notifications()` (not a bare `DatabaseNotification`
route-model-bind), so requesting another user's notification id 404s
before any read/write happens — verified by a dedicated test. Destination
`url`s are always server-built `route()` calls into routes that are
already policy-protected (`workspace.projects.show`, gated by
`ProjectPolicy::view` + `EnsureWorkspaceMember`), so a notification link
can never leak access beyond what the recipient could already reach by
navigating there directly — no new authorization surface was introduced,
only new *links* into existing protected routes.

**Not built, per the task's explicit exclusions**: no WebSockets/Echo/
broadcasting (the bell only refreshes on the next Inertia
navigation/partial-reload, not live-push), no browser/mobile push, no
notification preferences (still FR25), no mentions, no standalone
notification page (the dropdown feed is the whole UI, per "don't build a
large standalone page unless required" — FR21/FR22's own wording only
asks for a center/feed, which the dropdown satisfies).

**Tests**: extended `MeetingNotificationTest.php` with 4 new
`Notification::fake()` cases (scheduled/updated/cancelled reach the right
project members, actor excluded, link points at the project). New
`tests/Feature/Tasks/TaskNotificationTest.php` (11 tests — assignment on
create and on update, no-assignee/self-assignment send nothing,
unchanged-assignee update sends nothing, column move notifies the
assignee and excludes a self-move, comment notifies the assignee and
excludes the author, unrelated project members never notified, link
target). New `tests/Feature/Notifications/NotificationCenterTest.php`
(6 tests — unread count via the shared prop, notification data/url/read_at
round-trip through the shared prop, mark-one-read, mark-all-read, a user
cannot mark another user's notification as read (404), guests are
redirected to login). Full suite: **249 passed** (up from entry #13's 228
baseline + 21 new tests here).

### 16. FR18–FR19 — Archive and search

Read this file, `ProjectController::index()`/`show()`, `TaskPolicy`/
`MeetingPolicy`, `Task`/`Meeting`/`BoardColumn` models, and the FR21–FR22
notification center (entry #15, the most recent precedent for a
cross-project, workspace-scoped feature) before writing anything.
Confirmed first that no archive table, search infrastructure, or
pagination component existed anywhere in the codebase — this is new
ground on both the backend and frontend.

**No new tables — archive state is entirely derived, per the task's own
instruction.** A "completed task" is a task whose `board_column_id`
points at a column with `is_done = true` (the same flag `TaskFactory::
done()` and the Kanban board already use); a "past meeting" is one whose
`scheduled_at + duration_minutes` has already elapsed (the same rule
`lib/meetings.ts`'s `isPastMeeting()` already uses client-side for the
Upcoming/Past badge from entry #10 — mirrored server-side here). Neither
`Task` nor `Meeting` gained a column, a soft-delete, or an `archived_at`
timestamp. This also means the archive is always consistent with the
live board/meeting list by construction — there's no separate copy that
could drift out of sync.

**One SQL query, not two, per search.** Tasks and meetings are
structurally different tables, but the archive needs to search, filter,
sort, and *paginate* them together as one result set. Rather than running
two separate paginated queries and stitching pages together in PHP (which
breaks page-size guarantees and "keep search server-side, don't filter in
the browser"), `SearchArchiveAction` builds two `DB::table()` query
builders with an identical column shape (`id, type, title, subtitle,
project_id, project_name, assignee_id, assignee_name, occurred_at`),
combines them with `unionAll()`, wraps the union as a subquery via
`DB::query()->fromSub()`, and applies every filter (keyword, assignee,
date range) plus `orderByDesc('occurred_at')` and `->paginate()` on top
of that single combined query. Laravel's paginator works transparently
against a `fromSub()` query exactly like it would against a normal table,
so pagination math (`total`, `last_page`, `links`) is exactly correct
across both record types with no manual page-stitching.

**SQLite-specific date arithmetic — a deliberate, documented compromise,
not an oversight.** "Past meeting" requires comparing `scheduled_at +
duration_minutes` (a value that doesn't exist as a column) against now.
This app's `DB_CONNECTION` is `sqlite` everywhere it's configured
(`.env.example`, `phpunit.xml`, no other driver referenced anywhere in
`config/database.php`'s env override), so `pastMeetingsQuery()` uses
`whereRaw("datetime(scheduled_at, '+' || duration_minutes || ' minutes')
< ?", ...)` — SQLite's `datetime()` function, not portable to MySQL/
Postgres as written. `lib/meetings.ts`'s `isPastMeeting()` solves the
identical problem client-side with plain JS date arithmetic; this is the
first time the same logic has needed to run inside a SQL query, so it's
the first place this portability trade-off actually shows up. Flagged in
Known gaps below so a future driver migration doesn't silently break the
archive.

**Recipient/visibility scoping mirrors `ProjectController::index()`
exactly, not a new access-control model.** `SearchArchiveAction::
accessibleProjects()` is the same rule already used to build the
projects list: workspace Admin+ sees every project; everyone else sees
only projects they have a `project_users` row for. This single method is
reused three times — to scope the actual search query, to build the
"project" filter dropdown's options, and (via its returned project ids)
to build the "assignee" filter dropdown — so there's exactly one place
that decides "which projects can this user search," matching "avoid
duplicating recipient-selection logic" from entries #11/#15.

**Filters cannot bypass scope, by construction rather than by a guard
clause.** If a `project_id` filter is supplied that isn't in the caller's
accessible set, `handle()` intersects it against `accessibleProjects`
before building the query — the resulting id list is empty, `whereIn`
with an empty array is Laravel's documented always-false condition, and
the query returns zero rows through the completely normal pagination
path (no special "403 if you try to peek" branch, no separate empty-
result code path to keep in sync with the real one). Verified by
`test_requesting_an_inaccessible_project_id_returns_no_results` — the
request succeeds (200) and silently returns nothing, the same shape as
"this project has no archived records," rather than leaking that the
project exists via a distinguishable error.

**Assignee filter options come from actual completed tasks, not the full
member roster.** `assigneeOptions()` queries distinct assignees among the
accessible scope's *completed* tasks only — so the dropdown never offers
a name that would filter down to zero results, and needed no separate
membership query.

**Deep-linking a result — reused entry #15's "acceptable" precedent,
then went one step further.** Notification links from entry #15 just
open the project page; this task's own instruction ("clicking a result
should navigate to the relevant accessible resource") plus the fact that
`projects/show.vue` already tracks `taskModalTarget`/`meetingModalTarget`
refs made a proper deep link cheap enough to justify: archive record URLs
are `.../projects/{id}?task={taskId}` or `?meeting={meetingId}`, and a
new `onMounted()` hook in `show.vue` reads that query param once on
page load and opens the matching modal directly (falls back to just
landing on the board/meetings tab if the id isn't found in the loaded
props — e.g. a stale bookmark). No new route, no new modal — this reuses
the exact open functions (`openTaskDetails`, `meetingModalTarget =`)
click-to-open already calls.

**Frontend — new `AppPagination.vue` (first pagination UI in this app)**
in `resources/js/components/ui/`, alongside the other generic list-page
primitives (`AppDataTable`, `AppListToolBar`, `AppSearchInput`,
`AppSegmentedControl`, `AppEmptyState`) it composes with. It takes plain
numbers (`currentPage`/`lastPage`/`total`/`from`/`to`) and emits a page
number on click — no Inertia/paginator-shape knowledge baked in, so nothing
stops it from being reused by a future paginated list. New
`resources/js/pages/archive/index.vue` reuses `AppListToolBar` (search +
the type segmented control) exactly as `projects/index.vue`/`teams/
index.vue` already do, adds project/assignee/date-range filters into its
`#right` slot as plain native selects/date inputs (no new form-input
component needed for a filter bar with no validation state), and renders
results through `AppDataTable` with custom cell slots for the type badge,
title+subtitle, project, assignee, and date — deliberately **not**
passing `sortable: true` on any column, since `AppDataTable`'s sort is
client-side-only and would silently mis-sort a single page of
server-paginated, server-sorted data.

**Search is genuinely server-side, verified by construction.** Every
filter input (`search`, `type`, `project_id`, `assignee_id`, `from`,
`to`) is a local ref that triggers `router.get()` (debounced 350ms for
the free-text field via `@vueuse/core`'s `watchDebounced`, immediate for
the dropdowns/dates) back to `workspace.archive.index` with
`preserveState`/`preserveScroll`. The component never holds the full
archive in memory to filter client-side — `results.data` is read
directly from Inertia props every render, the same "no local mutable
copy that can go stale" principle entry #14 established for
`taskModalTarget`, applied here from the start instead of as a bugfix.

**Tests**: new `tests/Feature/Archive/ArchiveSearchTest.php` (13 tests) —
completed tasks appear, past meetings appear, an in-progress task and an
upcoming meeting are both excluded, keyword search, project filter, date
range filter, assignee filter, an unassigned workspace member sees an
empty archive and an empty project-filter list, a project member sees
only their own project's records, an inaccessible `project_id` filter
returns nothing rather than leaking existence, cross-workspace data never
appears even when both workspaces have identically-named completed
work, a non-member of another workspace gets a 404 (not a 403 — matches
`EnsureWorkspaceMember`'s existing `WorkspaceException::notFound()`
behavior, verified by reading the middleware rather than assuming),
and pagination correctly splits 25 seeded completed tasks into a 20 +
5 page split. Full suite: **262 passed** (249 baseline + 13 new).

**Not built, per the task's explicit exclusions**: no Analytics (FR20),
no AI/transcription work, no Elasticsearch/Meilisearch (query-builder
`LIKE` search only — fine at this data scale, flagged below if that
changes), no realtime updates to the archive list, no code comments.

### 17. FR20 — Analytics

Read this file, `ProjectController::index()`, `SearchArchiveAction` (entry
#16 — the freshest precedent for a cross-project, workspace-scoped,
server-side-aggregated feature), `Task`/`Meeting`/`BoardColumn` models,
`AppStatCard`/`SeatUsageCard`/`AppDataTable`, and `package.json` before
writing anything. Confirmed no chart library is installed anywhere in the
project (`package.json` has zero chart/graph dependencies) and no prior
analytics/aggregation infrastructure exists — this task is new ground,
built entirely on top of already-existing Task/Meeting/Project data.

**Extracted the "which projects can this user see" rule out of Archive
and into the model layer, rather than copying it a third time.** Entry
#16 already had this predicate once (`SearchArchiveAction::
accessibleProjects()`) mirroring `ProjectController::index()`'s inline
version — two copies of the same rule. Analytics needed it as a third
consumer, which was the forcing function to finally centralize it: new
`Workspace::accessibleProjectsFor(User $user): HasMany` (workspace
Admin+ gets every project; everyone else gets only projects they have a
`project_users` row for). `ProjectController::index()` and
`SearchArchiveAction::accessibleProjects()` were both refactored to call
it — behavior-identical (the full existing test suites for both were
re-run and stayed green), just one source of truth now. `Analytics
Controller` is the third and cleanest consumer.

**Extracted "past meeting" out of Archive's raw SQL and into a proper
Eloquent scope, rather than re-copying the SQLite `datetime()` string a
second time.** Entry #16 flagged its own `whereRaw("datetime(scheduled_at,
'+' || duration_minutes || ' minutes') < ?", ...)` as a SQLite-specific
compromise. Analytics needed the identical "has this meeting's end time
passed" predicate for its upcoming/past meeting counts. Rather than
paste the same raw SQL into a second file, added `Meeting::scopePast()`
and `Meeting::scopeUpcoming()` (same whereRaw, same SQLite dependency —
now declared once) and refactored `SearchArchiveAction::
pastMeetingsQuery()` to build off `Meeting::query()->past()->join(...)
->select(...)->toBase()` instead of `DB::table('meetings')->whereRaw(...)`.
`->toBase()` converts the Eloquent builder (with the scope's where clause
already applied) into the plain `Illuminate\Database\Query\Builder` the
UNION machinery needs — same SQL, same result, verified by re-running
`ArchiveSearchTest` unchanged. Also added `Task::scopeOverdue()` (not
extracted from anywhere — this is Analytics' own new predicate: has a
`due_date` in the past, still sitting in a non-`is_done` column, the same
semantics `lib/tasks.ts`'s `isOverdue()` already established client-side)
since "overdue" needed to be both a plain count and, indirectly, a
building block other future features could reuse.

**Metrics computed — all derived from existing columns, nothing
invented.** Task: total, completed, open, completion percentage
(0 when total is 0, never a division-by-zero), overdue, a breakdown by
board column, and a breakdown by assignee (including an explicit
"Unassigned" bucket via `COALESCE` — deliberately included since knowing
how much work has no owner is exactly the kind of "useful metric that
already exists" this task asked for, not an invented one). Meeting:
total, upcoming, past (via the new scopes). Project: total accessible
project count, and a per-project summary (total/completed/completion %)
that includes projects with **zero** tasks — the summary is built by
iterating the accessible-projects list and left-joining in an aggregate
count keyed by `project_id`, not by iterating the aggregate query's
result rows, so a brand-new empty project still shows up as "0 of 0"
rather than silently disappearing from the performance table.

**No N+1 — three raw aggregate queries, everything else is a single
`COUNT`/`whereHas` per metric.** `tasksByColumn()`, `tasksByAssignee()`,
and `projectSummaries()`'s count-map are each one `GROUP BY` query
(`DB::table('tasks')->join(...)->selectRaw(...)->groupBy(...)`) covering
every project in scope at once — none of them loop over projects or
tasks in PHP to build the breakdown. The six simple task/meeting counts
(`total_tasks`, `completed_tasks`, `overdue_tasks`, and the three cloned
meeting counts) are six independent `COUNT` queries, which is the
simplest correct thing to do for six unrelated numbers — combining them
into fewer queries would trade a handful of fast indexed counts for
meaningfully harder-to-read SQL, not a real performance win at this
data scale.

**Tenant/project scope is enforced in the query itself, not filtered
after fetching — same pattern as entry #16.** Every aggregate query
starts from `whereIn('project_id', $scopedProjectIds)`, where
`$scopedProjectIds` is `accessibleProjectIds` intersected with the
optional `project_id` filter (empty intersection → empty `whereIn` →
Laravel's documented always-false condition → zero rows through the
normal query path, not a special-cased empty response). A workspace
Member with zero accessible projects gets an all-zero, valid analytics
page — verified by `test_unassigned_workspace_member_sees_empty_state_
analytics` — not an error, matching Archive's precedent for the same
scenario.

**Date range filter is scoped to meetings only, deliberately, not to
tasks.** The task's own wording hedges this as "date range if
appropriate." Meetings have a real `scheduled_at` timestamp with an
unambiguous meaning; tasks have no `completed_at` column (entry #16
already flagged `updated_at` as an imperfect completion-time proxy), so
applying a date range to "total tasks" or "completion percentage" would
require picking an ambiguous cohort definition (created-in-range? due-in
-range?) that could make those numbers mean something subtly different
from what the stat card's label says. Task metrics always reflect the
*current* board state; only the meeting counts respect `from`/`to`. The
UI says so directly ("Date range applies to meetings only") rather than
leaving it to be discovered.

**No chart library added.** `package.json` has no chart/graph dependency
today, and every visualization this task actually needs — a completion
progress bar, a per-column bar list, a per-assignee bar list, a per-
project mini completion bar — is expressible as a plain `width: N%` div,
the exact technique `SeatUsageCard.vue` (Teams page) already uses in
production. New `AppBarList.vue` (`resources/js/components/ui/`)
generalizes that one pattern (label + proportional bar + count) so both
breakdowns reuse it instead of duplicating the markup twice. Not
reaching for Chart.js/ApexCharts/etc. for four bar-style visualizations
is the "use charts only where they genuinely improve understanding, do
not add a dependency unless necessary" instruction taken literally.

**UI**: new `analytics/index.vue` — a filter bar (project select + date
range, mirroring `archive/index.vue`'s server-side-filter pattern
exactly: local refs, a `watch` that fires `router.get` with
`preserveState`/`preserveScroll`, no client-side recomputation of
anything), six `AppStatCard`s (Projects/Total tasks/Completed/Open/
Overdue/Meetings — the Overdue card switches to an amber icon and a
"down" trend hint when non-zero), a completion card (big percentage +
progress bar + a compact meetings total/upcoming/past row), two
`AppBarList` cards (by column, by assignee — colored green for
`is_done` columns), and a project performance table via the existing
`AppDataTable` with a mini completion bar in its own cell. New
"Analytics" nav entry in `AppSidebar.vue`, positioned between Teams and
Archive.

**Dashboard integration — the one real placeholder, fixed; nothing
else added.** Per the task's own "reuse analytics data where
appropriate, do not redesign the entire dashboard" instruction, the
*only* dashboard change is `DashboardController::onboarding()`'s
`first_project_created` key, which was hardcoded `false` unconditionally
— now `$workspace->projects()->exists()`, a one-line fix using data that
already existed. Deliberately did **not** add a new analytics stat card
to the dashboard: the dashboard is workspace-wide (every member sees the
same team/activity data regardless of project membership), while
analytics numbers must be scoped per-viewer's accessible projects — bolting
a project-scoped number onto an otherwise workspace-wide page felt like
exactly the kind of scope creep "do not redesign the entire dashboard"
was warning against, so it was left alone beyond the one placeholder fix.

**Tests**: new `tests/Feature/Analytics/AnalyticsTest.php` (14 tests) —
task total/completed/open/completion-percentage, tasks-by-column
breakdown and ordering, overdue count (excludes done-column and
future-due-date tasks), tasks-by-assignee including the Unassigned
bucket, meeting total/upcoming/past, the meeting date-range filter,
project filter scoping every metric, an inaccessible `project_id`
returning an empty (not leaked) result, workspace Owner/Admin seeing the
full aggregate, a Project Member seeing only their own project's
numbers, an unassigned workspace member getting all-zero empty-state
analytics, cross-workspace isolation, a 404 (not 403) for a non-member
hitting another workspace's analytics route, and the project summary
correctly including a zero-task project. Full suite: **276 passed**
(262 baseline + 14 new).

**Not built, per the task's explicit exclusions**: no AI-generated
analytics, no forecasting, no sprint velocity (no sprint concept exists
anywhere in the data model — inventing one to compute "velocity" would
have violated "do not implement sprint velocity unless the current data
model genuinely supports sprints"), no realtime broadcasting, no
transcription/AI summary work, no unrelated page redesigns, no code
comments.

### 18. FR25 — Notification preferences

Per-user, per-notification-type/channel opt-out sitting directly on top of
entries #11 (meeting emails) and #15 (in-app notification center) — no new
notification types, no new delivery mechanism, just a gate in front of the
six dispatch points that already exist.

**Storage**: a new `notification_preferences` table
(`user_id`, `type`, `channel`, `enabled`, unique on
`[user_id, type, channel]`) plus a `NotificationPreference` model. Rows are
opt-out, not opt-in: **the absence of a row means enabled**. This was the
only design that satisfies "existing users must keep current behavior by
default" for free — no backfill migration was needed, and a user who never
opens the settings page behaves identically to before this FR existed.

**`App\Notifications\NotificationType`** and **`NotificationChannel`** are
new backed enums (placed in `app/Notifications`, alongside the existing
non-modular notification classes — this codebase already keeps
cross-workspace notification infrastructure outside `app/Modules`, e.g.
`NotificationController`, `routes/notifications.php`, the `notifications`
migration itself, so preferences followed the same placement rather than
inventing a new `Modules/Notifications` directory for one feature).
`NotificationType` is the single source of truth for the six existing
type strings (`meeting_scheduled`, `meeting_updated`, `meeting_cancelled`,
`task_assigned`, `task_moved`, `task_comment` — matching the literal
strings already hardcoded in each `Notification` class's `toArray()`,
verified against entry #15/#23's classes rather than assumed), which group
(`Meetings` vs `Tasks`) they belong to, and — critically — which channels
each one supports: the three meeting types support `IN_APP` and `EMAIL`;
the three task types support `IN_APP` only, because no task notification
has ever had an email channel (confirmed in `CreateTaskAction`/
`UpdateTaskAction`/`UpdateTaskStatusAction`/`CreateTaskCommentAction` before
writing anything — they call `Notification::send()` only, never `Mail::`).
This is what "only show channel toggles that actually exist" and "do not
create preferences for features/channels that do not exist yet" turn into
in code: the settings UI and the server-side validation both derive their
allowed channel list from `NotificationType::channels()`, so there is no
path to ever create a `task_assigned` + `email` row.

**Enforcement — one reusable gate, not seven copies of the same check**:
`App\Notifications\NotificationPreferenceGate::filter(Collection $recipients, NotificationType $type, NotificationChannel $channel): Collection`
takes the recipients a `ResolveMeetingRecipients`/`ResolveTaskRecipients`
call already resolved and rejects whoever has an explicit disabled row for
that exact type/channel pair (one `whereIn` + `pluck` query, not N
per-recipient queries). This keeps "who is eligible to be notified"
(project membership, actor exclusion — unchanged, still `ResolveXRecipients`)
and "does this eligible person want this notification" (the new gate) as
genuinely separate concerns, per the task's explicit instruction. Each of
the three meeting Actions (`CreateMeetingAction`, `UpdateMeetingAction`,
`DeleteMeetingAction`) now calls the gate twice — once for the `EMAIL`
channel immediately before the `Mail::to()->queue()` loop, once for
`IN_APP` immediately before `Notification::send()` — so a user can disable
the email and keep the in-app notification, or vice versa, independently.
Each of the four task Actions calls it once, for `IN_APP` only, since that
channel is all that exists there. All seven call sites are a one-line
`$recipients = $this->preferences->filter($recipients, NotificationType::X, NotificationChannel::Y);`
injected via the constructor, matching how `ResolveMeetingRecipients`/
`ResolveTaskRecipients` were already injected — no duplicated preference
logic, no changes to `ResolveMeetingRecipients`/`ResolveTaskRecipients`
themselves.

**Settings UI**: `settings/Notifications.vue`, added as a fourth entry in
`SettingsLayout`'s sidebar nav (Profile / Password / Notifications /
Appearance), following the exact same non-workspace-scoped `AppLayout` +
`SettingsLayout` shell as `Profile.vue`/`Password.vue` — this is global,
per-user account settings, not workspace-scoped, so it deliberately does
not live under `TenantRoute::prefixed()` like every Module route does.
`NotificationPreferenceController::edit()` builds a `Meetings`/`Tasks`
grouped array (type → label → its supported channels → each channel's
current enabled state, defaulting missing rows to `true`) entirely
server-side from `NotificationType::values()`, so the frontend never has
to know the type/channel matrix itself — it just renders whatever
structure the backend sends. The page renders one `Checkbox` per
type/channel pair (reusing the existing `ui/checkbox` component — no new
dependency, same component already used on the login page's "remember
me") and submits the full flattened preference list in one `PUT`.
Save feedback reuses `Profile.vue`'s exact pattern: a disabled-while-
submitting `Save` button plus a `TransitionRoot`-faded "Saved." message on
`form.recentlySuccessful`; a validation-error banner covers the failure
case.

**Security (FR25 requirement 5)**: the controller only ever reads/writes
`$request->user()`'s own rows — there is no route parameter or request
field that names a target user, so there is no code path through which a
workspace admin, project manager, or any other user could reach someone
else's preferences. No policy class was needed for the same reason
`ProfileController` has none: the "own resource only" guarantee comes from
never accepting a foreign identifier in the first place, not from an
authorization check that could be forgotten.

**Tests**: new `tests/Feature/Notifications/NotificationPreferenceTest.php`
(10 tests) — defaults are enabled for every current type/channel, a user
can disable an in-app type and it persists, a disabled task-assignment
notification is neither dispatched nor written to the `notifications`
table (asserted via a real `assertDatabaseCount('notifications', 0)`, not
just a fake-assertion), a different still-enabled type keeps working
alongside a disabled one, a meeting's email can be disabled independently
of its in-app notification and vice versa, preferences set by one user
never affect another user's dispatch, a user's `PUT` never creates or
touches another user's rows, guests are redirected to login on both
routes, and the server rejects a type/channel combination the type
doesn't support (`task_assigned` + `email`) with a validation error rather
than silently accepting it. Re-ran `MeetingNotificationTest.php`,
`TaskNotificationTest.php`, and `NotificationCenterTest.php` in full after
wiring the gate into all seven dispatch points — all 31 stayed green
unchanged, confirming the default-enabled behavior really is
behavior-identical to before this FR. Full suite: **286 passed** (276
baseline + 10 new).

**Not built, per the task's explicit exclusions**: no push notifications,
no browser notifications, no realtime broadcasting, no digest scheduling,
no mentions, no AI/transcription work, no code comments.

### 19. FR26 — Profile picture

Per-user avatar upload/replace/remove, wired into the existing shared
`AppAvatar` component's initials-fallback path rather than building a new
display primitive.

**Storage**: a nullable `avatar_path` string column on `users` (the
stored reference — never the binary itself) plus a `User::avatarUrl()`
accessor (`Illuminate\Database\Eloquent\Casts\Attribute`, appended via
`$appends`) that turns it into a public URL through
`Storage::disk('public')->url($path)`, or `null` when no avatar exists.
The `local` disk stayed the app's default (`FILESYSTEM_DISK=local` in
`.env.example`, unchanged), but avatars explicitly use the already-
configured `public` disk (`config/filesystems.php` already had it wired
with a `url` callback and a `public_path('storage') → storage_path('app/
public')` symlink entry in `links`) since a profile picture has to be
browser-viewable, unlike this app's other private storage needs. Ran
`php artisan storage:link` once as part of this work — the symlink didn't
exist yet in this environment, and `Storage::disk('public')->url()`
depends on it for real (non-faked) requests.

**Safe unique filenames**: `$request->file('avatar')->store('avatars', 'public')`
— Laravel generates the stored filename from a random unique ID plus an
extension inferred from the *actual* MIME type, never from the client-
supplied filename. Combined with `File::image()` validation (which
inspects real file content, not the extension) in the new
`AvatarUpdateRequest`, this is what satisfies "do not trust filename or
extension alone." `avatar_path` is never mass-assigned from request
input — the controller always calls `$user->forceFill(['avatar_path' =>
$path])->save()` with `$path` being Laravel's own generated storage path,
never anything read out of the request — so there is no way for a client
to make `avatar_path` point at an arbitrary filesystem location.

**Validation**: `File::image()->max(2 * 1024)` — real image content only
(Laravel's `image` rule checks MIME/content, and — per its own
documented default — rejects SVG uploads to avoid stored-XSS via SVG
`<script>`/event-handler payloads, which is exactly the "sensible" side
of "sensible file-size limit" for this threat model too), capped at 2 MB.

**New `Settings\AvatarController`** (`store()`/`destroy()`), mirroring
how `PasswordController` and (per entry #18) `NotificationPreferenceController`
are each a dedicated controller for one distinct settings concern rather
than growing `ProfileController` — Avatar is a distinct resource from
Profile info even though both render on the same settings page.
`store()` uploads/replaces: the new file is stored first, the user's
`avatar_path` is updated, and *only then* is the previous file (if any)
deleted from the `public` disk — matching the requirement's explicit
ordering ("delete the old stored file after the new upload succeeds"),
so a failed upload can never destroy a working avatar. `destroy()`
deletes the stored file and clears `avatar_path` in one step, and is a
safe no-op when the user has no avatar (no error, nothing to orphan).
Both methods only ever act on `$request->user()` — there is no route
parameter or request field naming a target user, so (as with FR25) there
is no code path through which one user could reach another's avatar; no
policy class was needed for the same reason.

**Wiring `avatar_url` into existing displays, not patching each screen**:
per the task's "update all existing places automatically where
practical" instruction, this only touched the places that *already* had
`avatar_url` plumbed end-to-end in their Vue templates but the PHP side
hardcoded it to `null` — `DashboardController::members()`/`activity()`
(4 spots) and `TeamRoster::forWorkspace()` (1 spot, the second `null` there
is for pending invitations, which have no `User` yet and correctly stay
`null`) — now select `users.avatar_path` and return the real
`$member->avatar_url`. Those screens (Dashboard member list, Dashboard
activity feed, Team roster) picked up real avatars with zero Vue changes.
`UserInfo.vue` (used by both the sidebar-footer `NavUser` and the header
dropdown `UserMenuContent`) was switched from the starter kit's dead,
never-wired `user.avatar` field and raw `Avatar`/`AvatarImage`/
`AvatarFallback` primitives to the same `AppAvatar` component every other
screen in the app already uses — one file change, both places update.
**Deliberately left untouched**: task assignee avatars (`TaskCard`,
`TaskDetailModal`, `AssigneePicker`), task comment avatars
(`TaskCommentThread`, `TaskCommentComposer`), and project member avatars
(`projects/show.vue`, `WorkspaceMemberPicker`) — none of these currently
pass an `avatar_url` prop at all; wiring them up would mean extending
several more Data classes/inline arrays (`TaskData`, `TaskCommentData`,
project member payloads) and editing 6+ additional Vue files, which is
building new plumbing rather than flipping on plumbing that already
exists — judged out of scope for "where practical" and flagged below as
a known gap instead of quietly expanding this task's surface.
`resources/js/components/AppHeader.vue` also still references the dead
`user.avatar` field, but that component is unreachable dead code (never
imported by any active layout — confirmed via search), so it was left
alone rather than "fixed" for something nothing renders.

`AppAvatar.vue` gained one new size token, `2xl` (`size-20`), for the
settings-page preview — a config-driven extension of the size map the
component already exposes, not a new component or a one-off style
override in the settings page.

**Bug found and fixed along the way**: adding `avatar_path` immediately
broke `ProfileUpdateTest.php`, `DashboardTest.php`, and
`TeamMemberTest.php` with `MissingAttributeException`. Root cause:
`AppServiceProvider` already calls
`Model::preventAccessingMissingAttributes(! $this->app->isProduction())`,
and `UserFactory::definition()` didn't set `avatar_path` — so a
factory-built model handed straight to `actingAs()` (never re-queried
from the database) simply didn't have the attribute in memory, and the
new `avatar_url` accessor (evaluated on every request via `$appends`,
because `auth.user` is serialized on every Inertia response) tripped the
strict-mode guard on first touch. Fixed by adding `'avatar_path' => null`
to `UserFactory::definition()`, following the exact precedent already set
by that same factory's `'current_workspace_id' => null,` line. Also added
a `withAvatar()` factory state for tests that need a pre-existing avatar.
This is a general lesson worth remembering for any future nullable
column that gets an appended accessor: it must be added to
`UserFactory::definition()` in the same change, or every
`actingAs(User::factory()->create())` test that touches an
accessor-bearing page breaks.

**Tests**: new `tests/Feature/Settings/AvatarTest.php` (12 tests) — valid
upload persists a `avatars/…` path and a real file on the faked `public`
disk, the URL is exposed through `auth.user.avatar_url` on the Inertia
shared prop, replacing an avatar deletes the old file and stores the new
one (asserted both ways: `assertMissing`/`assertExists`), removing an
avatar deletes the file and nulls the reference, removing when no avatar
exists is a safe no-op, a non-image file is rejected, a `.jpg`-named file
with non-image content is rejected (proving the validator inspects
content, not the extension), an oversized (3 MB) image is rejected, one
user's upload/removal never touches another user's `avatar_path` or
file, initials fallback (`auth.user.avatar_url === null`) still works
with no avatar, and guests are redirected to login on both routes. Also
re-ran `ProfileUpdateTest.php`, `DashboardTest.php`, and
`TeamMemberTest.php` in full after the factory fix — all green. Full
suite: **298 passed** (286 baseline + 12 new).

**Not built, per the task's explicit exclusions**: no image cropping
library, no external image hosting, no S3-specific logic (the app has no
S3 credentials configured — `public` local disk matches "based on
existing app conventions"), no Audit Log or AI work, no code comments.

### 20. FR28 — Audit log

A persistent, queryable record of who did what across every module —
Workspace, Teams, Projects, Tasks, Meetings — built as a new
`App\Modules\Audit` module rather than bolted onto the existing "activity"
feed (`DashboardController::activity()`, entry #6's ad-hoc `workspace.
created`/`member.joined`/`member.invited` timeline), which is a live,
un-persisted, workspace-home-page-only query with no storage, no
filtering, and no authorization tier beyond "workspace member" — a
fundamentally different feature from a durable audit trail with
project-scoped visibility rules.

**Storage — `audit_logs`**: `workspace_id`, `project_id` (nullable),
`user_id` (nullable), `action`, `subject_type`/`subject_id`
(polymorphic-style, mirroring the existing `notifications` table's
`notifiable_type`/`notifiable_id` convention), a pre-rendered
`description`, a `metadata` JSON column, and `created_at` only (no
`updated_at` — `const UPDATED_AT = null;` on the model — an audit row is
immutable by definition, so a silently-unused `updated_at` column would
misrepresent that). **Deliberately no foreign-key constraints** on
`workspace_id`/`project_id`/`user_id`/`subject_id` — this is the direct
answer to "deleted where technically possible to preserve safely":
`DeleteWorkspaceAction`, `DeleteProjectAction`, `DeleteTaskAction`, and
account deletion (`ProfileController::destroy`) all hard-delete rows, and
a `cascadeOnDelete()` FK would silently erase the very audit history
those deletions are supposed to be recorded in. Eloquent `belongsTo`
relations still exist on `AuditLog` for convenience eager-loading
(`user`, `project`, `workspace`) — Eloquent relations don't require a
DB-level constraint to function, only a column-name convention.

**Pre-rendered `description`, not template+params re-rendered at read
time**: every write call composes its own final sentence
(`"{$actor->name} moved \"{$task->title}\" from {$old} to {$new}."`) using
values it already has in scope, the same convention every `Notification`
class in `app/Notifications` already uses for its stored `message`
string. This is what keeps a description legible forever regardless of
whether the referenced task/project/user still exists — the task's title
at the moment of the move is baked into the sentence, not looked up live
from a `subject_id` that might 404. `metadata` carries only small
structural facts (`changed_fields`, `ordered_column_ids`) for potential
future programmatic use — never full free-text field values (a task's
`description`/comment body is never copied into `metadata`), satisfying
"do not store unnecessary sensitive information" without needing a
denylist.

**One reusable logger, ~21 call sites, never a raw `AuditLog::create()`
outside it**: `App\Modules\Audit\Actions\RecordAuditLogAction::handle()`
takes `(Workspace, ?Project, ?User $actor, AuditAction, string
$description, ?Model $subject, array $metadata)` and is injected into
every domain Action that mutates something worth auditing —
`Create/Update/DeleteWorkspaceAction`, `CreateWorkspaceInvitationAction`,
`Remove/UpdateWorkspaceMemberRoleAction` (Teams),
`Create/Update/DeleteProjectAction` +
`Add/Remove/UpdateProjectMemberRoleAction` (Projects),
`Create/Update/UpdateStatus/DeleteTaskAction` +
`Create/Delete/ReorderBoardColumnAction` (Tasks), and
`Create/Update/DeleteMeetingAction` (Meetings). It wraps its own write in
`try/catch (Throwable)` and only `Log::error()`s on failure — mirroring
the exact pattern every `notify()` method in Meetings/Tasks Actions
already uses — so a broken audit write can never fail the actual
mutation, per the task's explicit "audit failure should not corrupt the
primary business action" instruction. Several Actions
(`UpdateWorkspaceMemberRoleAction`, `UpdateProjectAction`,
`DeleteProjectAction`, `Add/Remove/UpdateProjectMemberRoleAction`,
`DeleteTaskAction`, `Create/Delete/ReorderBoardColumnAction`) didn't
previously receive an actor at all and needed a new `User $actor`
parameter threaded through from their controllers — a mechanical but
necessary change, verified safe by grepping the test suite first for any
direct (non-HTTP) instantiation of these Actions (found none — every
existing test exercises them through routes, so the new required
parameter couldn't silently break a call site).

**Independent of the notification recipients-empty early return.** Every
Meetings Action already had a `notify()` step that returns early when
`ResolveMeetingRecipients` finds nobody to tell (e.g. a solo-member
project, or everyone having disabled that channel per FR25). The audit
call was placed in `handle()`, before/independent of `notify()` — so
"a meeting was scheduled" is recorded even when zero people get notified
about it. Getting this wrong would have made the audit trail silently
incomplete for exactly the workspaces where FR25 preferences matter most.

**`task.assigned` is deliberately a separate action from `task.created`
and `task.updated`**, matching the task's own explicit event list.
Creating a task with an initial assignee logs both `task.created` and
`task.assigned`; editing a task's title/description/due-date logs
`task.updated`; changing only the assignee logs `task.assigned` alone —
verified by a dedicated test that a title-only edit never produces a
stray `task.assigned` row and an assignee-only change never produces a
stray `task.updated` row.

**Authorization — chained, never project-role-alone**:
`AuditLogPolicy::viewAny(User, Workspace)` grants access if the user is
Workspace Owner/Admin (sees everything, including workspace-level
entries with `project_id = null` — invitations, role changes, workspace
renames/deletion) **or** manages at least one project
(`Workspace::managedProjectsFor()`, a new method built the same way as
entry #17/#20's `accessibleProjectsFor()` but scoped to `Project::
managers()` instead of `members()` — reusing existing infra rather than
duplicating the membership check). A Project Manager's query is then
further restricted in `SearchAuditLogAction` to `project_id IN (their
managed project ids)` only — they never see workspace-level entries, even
though `viewAny` let them through the door. A plain Project Member or an
unrelated workspace Member gets a `403` (they passed
`EnsureWorkspaceMember`, so they're a real member, just not one with
audit privilege). A non-member of the workspace gets Laravel's own
`404` via the existing `EnsureWorkspaceMember` middleware, before the
controller or policy ever runs. This is the same three-tier chain
(workspace access → project access → feature visibility) already
established for Analytics/Archive, with one addition: Analytics/Archive
grant every workspace member *some* view (scoped to their own projects);
Audit Log is the first feature in this app where a workspace member can
be shown nothing at all.

**UI**: a new "Audit Log" card in the Workspace Settings hub
(`workspace/settings/index.vue`), shown only when `canViewAuditLog` is
true — not a main-sidebar entry like Analytics/Archive, since those are
"scoped for everyone," while this feature should not visibly advertise
its own existence to members who aren't allowed to see it. `audit/
index.vue` follows Archive's exact filter-bar-plus-`AppDataTable`-plus-
`AppPagination` shape: category (one of the 5 fixed buckets, not all 23
raw action strings — kept the filter dropdown scannable), actor, project
(hidden entirely for a Project Manager, since they only ever see their
own managed projects anyway), and a date range, all server-side via
`router.get(..., {preserveState, preserveScroll, replace})`. The
`AuditLogEntryData` DTO sent to the frontend never includes `subject_type`,
`subject_id`, or raw `metadata` — only `description` (already a finished
sentence), an `action_label`, and a `category` — so "do not expose raw
model class names or raw JSON to normal users" is enforced structurally
by what the payload contains, not by frontend discipline.

**Module-boundary lesson (new, worth remembering)**: this codebase has a
pre-existing `tests/Unit/ModuleBoundaryTest.php` that enforces modules
may only import each other's `Contracts`, `Data`, `Models`, `Actions`,
`Policies`, or `Exceptions` — a class sitting bare at a module's root
(no subfolder) is invisible to that check's public-surface allowlist.
The first draft of `AuditAction` lived at `App\Modules\Audit\AuditAction`
and broke this test the moment any other module imported it (21 import
sites at once). Moved to `App\Modules\Audit\Data\AuditAction` — fixed
with a namespace-only change since enums have no other module-boundary
implications. Lesson for any future module enum meant for cross-module
use: put it in `Data/` from the start.

**Tests**: `tests/Feature/Audit/AuditLoggingTest.php` (9 tests, write
side) — task create/update/move/assign/delete each produce the right
`AuditAction`, an assignee-only change never also logs `task.updated`
and vice versa, the exact `"{actor} moved \"{title}\" from {old} to
{new}."` message shape, meeting scheduled/updated/cancelled, project
create/update/delete, project member added/removed/role-changed
(matching the task's own "Zafar assigned Ahmed as Project Manager"
example almost verbatim), workspace member removed/role-changed, correct
`workspace_id`/`project_id`/`user_id` on every row, and metadata proven
to never contain a task's free-text description even when only that
field changed underneath an unrelated edit.
`tests/Feature/Audit/AuditLogViewTest.php` (10 tests, read side) —
Owner sees all 3 seeded entries including the workspace-level one,
Project Manager sees exactly the 1 entry scoped to their managed
project, a plain project member and a member with no managed projects
are both `403`, a non-member of the workspace is `404`, a second
workspace's audit rows never appear (cross-workspace isolation),
filtering by category/project/actor/date range each correctly narrows
the result set. Full suite: **317 passed** (298 baseline + 9 + 10 new).

### 21. Re-audit of the Partial FRs (2026-08-17) — no code changed

Re-audited the nine FRs the table listed as Partial against the actual
working tree, not against this log's own prior claims. Full suite re-run
first as a baseline: **317 passed, 1423 assertions, 0 failures**.

**Blocking limitation, recorded so it isn't rediscovered later.** The
source of truth this file names in its own header — `SprintSync FYP1
Report.docx` — is **not in the repository and not anywhere on the dev
machine** (verified by a filesystem-wide `find` for `*SprintSync*FYP*`
and for every `.docx`/`.pdf` under the usual document folders; also not
in git history, and no FR wording exists in `README.md`, `AGENTS.md`,
`CLAUDE.md`, `docs/MODULE_GUIDE.md`, or `.cursor`/`.claude`). Only three
FRs could therefore be graded against a known requirement — FR30, FR34
and FR35, whose scope was supplied directly in the audit request. FR02,
FR03, FR04, FR27, FR31 and FR33 were **not** re-graded, because every FR
number in this file is a bare label with no accompanying text; guessing
their scope would produce a status that looks authoritative and isn't.
Their entries are carried forward from entry #1 marked unverified.

**FR30 — workspace rename / delete / member management: remains Partial.**
Rename and delete are genuinely complete end to end (`WorkspaceController::
update`/`destroy`, `UpdateWorkspaceRequest` with slug uniqueness +
`WorkspaceSlug`, `DeleteWorkspaceAction` with a last-workspace guard, a
`current_workspace_id` re-point for every affected user, and an audit-log
write, plus `RenameWorkspaceModal` and a type-the-name-to-confirm
`DeleteWorkspaceDialog`). Member management is complete on the Team page
(`workspace.members.update`/`destroy`, owner-immutability and
self-removal guards, audit writes, 8 passing tests). What is missing:
- **No test at all covers `workspace.update` or `workspace.destroy`** —
  the only related test is `test_outsider_cannot_view_workspace_settings`.
  Rename, delete, the only-workspace guard, and the non-Owner denial are
  all unproven.
- **`WorkspaceController::edit` performs no authorization** beyond
  `EnsureWorkspaceMember`, and the settings page ships `canViewAuditLog`
  as its *only* permission prop. A plain Member sees, and can click,
  both "Rename workspace" and Danger Zone → "Delete workspace"; the
  request then 403s. Same "policy exists but the page doesn't consult it"
  shape as the `ProjectController::show` gap found in entry #8.
- The settings hub's **Members and Invitations cards are still badged
  "Soon"** even though both features work elsewhere in the app.
- On the Team page, **`onResendInvite`, `onRevokeInvite`,
  `onCopyInviteLink` and `onTransferTasks` are `console.log` stubs.**
  `workspace.invitations.resend`/`destroy` exist and are authorized; only
  the wiring is absent. Note the roster emits `id` as
  `"invitation-{id}"` and carries the real id separately as
  `invitation_id`, so wiring these must use `invitation_id`.
- Seat usage is hardcoded to `total = 10`; the roster's `status` is
  hardcoded `'active'`/`'pending'` while the filter bar offers a
  **"Suspended" tab for a state nothing can produce**; `AppAiInsight` on
  that page renders a fabricated statistic about a named user.

**FR34 — role assignment UI: reclassified Partial → Broken.**
The backend is sound (`WorkspaceRoleController`, both FormRequests
authorizing via `manageRoles`/`WorkspaceRolePolicy`, unknown-permission
rejection, name-collision and reserved-name rules, 8 passing tests). The
UI is not:
- **`RoleManagement.vue` calls `workspaceRoute('workspace.roles.update',
  selectedRole.value.id)` and the same for `destroy`** — a bare number
  where a params object is required. `workspaceRoute()` does
  `{...params, workspace}`; spreading a number yields `{}`, so the
  required `{role}` segment is dropped and Ziggy v2 throws. **Save
  changes and Delete role do not work.** This is the exact bug class of
  fixes #2 and #4, and was already listed under Known gaps as unfixed —
  confirmed still unfixed.
- **`customRoles` is a local `ref` seeded once from `props.roles` and
  never re-synced**, so creating, saving or deleting a role does not
  update the list (same stale-prop-reference class as entry #14).
- **The controller passes `canManageRoles`; the component never declares
  or uses it**, and the page itself has no authorization check — a plain
  Member can open Role Management and sees New role / Save / Delete.
- The page renders **four hardcoded "System roles" with fabricated
  member counts** (Owner 1, Admin 3, Member 12, Viewer 5) — including a
  **`viewer` role that does not exist in `App\UserRole`** — plus a
  hardcoded Developer/Designer fallback for `props.roles`.
- **No UI assigns a custom role to a member.** `UpdateTeamMemberRequest`
  accepts and validates `workspace_role_id` and
  `UpdateWorkspaceMemberRoleAction` persists it, but
  `ChangeMemberRoleModal` submits only `{ role }`, and `TeamRoster`
  never returns the custom role, so it could not be displayed either.
  Custom roles can be created but never actually worn by anyone.
- **`WorkspacePermission` is decorative.** `WorkspaceRole::grants()` is
  called from nothing but tests, and no policy anywhere consults a
  custom role's permission map — the toggles persist and are then
  ignored by every authorization decision in the app.

**FR35 — project membership & access rules: remains Partial.**
The access model itself is complete and well covered: `project_users` +
`App\ProjectRole`, `ProjectPolicy`/`TaskPolicy`/`MeetingPolicy` all on
the `workspace admin-rank OR project role-rank` model, `ProjectMember
Controller` with 19 passing tests, `Workspace::accessibleProjectsFor()`
as the single visibility predicate reused by Projects, Archive and
Analytics, and `AuditLogPolicy` correctly admitting project Managers.
Archive and Analytics scope their data per viewer; Audit is policy-gated
and hidden from the settings hub when not permitted. What is missing is
the **UI shell**, not the rules:
- **`AppSidebar` is a static list with no permission gating** — every
  workspace member sees Dashboard, Projects, Teams, Analytics, Archive.
  Analytics/Archive are safe (they scope to zero rows for an unassigned
  member) but present as empty pages rather than being hidden.
- The **"Workspace settings" entry in `WorkspaceSwitcher` is ungated**,
  which is what exposes the FR30 problem above.
- **`ProjectController::show` sends `workspaceMembers` — the whole
  workspace roster's id/name/email — to every viewer**, including a
  plain project Member who cannot manage members. The Add-member modal
  is UI-gated on `canManageProjectMembers`, but the payload is not.
- The sidebar footer still links to the Laravel starter-kit GitHub and
  docs.

### 22. FR34 — role assignment UI repaired (Broken → Partial)

Fixed every defect entry #21 listed for FR34 except permission
enforcement, which is deliberately left open (see the end of this entry).
No policy, migration or route changed — the backend was already correct;
this was a UI-layer repair plus two read-side additions.

**The dead buttons.** `RoleManagement.vue` now calls
`workspaceRoute('workspace.roles.update', { role: role.id })` — a params
object, not a bare number — so the `{role}` segment survives and Ziggy
resolves. Delete moved into a new `DeleteWorkspaceRoleDialog.vue`
(`workspace/popups/`, mirroring `DeleteWorkspaceDialog`) which builds the
same params object. Deleting a role unassigns every member holding it
(`DeleteWorkspaceRoleAction`), so it now requires a confirmation step and
the dialog states how many members are affected and that they keep their
workspace access — previously this was a one-click, unconfirmed action.

**The stale list.** `customRoles` (a `ref` seeded once from props) is gone;
the list is a `computed` over `props.roles`, so create/save/delete are
reflected immediately after Inertia reloads. Selection is tracked by id
with a `watch` that re-points to the first role when the selected one
disappears. `CreateWorkspaceRoleModal` now emits the slug it submitted, and
the page selects the newly created role once it appears in the refreshed
props — so "Create & configure" actually lands on the new role. The edit
form is an Inertia `useForm`, reset only when the *selected role id*
changes (entry #14's lesson), so a background prop refresh cannot wipe
in-progress edits; `onSuccess: () => form.defaults()` clears the dirty
state after a save.

**The fabricated data.** The four hardcoded "System roles" — including a
`viewer` role that does not exist in `App\UserRole` — and their invented
member counts are deleted, as is the hardcoded Developer/Designer
fallback for `props.roles`. `WorkspaceRoleController::index` now sends a
`systemRoles` prop built from `UserRole::cases()` with real counts from a
single grouped query over `workspace_users`. Role copy moved to a new
`UserRole::description()` so it lives next to `label()` rather than in the
template.

**The silent no-op nobody had noticed.** The edit panel offered an
editable "Identifier / Slug" input, but `UpdateWorkspaceRoleRequest`
never accepted `slug` — it reuses `$this->role()->slug`. Typing in that
field did nothing. It is now rendered as read-only text with a note that
the identifier is fixed at creation.

**Permission gating.** The controller already sent `canManageRoles` and
the component ignored it. It is now declared and honored: New role, Save,
Delete and Toggle-all are hidden for non-admins, and the permission
switches render disabled. Viewing the page stays open to any workspace
member, matching `WorkspaceRolePolicy::view` (`hasMember`) — the page is a
read of workspace structure, and every mutating route was already
authorized server-side. The permission checkboxes were also swapped for
the shared `AppSwitch`, and the permission list is now filtered against
the `availablePermissions` prop so the UI cannot drift from
`WorkspacePermission`.

**Custom roles can now actually be worn.** This was the FR's headline gap:
roles could be created but never assigned. Three additions closed it —
`TeamRoster` returns `workspace_role_id` and `workspace_role_name` per
member (resolved from one `pluck('name', 'id')` over the workspace's
roles, `null` for pending invitations); `TeamMemberController::index`
sends a `workspaceRoles` list; and `ChangeMemberRoleModal` gained a custom
role picker that submits `workspace_role_id` alongside `role`. The Team
page's role column renders the custom role under the system badge, and
the search box matches on it. `UpdateTeamMemberRequest` and
`UpdateWorkspaceMemberRoleAction` needed no change — they already
validated the id against the workspace and persisted it; nothing had ever
sent the field.

**Tests**: 5 new, all passing. `WorkspaceRoleTest` gained
`test_the_index_page_reports_real_system_role_counts_and_the_manage_flag`
and `test_a_plain_member_cannot_manage_roles` (index exposes
`canManageRoles: false`, and store/update/destroy are each `403`).
`TeamMemberTest` gained
`test_an_admin_can_assign_a_custom_workspace_role_to_a_member`,
`test_a_custom_role_from_another_workspace_cannot_be_assigned` (asserts
the validation error *and* that the pivot is untouched), and
`test_the_roster_exposes_the_assigned_custom_role_and_the_available_roles`.
Full suite: **322 passed** (317 baseline + 5). Pint, ESLint and
`vue-tsc --noEmit` all clean.

**Deliberately not done — the open decision.** `WorkspacePermission` is
still enforced by nothing: `WorkspaceRole::grants()` is called only from
tests, and every policy still authorizes on `UserRole` rank plus project
membership. Making the permission matrix authoritative is a genuine
redesign — every policy would need a precedence rule for system rank
versus custom-role permission — and it is not obviously what the FR asks
for. Until that is decided, the permissions panel carries an inline note
saying permissions are recorded for team structure while access is
enforced by the system role and project membership, so the page no longer
implies an enforcement that does not exist. Resolving this one way or the
other is what moves FR34 from Partial to Complete.

### 23. FR30 — workspace rename / delete / member management completed

Closed every defect entry #21 listed for FR30. Writing the missing tests
first turned up a real bug and one wrong assumption about existing
behaviour, both described below.

**The missing tests — and the bug they found.** `workspace.update` and
`workspace.destroy` had *zero* coverage. New
`tests/Feature/Workspace/WorkspaceSettingsTest.php` (13 tests) covers
rename, slug uniqueness against another workspace, keeping your own slug,
delete with a remaining workspace, the current-workspace re-point for
affected members, the only-workspace guard, and per-role denials for
Admin and Member on both routes.

The delete test failed on first run: after deleting a workspace the owner
was redirected to **`login`**, not to a remaining workspace.
`WorkspaceController::destroy` resolved its fallback purely from
`$user->currentWorkspace`, and `DeleteWorkspaceAction` only re-points
users whose `current_workspace_id` *equalled the deleted workspace* — so
anyone whose current workspace was already `null` fell through to the
"you have nowhere to go" branch and got bounced to the login page while
still authenticated and still owning other workspaces. That is the same
bug class as fixes #2 and #4. `destroy` now falls back to
`WorkspaceService::currentFor($user) ?? $user->workspaces()->first()` and
persists the switch via `switchTo()` before redirecting, so the user
always lands on a workspace they actually belong to.

The only-workspace guard also behaves differently than assumed: it throws
`WorkspaceException::cannotDeleteOnlyWorkspace()` (an `AppException`,
422), which `ErrorResponseBuilder` turns into a `back()->with('error')`
toast for Inertia requests and a plain 422 otherwise. Both paths are now
asserted rather than guessed at.

**Permission gating on the settings hub.** `WorkspaceController::edit`
shipped `canViewAuditLog` as its only permission prop, so a plain Member
saw and could click "Rename workspace" and "Delete workspace" and got a
403 for their trouble. It now also sends `canUpdateWorkspace`,
`canDeleteWorkspace`, `canManageMembers` and `canInviteMembers`, and the
page renders the Profile, Invitations and Danger Zone cards — and their
modals — only for viewers who hold the matching ability. The page itself
stays open to any workspace member deliberately:
`AuditLogPolicy::viewAny` admits project Managers who are not workspace
admins, so gating the whole route to Admin+ would lock them out of the
audit log they are explicitly permitted to see.

**Members and Invitations are no longer "Soon".** Both features have
worked for a long time; only the hub had not caught up. Members now links
to the Team page (label and description adapt to whether the viewer can
manage or only view), and Invitations links to the invite form for
viewers who can invite.

**The Team page's four dead menu items.** `onResendInvite`,
`onRevokeInvite`, `onCopyInviteLink` and `onTransferTasks` were
`console.log` stubs. Resend and revoke now call the existing
`workspace.invitations.resend`/`destroy` routes using `invitation_id` —
*not* the row's `id`, which is the string `"invitation-{id}"` — with
success/error toasts through the existing notification store and a
`pendingInvitationId` guard against double submission.

*Copy invite link* needed a decision rather than wiring. Building the
link requires the invitation token, and `WorkspaceInvitationController::
showAccept` lets a logged-out visitor with no existing account register
under the invited email — so a leaked token is a real way into the
workspace. The roster payload goes to every viewer of the Team page, not
just the admins who see the actions menu, so naively adding the token
would have handed it to plain members too. `TeamRoster` now computes
`$viewer->can('invite', $workspace)` once and emits `invite_url` only for
viewers who could have issued that invitation anyway; everyone else gets
`null`, and `MemberActionsMenu` hides the item when it is absent. Two
tests lock this down, including an `assertDontSee($invitation->token)`
for a plain member.

*Transfer tasks* was removed. There is no task-reassignment feature
anywhere in this codebase, and inventing one was well outside FR30 —
shipping a button that silently does nothing is worse than not offering
it.

**Other fabrications removed from the Team page.** The "Suspended" filter
tab counted a status nothing can produce (`TeamRoster` only ever emits
`active`/`pending`, and no suspend feature exists). The `AppAiInsight`
strip displayed an invented statistic about a named user as if it were
real analytics. A "Filter" button in the toolbar had no handler at all.
All three are gone. Seat usage no longer hardcodes `total = 10` in the
component — it comes from a new `workspace.seat_limit` config value
(`WORKSPACE_SEAT_LIMIT`, default 10) passed as a `seatLimit` prop, so the
number has one source. Nothing enforces that limit yet; it is a display
figure, and billing remains a "Coming later" card. The "Invite member"
button is now gated on `canInviteMembers`.

**Tests**: 18 new (13 in `WorkspaceSettingsTest`, 5 added to
`TeamMemberTest` for invite-link exposure, token non-disclosure, and
resend/revoke for both an admin and a denied member). Full suite:
**340 passed** (322 baseline + 18). Pint, ESLint and `vue-tsc --noEmit`
all clean.

**Not done, deliberately**: member suspend and transfer-tasks-on-removal
have no backend and were not invented; the seat limit is displayed but
not enforced.

### 24. FR35 — role/project-based visibility and access cleanup

Entry #21 found FR35's *rules* complete and well covered (project_users,
the three policies on the `workspace admin-rank OR project role-rank`
model, `accessibleProjectsFor()`, `AuditLogPolicy`) but the *UI shell* and
*page payloads* ignoring them. This entry closes that gap. No policy,
migration or route changed, and `WorkspacePermission` was deliberately not
touched.

**Navigation booleans are derived server-side, once.** The sidebar renders
on every page, so the flags belong in `HandleInertiaRequests::share()`
alongside the existing `workspace` and `notifications` keys rather than in
a per-page prop. New `navigation` key (a closure, so partial reloads that
don't ask for it skip the queries; `null` for guests and for users with no
current workspace):

- `projects` — has at least one accessible project **or** can create them
  (`accessibleProjectsFor()->exists() || can('create', [Project, ws])`).
  An Admin with an empty workspace still sees the entry, which is the
  point: they are the one who would create the first project.
- `analytics` / `archive` — `accessibleProjectsFor()->exists()`. Both
  pages already scope their data per viewer, so an unassigned member got a
  correct-but-empty page; per the task's "do not show links just because
  the backend safely returns an empty page", the link is now hidden
  instead.
- `audit` — `can('viewAny', [AuditLog, ws])`, so project Managers keep it.
- `team` — always true for a workspace member. `TeamMemberController::
  index` has never gated the roster beyond membership and
  `WorkspacePolicy::view` is `hasMember`, so hiding it would have been a
  policy change, not a visibility fix.
- `workspaceSettings` — true if **any** settings ability holds: audit
  view, update, delete, manageMembers, manageRoles, or invite. Audit is
  checked first precisely so a project Manager who is not a workspace
  Admin still reaches the one section they are entitled to.

Every flag calls the existing policy or `Workspace::accessibleProjectsFor()`
— no authorization logic is restated in the middleware and none moved into
Vue. `AppSidebar` builds its item list from the flags, and
`WorkspaceSwitcher` gates its "Workspace settings" entry on
`navigation.workspaceSettings`.

**Audit was deliberately not added to the sidebar.** The task asks that
Audit "only appear when `AuditLogPolicy::viewAny` allows it"; it has never
been a sidebar entry, and the do-not list rules out adding navigation
items. It stays reachable from the settings hub, which already gates it on
the same ability. The `navigation.audit` flag is still emitted — it feeds
`workspaceSettings` and makes the rule directly testable.

**Payload hardening on `ProjectController::show`.** Two lists leaked past
their audience:

- `workspaceMembers` — every workspace user's id/name/email — went to any
  project viewer, though only the Add-member modal consumes it. Now sent
  only when `manageMembers` passes, empty otherwise.
- `members` — the assignee-picker source (project members ∪ workspace
  admins) — went to viewers who cannot touch tasks. `TaskDetailModal` uses
  it for exactly one thing, `AssigneePicker` inside the edit form, so it is
  now sent only when `can('create', [Task, project])`. Project Managers and
  Admins are unaffected and assignee selection still works for everyone
  allowed to create or edit tasks; a plain project Member gets an empty
  list and never sees the picker.

Both keep their prop names and array shape, so the frontend contract is
unchanged and no page needed redesigning.

**A real permission mismatch found on the project page.** The Danger Zone
("Delete project") was gated on `canManageProjects`, which is
`can('update', $project)` — true for a project **Manager**. But
`ProjectPolicy::delete` is workspace-Admin-only. Every project Manager was
shown a Delete button that 403s. Added a `canDeleteProject` prop from
`can('delete', $project)` and gated the Danger Zone on it. The other
controls audited on that page — edit details, member management, task
create/edit/delete, board columns, meeting create/edit/delete — were
already gated on the matching ability and were left alone; their modals
are now additionally wrapped in `v-if` guards so they cannot be mounted by
a viewer without the ability rather than relying on a 403 at submit time.

**Tests**: 14 new. New
`tests/Feature/Workspace/NavigationVisibilityTest.php` (8) covers Owner,
Admin-with-no-projects, project Manager, plain project Member, unassigned
workspace member, audit visibility asserted against the actual
`workspace.audit.index` response before *and* after a role change,
cross-workspace isolation (a project in another workspace must not light
up this workspace's nav), and `navigation` being `null` for a guest.
`ProjectMemberTest` gained 6: plain member receives neither roster,
Manager receives both, Manager sees `canDeleteProject: false` and is
actually forbidden from deleting, Admin still receives the full payload,
and the roster never crosses a workspace boundary.

**Verification**: `php artisan test --compact` → **354 passed, 1844
assertions**, 0 failures (340 baseline + 14). `vendor/bin/pint --dirty
--format agent` → passed. `npm run lint:check` → clean. `npm run build` →
built in 2.73s, no errors. `npx vue-tsc --noEmit` reports exactly two
`TS2688` errors, both **pre-existing and unrelated to this work**: they
come from `tsconfig.json`'s `compilerOptions.types` array, which lists
`"vue/tsx"` and `"./resources/js/types"` — the latter is a directory whose
entry point is `index.ts`, not a `.d.ts`, so it cannot resolve as a type
library. `tsconfig.json` has not been modified since the initial
starter-kit commit (`e998c49`, 4 months ago), and neither error references
any source file. Worth fixing in a cleanup pass so the type check can be
trusted to exit zero.

### 25. Report-backed re-audit of the remaining Partial FRs

`SprintSync FYP1 Report-1.docx` was located in `~/Downloads` (earlier
recursive searches missed it; a direct `ls` found it immediately) and
extracted with `textutil -convert txt`. FR01–FR35 are now recorded
verbatim in `docs/FUNCTIONAL_REQUIREMENTS.md`, which replaces this file's
header reference as the requirement source of truth. No code changed in
this entry.

**FR02 — Reset Password: Partial.** FR02-04 requires "a 6-digit one-time
code" sent to the email address, FR02-05 a code-entry screen with a Resend
Code hyperlink, and FR02-06 a separate Set New Password screen after
verification. The implementation is Laravel's standard signed-token reset
link (`PasswordResetLinkController` → `NewPasswordController`, pages
`auth/ForgotPassword` and `auth/ResetPassword`). FR02-01, 02, 03 and 07
are satisfied; 04, 05 and 06 are not, as written. This is a
**report-vs-design mismatch, not a defect** — a single-use signed link is
the stronger mechanism (no 6-digit brute-force surface, no code-reuse
window) and is what the framework provides. Recommendation: amend the
report to describe a link-based reset, rather than downgrade the
implementation to an OTP. If the report must stand, the change is
self-contained (a codes table, a verify screen, a resend endpoint) and is
a small-to-medium feature, not an architectural one.

**FR03 — Logout: Complete.** FR03-01 is satisfied by the Logout item in
`UserMenuContent` (posts to `route('logout')`); FR03-02 by
`AuthenticatedSessionController::destroy`, which invalidates the session
and regenerates the token before redirecting to `/`→login; FR03-03 by
Laravel's idle session expiry (`config/session.php` `lifetime` = 120
minutes via `SESSION_LIFETIME`, `expire_on_close` = false). The session is
refreshed per request, so the 120 minutes is genuinely an inactivity
window. Previously marked Partial with no stated reason; the wording shows
nothing is missing. There is no pre-expiry warning UI, but the report does
not ask for one.

**FR04 — View Dashboard: Partial.** FR04-02 is satisfied
(`NotificationBell` renders the unread count in `AppSidebarHeader`).
FR04-01 is partially satisfied — entry #24 made the *navigation*
role-dependent, but the dashboard body itself is identical for every role.
Three sub-requirements are absent:
- **FR04-03** — no list of upcoming scheduled meetings. `DashboardController`
  returns members, pending invite count, activity and onboarding only. The
  `ComingNextCard` on the right column is a hardcoded marketing placeholder
  ("AI sprint planning / Join the waitlist"), not meeting data.
- **FR04-04** — pending summary reviews. Blocked by FR11–FR13.
- **FR04-05** — no sprint task completion widget.
FR04-03 and FR04-05 are small, self-contained additions (both read from
data the app already stores). FR04-04 cannot be built before the summary
pipeline exists.

**FR27 — Assign and Manage User Roles: Partial.** FR27-02 and FR27-03 are
satisfied by the policy layer plus entry #24's visibility work. FR27-01 —
"user roles (Scrum Master, Developer, Team Lead) … assigned at the time of
account registration" — is not met and, as written, conflicts with the
architecture: `RegisteredUserController` takes name/email/password and a
workspace name, and roles are **per-workspace** (`workspace_users.role`),
assigned by invitation or by an owner, not globally at signup. The report's
three role names do not exist anywhere in the codebase; `App\UserRole` is
Owner/Admin/Member. This is a **report-vs-design mismatch**, and the
current design is the correct one for a multi-tenant product — a user who
is a Scrum Master in one workspace may be a Developer in another, which a
registration-time global role cannot express. Recommendation: amend FR27-01
to describe workspace-scoped role assignment. Reverting to global roles
would be an architectural change and would break FR29–FR35.

**FR31 — Invite Team Members: Partial.** Six of seven satisfied — invite
by email (FR31-01), emailed join link (FR31-02), unique token with a 7-day
expiry (FR31-03, `workspace.invitation_ttl_days` default 7), expired links
rejected with a notice (FR31-05), acceptance with a default role
(FR31-06), and revocation before expiry (FR31-07, wired into the UI in
entry #23). **FR31-04 is missing**: there is no shareable invite link that
*any* user can redeem. Every invitation is bound to one email address —
`WorkspaceInvitationController::showAccept` rejects a signed-in user whose
email differs, and a signed-out visitor can only register under the
invited address. A generic workspace join link is a genuine feature gap of
moderate size (a workspace-level token plus an accept path that does not
assume a pre-known email), and it carries a real security decision: an
open link is bearer-authority to join a tenant, so it needs its own expiry,
revocation and probably a role cap. Not a small fix.

**FR33 — Create and Manage Custom Roles: Complete.** All six satisfied
after entry #22: create by name (FR33-01), define granular permissions from
a predefined list (FR33-02, `WorkspacePermission` + the Role Management
toggles), edit name or permissions (FR33-03 — this was the broken
save/delete fixed in entry #22), delete with affected members reassigned to
a default (FR33-04 — `DeleteWorkspaceRoleAction` nulls `workspace_role_id`,
leaving the member on their system role, which *is* the default tier),
per-workspace isolation (FR33-05, enforced by `workspace_id` scoping and
covered by `test_the_same_role_name_can_exist_in_two_workspaces`), and the
settings-page listing of roles and permissions (FR33-06).

Note FR33-02's example permission list ("manage meetings, approve
summaries, manage tasks, view analytics, manage members, manage projects")
does not match `WorkspacePermission`'s actual cases, which cover projects,
members, billing and integrations. The report says "e.g.", so this is not a
compliance failure, but the catalogue is worth aligning if the permissions
ever become authoritative.

**FR34 — Assign Roles to Workspace Members: Complete.** This resolves the
question left open by entry #22. FR34's five sub-requirements are
*exclusively about assignment*: assign any defined role to any member
(FR34-01), change it at any time (FR34-02), apply the change immediately
without forcing a logout (FR34-03), prevent the Owner from removing their
own Owner role (FR34-04), and show each member's current role in the member
list (FR34-05). All five hold — entry #22 shipped 01, 02 and 05;
`UpdateWorkspaceMemberRoleAction` throws `cannotChangeOwnerRole` for 04;
and 03 is satisfied structurally, since every authorization decision reads
the pivot per request and nothing caches roles in the session.

**Custom-permission enforcement is not an FR34 requirement.** The word
"enforce" never appears in FR34. Permission *definition* belongs to FR33
("define granular permissions"), and permission *enforcement* belongs to
**FR35** — FR35-02 ("only the navigation items, widgets, and action
buttons that the authenticated user's role has permission to access"),
FR35-03 ("as defined by their workspace role") and FR35-04 ("users whose
role includes the corresponding permission"). FR27-02 restates the same
idea generally. So the open item recorded in entry #22 was filed under the
wrong FR: it is an FR35 obligation, and FR34 is done.

**FR35 — Role-Based Dashboard and Visibility Control: reopened, Partial.**
Entry #24's work stands and satisfies FR35-02's navigation and
action-button clauses, FR35-03 (task visibility scoped to accessible
projects), FR35-05 (workspace switcher re-rendering per workspace) and
FR35-06 (access-denied responses). Three things the wording requires are
absent:
- **FR35-01** — "a distinct dashboard layout for each user based on their
  assigned workspace role and its associated permissions". Every role
  currently receives the same `Dashboard.vue` body.
- **FR35-02, "widgets"** — dashboard widgets are not role-filtered.
- **FR35-04, summary approval** — the action does not exist; blocked by
  FR12/FR13.
FR35-01 and the widgets clause are also where the `WorkspacePermission`
matrix would finally become authoritative, since both are phrased in terms
of what the role "has permission to access". That remains the open design
decision — it was not made here, and no authorization was redesigned.

**FR30 — Edit Workspace: stays Complete.** All five verified against the
wording: edit name (FR30-01), view all members (FR30-02, the Team roster),
remove any member (FR30-03), delete the workspace "removing all associated
projects, tasks, meetings, and member associations" (FR30-04 — confirmed by
reading the migrations: `projects`, `tasks`, `meetings`, `board_columns`,
`task_comments`, `project_users`, `workspace_users`, `workspace_roles` and
`workspace_invitations` all declare `cascadeOnDelete` up the chain to
`workspaces`), and switching between workspaces from the navigation
(FR30-05). The report phrases these as Workspace Owner abilities; the
implementation also grants edit/member-management to workspace Admins,
which is a superset and not a violation. The suspend and transfer-tasks
capabilities noted as a caveat in entry #23 do not appear anywhere in
FR30 — that caveat can be considered closed.

### 26. Full report-backed audit, graded per sub-requirement (2026-08-17)

First audit of all 35 FRs against `docs/FUNCTIONAL_REQUIREMENTS.md` at
sub-requirement granularity. No code changed. Rule applied: an FR stays
Complete only if every mandatory sub-requirement is implemented. Legend —
**C** Complete, **P** Partial, **NS** Not Started, **B** Blocked by
FR10–FR13, **DM** Design Mismatch.

| FR | Sub-requirement verdicts | Status |
|---|---|---|
| FR01 Login | 01 C, 02 C, 03 C, 04 C, 05 C, 06 **P** | Partial |
| FR02 Reset Password | 01 C, 02 C, 03 C, 04 **DM**, 05 **DM**, 06 **DM**, 07 C | Design Mismatch |
| FR03 Logout | 01 C, 02 C, 03 C | **Complete** |
| FR04 View Dashboard | 01 P, 02 C, 03 **C**, 04 **B**, 05 **C** | Partial (blocked-only) |
| FR05 Schedule Meeting | 01 P, 02 C, 03 **DM**, 04 C, 05 **NS**, 06 C | Partial |
| FR06 View Meeting Details | 01 **C**, 02 P, 03 **B** | Partial |
| FR07 Join Meeting | 01 C, 02 C, 03 **DM** | **Complete** |
| FR08 Edit Meeting | 01 C, 02 C | **Complete** |
| FR09 Cancel Meeting | 01 C, 02 C | **Complete** |
| FR10 Auto-Transcribe | 01–04 **NS** | Not Started |
| FR11 Generate AI Summary | 01–05 **NS** | Not Started |
| FR12 Review AI Summary | 01–07 **NS** | Not Started |
| FR13 Approve and Send | 01–05 **NS** | Not Started |
| FR14 View Task Board | 01 C, 02 **DM**, 03 C, 04 **C** | **Complete** (DM on 02) |
| FR15 Create Task | 01 **B**, 02 C | Partial (blocked-only) |
| FR16 Update Task Status | 01 C, 02 **NS** | Partial |
| FR17 Edit and Delete Task | 01 C, 02 C | **Complete** |
| FR18 View Meeting Archive | 01 C, 02 P, 03 **B** | Partial |
| FR19 Search Meeting Archive | 01 C, 02 C, 03 **B**, 04 C | Partial (blocked-only) |
| FR20 View Sprint Analytics | 01 C, 02 P, 03 **B**, 04 P, 05 **NS** | Partial |
| FR21 View Notifications | 01 C, 02 C, 03 C | **Complete** |
| FR22 In-App Triggers | 01 **B**, 02 **B**, 03 C | Partial (blocked-only) |
| FR23 Email Triggers | 01 C, 02 **B**, 03 C | Partial (blocked-only) |
| FR24 Change Password | 01 C, 02 C, 03 C, 04 C | **Complete** |
| FR25 Notification Preferences | 01 C, 02 C, 03 C | **Complete** |
| FR26 Change Profile Picture | 01 C, 02 **C**, 03 C | **Complete** |
| FR27 Assign/Manage User Roles | 01 **DM**, 02 C, 03 C | Design Mismatch |
| FR28 View Audit Log | 01 P, 02 C, 03 P | Partial |
| FR29 Create Workspace | 01 C, 02 C, 03 C, 04 C, 05 C | **Complete** |
| FR30 Edit Workspace | 01 C, 02 C, 03 C, 04 C, 05 C | **Complete** |
| FR31 Invite Team Members | 01 C, 02 C, 03 C, 04 **NS**, 05 C, 06 C, 07 C | Partial |
| FR32 Create/Manage Projects | 01 C, 02 C, 03 C, 04 C, 05 **C**, 06 C | **Complete** |
| FR33 Custom Roles | 01 C, 02 C, 03 C, 04 C, 05 C, 06 C | **Complete** |
| FR34 Assign Roles to Members | 01 C, 02 C, 03 C, 04 C, 05 C | **Complete** |
| FR35 Role-Based Visibility | 01 **NS**, 02 P, 03 C, 04 P, 05 C, 06 C | Partial |

#### Evidence for every non-Complete verdict

**FR01-06** "role-based dashboard" — `RegisteredUserController` /
`AuthenticatedSessionController` redirect to `/{workspace}/dashboard`, which
renders the same `Dashboard.vue` body for every role. The redirect works; the
role-distinctness is FR35-01's obligation. Resolves when FR35-01 lands — not
separate work.

**FR02-04/05/06** — `PasswordResetLinkController` + `NewPasswordController`
implement Laravel's signed-token link (`auth/ForgotPassword`,
`auth/ResetPassword`). No 6-digit code, no code-entry screen, no Resend Code.
See "Report/design mismatches" below.

**FR04-01** — see FR01-06. **FR04-03** — `DashboardController` returns
members, `pendingInvitesCount`, activity and onboarding only; the right-column
`ComingNextCard` is a hardcoded marketing placeholder ("AI sprint planning /
Join the waitlist"), not meeting data. **FR04-04** — pending summary reviews,
Blocked. **FR04-05** — no task-completion widget anywhere on the dashboard.

**FR05-01** — meetings are reached via sidebar → Projects → project → Meetings
tab, not "from the dashboard". **FR05-03** — `Meeting` has no participant
relation at all (`$fillable`: title, description, scheduled_at,
duration_minutes, meeting_link, project_id, workspace_id, created_by).
`ResolveMeetingRecipients` returns `project->members()`. **FR05-05** — the
record is stored, but `meeting_link` is an optional URL the creator pastes;
nothing generates a link. Needs a conferencing-provider decision, and is
*not* blocked by FR10–FR13.

**FR06-01** — no meetings list on the dashboard (same surface as FR04-03).
**FR06-02** — `EditMeetingModal` shows title, agenda, date/time, duration,
join link and creator; **participants** are not shown because they are not
modelled. **FR06-03** — only the client-side Upcoming/Past badge
(`lib/meetings.ts::isPastMeeting`) exists; Scheduled/Completed map onto it,
but Pending Review and Distributed are summary states — Blocked.

**FR07-03** — access is denied to non-members of the project
(`ProjectPolicy::view` gates the whole page, `MeetingPolicy::view` the
meeting), not to "any user not listed as a participant", because no
participant list exists. Intent satisfied by a different mechanism.

**FR14-02** — entry #12 replaced the fixed To Do/In Progress/Done enum with
per-project `board_columns`; every project is still seeded with those three
by default, so this is a superset, not a regression. **FR14-04** — the board
always shows every task in the project; no "my tasks" default and no toggle.

**FR15-01** — auto-create tasks from a distributed summary. Blocked.

**FR16-02** — "immediately reflect across all active users' dashboards".
There is no broadcasting anywhere in the project: no Echo, Reverb or Pusher in
`composer.json`, `package.json` or `config/`. Other tabs update on their next
Inertia navigation. Needs realtime infrastructure.

**FR18-02** — `ArchiveRecordData` carries id, type, title, subtitle,
project, assignee and `occurred_at`. **Participant count** is absent
(depends on the participant model); **summary status** is Blocked.
**FR18-03** — approved summary + transcript, Blocked.

**FR19-03** — `SearchArchiveAction` LIKE-searches title/subtitle only.
Decisions, action items and blocker text do not exist yet. Blocked.

**FR20-02** — `BuildAnalyticsAction` returns `task_completion_percentage` and
`tasks_by_column`, which is the substance of the chart, but there is **no
sprint entity anywhere in the schema**, so "for the current sprint" cannot be
satisfied. **FR20-03** blocker frequency — Blocked. **FR20-04** — total
meetings held is present (`total_meetings`); total action items and total
blockers are Blocked. **FR20-05** — analytics are scoped by
`accessibleProjectsFor()`, i.e. by project membership, **not** by role tier;
there is no team-wide-vs-personal split.

**FR22-01** summary ready for review — Blocked. **FR22-02** — a task-assigned
in-app notification exists (`TaskAssignedNotification` via
`ResolveTaskRecipients`), but the requirement's trigger is assignment *from a
distributed summary*, which is Blocked.

**FR23-02** approved-summary email — Blocked. FR23-01/03 are satisfied by
`MeetingScheduledMail` / `MeetingCancelledMail`, sent to project members
(participant mismatch noted above).

**FR26-02** — `AvatarSettings.vue` uploads immediately on file selection or
drop; there is no preview-then-confirm step. The requirement asks for a
preview followed by upload "upon confirmation". Small, self-contained gap.

**FR27-01** — `RegisteredUserController` accepts name/email/password and an
optional workspace name; no role selection. Roles are per-workspace
(`workspace_users.role`), and Scrum Master / Developer / Team Lead exist
nowhere — `App\UserRole` is Owner/Admin/Member.

**FR28-01** — `AuditAction` covers workspace, member, project, task
(including `TASK_MOVED`), board-column and meeting events. **User account
changes are not audited at all** — no case for profile update, password
change, avatar change or account deletion (entry #20 flagged the deletion gap
already). Summary approvals are Blocked. **FR28-03** — the log is viewable
and filterable by project and actor, and meeting events are recorded, but
"for any meeting or summary" is only half-reachable; per-summary history is
Blocked.

**FR31-04** — every invitation is bound to one email:
`WorkspaceInvitationController::showAccept` rejects a signed-in user whose
email differs, and a signed-out visitor may only register under the invited
address. No shareable link any user can redeem.

**FR32-05** — projects are listed on their own index page. The workspace
dashboard shows no project list and no per-project task summary; the only
mention of projects there is an onboarding checklist item.

**FR35-01** — one `Dashboard.vue` body for all roles. **FR35-02** —
navigation and action buttons are gated (entry #24); **widgets** are not.
**FR35-04** — meeting scheduling is correctly restricted
(`MeetingPolicy::create`); summary approval is Blocked.

#### Group 1 — Unblocked missing work

Everything here can be finished before FR10–FR13.

| Item | Size | Note |
|---|---|---|
| FR26-02 preview before avatar upload | XS | one component |
| FR28-01 audit user account changes | S | add `AuditAction` cases + `RecordAuditLogAction` calls in `Settings\*Controller` |
| FR04-03 + FR06-01 upcoming meetings on dashboard | S | one surface, two FRs |
| FR04-05 task completion widget | S | reuses `BuildAnalyticsAction` aggregates |
| FR32-05 projects + task summary on dashboard | S | same controller as above |
| FR14-04 "my tasks" default with view-all toggle | S–M | board default + filter |
| FR20-05 team-wide vs personal analytics by role | M | needs a role-tier rule, not a new model |
| FR31-04 shareable workspace invite link | M | security design: bearer authority to join a tenant; needs its own expiry, revocation and role cap |
| FR05-03 participants on meetings | M–L | new domain concept; cascades to FR06-02, FR07-03, FR18-02, FR23 |
| FR05-05 generated meeting link | M–L | needs a conferencing-provider decision |
| FR35-01 + FR35-02 widgets: role-distinct dashboard | L | requires the `WorkspacePermission` enforcement decision first |
| FR16-02 realtime status propagation | L | new infrastructure (Echo/Reverb) |
| FR20-02 "current sprint" | L | no sprint entity exists |

#### Group 2 — Blocked by FR10–FR13

Do not schedule these independently; they land with the AI pipeline.

FR04-04 · FR06-03 (Pending Review / Distributed) · FR15-01 · FR18-02
(summary status) · FR18-03 · FR19-03 · FR20-03 · FR20-04 (action items,
blockers) · FR22-01 · FR22-02 · FR23-02 · FR28-01 (summary approvals) ·
FR28-03 (per-summary history) · FR35-04 (summary approval).

FR15, FR19, FR22 and FR23 contain **only** blocked work — they are as
finished as they can be until FR10–FR13 exist.

#### Group 3 — Report/design mismatches

No implementation recommended. In each case the current design is the safer
engineering and the report should be amended.

- **FR02-04/05/06 — OTP vs signed reset link.** A single-use signed link has
  no 6-digit brute-force surface and no code-reuse window, and is what the
  framework hardens for you. Amending the report is safer than building an
  OTP path. *If the report must stand, it is a self-contained
  small-to-medium feature — a codes table, a verify screen, a resend
  endpoint — not an architectural change.*
- **FR27-01 — registration-time global roles vs workspace-scoped roles.**
  A global role cannot express a user who is a Scrum Master in one workspace
  and a Developer in another, which is the product this now is. Reverting
  would break FR29–FR35. Amend FR27-01 to describe workspace-scoped
  assignment.
- **FR05-03 / FR06-02 / FR07-03 — per-meeting participant list vs project
  membership.** The app treats project membership as the participant list,
  which keeps meeting access consistent with task and board access and
  removes a whole class of "invited but can't see the project" states. The
  open question the report forces is **external participants** — people
  without a workspace account. If those matter, this becomes real Group 1
  work; if not, amend the wording.
- **FR14-02 — three fixed columns vs custom board columns.** The
  implementation is a superset and every project still starts with To Do /
  In Progress / Done. Amend to describe configurable columns.
- **FR29-02 — "Workspace Owner (Scrum Master)".** Terminology only; the code
  designates `UserRole::OWNER`. No change needed either way, but the report's
  parenthetical should be dropped if FR27-01 is amended.
- **FR33-02 — permission catalogue naming.** The report's examples (manage
  meetings, approve summaries, view analytics) do not match
  `WorkspacePermission`'s cases (projects, members, billing, integrations).
  The report says "e.g.", so this is not a failure, but the catalogue should
  be aligned if the permissions are ever made authoritative.

### Superseded: Complete FRs with unverified sub-requirements

Reading the full requirement text surfaced gaps inside FRs this tracker
already counts Complete. They were **not** re-graded — the audit was scoped
to FR02/03/04/27/31/33/34 plus a FR30/FR35 wording check — but they are
recorded here so the 26 is not mistaken for a fully verified number. Most
trace back to the unbuilt FR10–FR13 pipeline:

- **FR06-03** — meeting status must be one of Scheduled / Completed /
  Pending Review / Distributed. Only an Upcoming/Past distinction exists
  (deliberate, per entry #10); three of the four states are summary states.
- **FR14-02** — "three status columns: To Do, In Progress, Done". Entry #12
  replaced the fixed enum with custom per-project columns, a superset.
- **FR14-04** — "the user's assigned tasks by default, with an option to
  view all" — already a known gap.
- **FR15-01** — auto-create tasks from a distributed summary. Blocked by
  FR11–FR13.
- **FR18-03** / **FR19-03** — archive must expose the approved summary and
  transcript, and search across decisions, action items and blocker text.
  Blocked by FR10–FR13.
- **FR20-02/03/04** — sprint-based completion chart, blocker-frequency
  chart, and a totals widget counting action items and blockers. The
  analytics module has no sprint concept and no blocker data.
- **FR22-01/02** — both in-app triggers are summary-derived. Blocked.
- **FR23-02** — summary distribution email. Blocked.
- **FR28-01/03** — audit must cover summary approvals and be viewable
  per meeting or summary. Blocked.
A full report-backed sweep of all 35 is the honest next step for the
percentage; it is a read-only audit and does not depend on any build work.

## Key architectural decisions

- **Tasks is its own module**, not nested inside Projects — mirrors how
  `Teams` is already a separate module from `Workspace` despite the
  conceptual overlap. Modules are organized by feature area, not raw
  parent/child DB relationships.
- **`App\TaskStatus`** lives at the top level (`app/TaskStatus.php`), not
  inside a module — mirrors `App\UserRole`'s placement. Cross-cutting small
  enums live outside `app/Modules` in this codebase.
- **Two distinct task-authorization abilities**: `update` (full edit —
  Owner/Admin only) vs. `updateStatus` (the assignee, or Owner/Admin). This
  is what lets a Member drag/change status on their own card without
  granting them edit/delete rights — matches "Members can view/update
  permitted tasks; Owner/Admin can manage all tasks."
- **Drag-and-drop uses the native HTML5 DnD API, not a library.** Checked
  `package.json`/`node_modules` first — nothing suitable was already
  installed (`@vueuse/core` has no `useSortable`; that's a separate,
  unpackaged `@vueuse/integrations` addon). Native DnD needed zero new
  dependencies, matching `CLAUDE.md`'s "don't change dependencies without
  approval." Trade-off: it's mouse-primary — no keyboard or reliable touch
  fallback exists now that the segmented control is gone (see Known gaps).
- **The Kanban board's data rides on `ProjectController::show`**, not a
  separate Tasks index route — matches this app's existing convention that
  a page's own controller aggregates whatever it needs to render
  (`DashboardController` does the same across Workspace/Invitations).
  Verified via Laravel's `ImplicitRouteBinding` source that 3-level scoped
  bindings (`workspace → project → task`) enforce pairwise parent scoping
  correctly, so a task 404s unless it belongs to both the project and
  workspace in the URL.
- **`workspaceRoute()` bugs (#2 and #4) share one root cause**: silently
  trusting global "current workspace" state instead of the page's own
  scoped params. Every new page/component built since has passed explicit
  `{ paramName: id }` objects rather than relying on that fallback.
- **Project membership is a second, independent authorization tier below
  workspace roles, not a replacement for them.** Every policy check is
  `workspace admin-rank OR project role-rank`, never project role alone —
  so an Owner/Admin's access never regresses just because nobody added
  them to a project's `project_users` table. This mirrors how
  `WorkspaceRole` (custom roles) layers under the fixed `UserRole` enum
  rather than replacing it.
- **`{member}` route params over `{user}` for nested project-membership
  routes** — see entry #8. General lesson for this codebase: when adding a
  child resource under a model whose collection relationship isn't named
  after the English plural of the route segment, name the route param
  after the actual relationship method, not the underlying model class.
- **A policy method that exists but is never called by a controller is
  equivalent to no policy at all.** `ProjectPolicy::view()` had been
  unreachable dead code since Projects shipped (entry #8). Worth a
  deliberate sweep of the other modules for the same gap rather than
  assuming a policy file's presence means it's enforced.
- **Notification preferences are opt-out rows, not a full opt-in matrix
  seeded per user.** A missing `(user_id, type, channel)` row means
  enabled. This is what makes "existing users keep current behavior by
  default" true without a data migration/backfill — see entry #18. The
  trade-off: reading a user's full preference state means unioning saved
  rows with the enum's declared defaults (done once, server-side, in
  `NotificationPreferenceController::groups()`), rather than a single flat
  table scan — an acceptable cost since it only runs on the settings page
  itself, never on the hot notification-dispatch path.
- **`NotificationPreferenceGate` filters a recipients collection, not a
  single notifiable.** It was tempting to filter per-recipient inside each
  `Notification` class's `via()` method (Laravel calls `via($notifiable)`
  per recipient under `Notification::send()`), but that would have coupled
  plain notification classes to a preferences repository and made the
  "recipient resolution vs. preference filtering are separate concerns"
  requirement (FR25) harder to see in the code. Filtering the collection
  once, right before each `Mail::`/`Notification::send()` call, keeps both
  steps visible as two sequential one-line calls in every Action.
- **New nullable columns with an appended accessor must be added to the
  relevant factory's `definition()` in the same change**, not left to
  default silently. `Model::preventAccessingMissingAttributes()` is
  already enabled outside production (`AppServiceProvider`), and
  `actingAs(User::factory()->create())` hands a never-re-queried model
  straight to the auth guard — so a factory that doesn't set a new
  column leaves it genuinely absent from that in-memory model, and the
  first accessor touch (here, `avatar_url`, evaluated on every request
  because `auth.user` is shared) throws. See entry #19 for the concrete
  break/fix.
- **`tests/Unit/ModuleBoundaryTest.php` is a real, enforced architectural
  contract in this codebase**, not just a convention documented in
  prose: modules may only import each other's `Contracts`/`Data`/
  `Models`/`Actions`/`Policies`/`Exceptions`; anything else (a class at a
  module's root, `Services`, `Http`, `Support`) is private. See entry
  #20's module-boundary lesson — `AuditAction` had to move from the
  module root into `Data/` the moment other modules needed to import it.
  Worth checking this test before adding any new module-root class that
  other modules will reference.
- **Audit logging deliberately omits foreign-key constraints** on every
  reference column (`workspace_id`, `project_id`, `user_id`,
  `subject_id`) so that deleting a workspace/project/task/user can never
  cascade-delete the audit history describing that very deletion — see
  entry #20. This is a different trade-off than every other table in
  this app (which all use `constrained()`), made deliberately for this
  one immutable-log use case; not a pattern to copy elsewhere without
  the same reasoning applying.

## Test results

FR05's own suite, run in isolation, is fully green:

```
php artisan test --compact tests/Feature/Meetings/MeetingTest.php
Tests:    18 passed (62 assertions)

vendor/bin/pint --dirty --format agent
→ passed

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.29s (3143 modules, no errors)
```

At the time FR05 was finished, running the **full** suite showed 2
pre-existing failures in `tests/Feature/Tasks/TaskTest.php`
(`a_task_can_be_created_with_an_assignee`, `an_owner_can_update_a_task`),
caused by the concurrent project-membership work landing mid-flight (see
entry #7's note and entry #8). **Both are now fixed** as part of entry #8,
which also updated the two `ProjectTest` cases that assumed any workspace
member could list/view any project (now correctly scoped to project
membership) and added dedicated coverage for the new membership rules.

Full suite after entry #11:

```
php artisan test --compact
Tests:    193 passed (644 assertions)

vendor/bin/pint --dirty --format agent
→ passed

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.35s (no frontend files touched — identical output hashes to entry #10)
```

Covers: auth, email verification, password reset/update, profile update,
dashboard, workspace tenant isolation, workspace CRUD, workspace roles,
workspace invitations, team member management, AI assistant endpoints,
module boundaries, Projects (25 tests), Tasks (26 tests), project
membership (19 tests in `ProjectMemberTest.php`), Meetings (25 tests,
unchanged from entry #10), meeting notifications (10 new tests in
`MeetingNotificationTest.php` — scheduled email reaches the right project
members and excludes the actor + unassigned members, email payload
matches the meeting's fields, meaningful updates email while no-op
updates don't, cancellation reaches the project minus the actor with the
right "cancelled by" name, and an explicit no-duplicate-recipients check).

No JS test runner exists in this project (`package.json` only has
build/lint/format scripts). Entry #11 touched no frontend files, so
`npm run build` is an unaffected-regression check, not new coverage.

Full suite after entry #13 (custom board columns, column reordering,
task-detail-first popup v1, task comments — see entries #12/#13):

```
php artisan test --compact
Tests:    228 passed (734 assertions)

vendor/bin/pint --dirty --format agent
→ passed

npm run lint:check
→ 0 errors

npm run build
→ succeeded
```

Adds `BoardColumnTest.php` (21 tests), `TaskCommentTest.php` (14 tests),
`TaskTest.php` rewritten for `board_column_id` (24 tests, same count as
before, different assertions).

**Entry #14 (this Task Detail redesign + comment-refresh fix) is
frontend-only and was not run through the automated suite, per that
task's explicit instruction not to run build/lint/tests this pass.** No
PHP changed, so the 228-passed backend baseline above is unaffected by
construction. This session has no browser access, so "manual verification"
below is a code-level review (reading the final files end to end, tracing
the prop/watch/emit chain by hand, confirming no unclosed tags or type
mismatches) — **not** an actual click-through in a running app. The
checklist the task asked for still needs a real pass in the browser
before calling this done:

- [ ] Open a task's detail view from the board — should land on the
  read-only view first (title, status badge, description, assignee, due
  date, project, discussion), never straight into the edit form.
- [ ] Click Edit (as a manager) — form should appear in place; Cancel
  should return to the read-only view without submitting; Save should
  persist and return to view mode showing the updated fields.
- [ ] Post a comment — should appear in the thread without a manual page
  refresh, composer should clear, list should auto-scroll to the new
  entry.
- [ ] Rapidly press Enter/Send several times while a post is in flight —
  only one comment should be created (`form.processing` guard should
  hold — traced in code, not exercised live).
- [ ] Reload the page after posting — comment should still be present.
- [ ] As a Project Member (not Manager): can view the task and post/read
  comments, cannot see the Edit button, can delete only their own
  comment. As Owner/Admin: full edit access, can delete any comment.
- [ ] Force a failed comment post (e.g. offline) — error toast should
  appear via `useNotificationStore`, composer content should be
  preserved, not cleared.

If any of these don't hold up in the browser, flag it and it can be
fixed directly rather than assumed correct from the code review alone.

Full suite after entry #15 (FR21–FR22 in-app notification center):

```
php artisan test --compact
Tests:    249 passed (825 assertions)

vendor/bin/pint --dirty --format agent
→ passed (auto-fixed one unused import in a new test file)

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.39s (no errors)
```

Adds 4 new cases to `MeetingNotificationTest.php`, plus new
`TaskNotificationTest.php` (11 tests) and `NotificationCenterTest.php`
(6 tests) — 21 new tests over entry #13's 228 baseline. Unlike entry #14,
this pass **did** run the full verification suite per the task's own
instruction — no outstanding manual-browser-verification checklist for
the backend/data layer, since it's fully covered by automated tests. The
bell dropdown's visual rendering itself is still unverified in a live
browser (no browser access in this session), same caveat as entry #14.

Full suite after entry #16 (FR18–FR19 archive and search):

```
php artisan test --compact
Tests:    262 passed (997 assertions)

vendor/bin/pint --dirty --format agent
→ passed (auto-fixed import order in the new test file)

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.40s (archive/index.vue present in public/build/manifest.json)
```

Adds `tests/Feature/Archive/ArchiveSearchTest.php` (13 tests) — 13 new
over entry #15's 249 baseline. The union-query approach, the SQLite
`datetime()` past-meeting filter, the accessible-project scoping, and the
inaccessible-project-id-returns-empty behavior are all exercised by
automated tests, not just code review. The archive page's visual
rendering (filter bar layout, table density, pagination control) is
unverified in a live browser, same standing caveat as entries #14/#15 —
no browser access in this session.

Full suite after entry #17 (FR20 analytics):

```
php artisan test --compact
Tests:    276 passed (1175 assertions)

vendor/bin/pint --dirty --format agent
→ passed

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.34s (analytics/index.vue present in public/build/manifest.json)
```

Adds `tests/Feature/Analytics/AnalyticsTest.php` (14 tests) — 14 new over
entry #16's 262 baseline. Also re-ran `ArchiveSearchTest.php` and
`ProjectTest.php` in full after the `Workspace::accessibleProjectsFor()`/
`Meeting::scopePast()` refactor (both stayed green, confirming the
extraction changed nothing observable). The analytics page's visual
rendering — stat card grid, bar-list proportions, project performance
table — is unverified in a live browser, same standing caveat as
entries #14/#15/#16.

Full suite after entry #18 (FR25 notification preferences):

```
php artisan test --compact
Tests:    286 passed (1239 assertions)

vendor/bin/pint --dirty --format agent
→ fixed (fully_qualified_strict_types + ordered_imports in the new test
  file, on the first run); passed clean on the re-run after

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.49s (settings/Notifications.vue present in
  public/build/manifest.json as Notifications-*.js)
```

Adds `tests/Feature/Notifications/NotificationPreferenceTest.php`
(10 tests) — 10 new over entry #17's 276 baseline. Also re-ran
`MeetingNotificationTest.php`, `TaskNotificationTest.php`, and
`NotificationCenterTest.php` (31 tests total) after wiring
`NotificationPreferenceGate` into all seven dispatch points across the
Meetings and Tasks modules — all 31 stayed green with zero changes,
confirming the default-enabled behavior is identical to before this FR
for any user who never touches the new settings page. The notification
preferences settings page's visual rendering (checkbox grid layout,
grouped sections) is unverified in a live browser, same standing caveat
as entries #14/#15/#16/#17.

Full suite after entry #19 (FR26 profile picture):

```
php artisan test --compact
Tests:    298 passed (1294 assertions)

vendor/bin/pint --dirty --format agent
→ passed

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.42s (Profile-*.js grew with the new AvatarSettings.vue
  component bundled in)
```

Adds `tests/Feature/Settings/AvatarTest.php` (12 tests) — 12 new over
entry #18's 286 baseline. Also re-ran `ProfileUpdateTest.php`,
`DashboardTest.php`, and `TeamMemberTest.php` in full after the
`UserFactory` fix described in entry #19 — all green. The avatar upload
UI (file picker button, progress bar, remove button, current-avatar
preview) is unverified in a live browser, same standing caveat as every
other UI-heavy entry in this log — worth prioritizing in the overdue
browser pass since this is the first entry to add a real `<input
type="file">` interaction, which is harder to fully trust from code
review alone than a read-only display.

Full suite after entry #20 (FR28 audit log):

```
php artisan test --compact
Tests:    317 passed (1423 assertions)

vendor/bin/pint --dirty --format agent
→ passed

npm run lint:check
→ 0 errors

npm run build
✓ built in 2.49s (audit/index.vue present in public/build/manifest.json)
```

Adds `tests/Feature/Audit/AuditLoggingTest.php` (9 tests) and
`tests/Feature/Audit/AuditLogViewTest.php` (10 tests) — 19 new over
entry #19's 298 baseline. Also re-ran the full Workspace, Teams,
Projects, Tasks, and Meetings suites (192 tests total) after threading a
new `User $actor` parameter through 11 Actions that previously lacked
one — all green, confirming every controller call site was updated
consistently and no existing behavior regressed.
`tests/Unit/ModuleBoundaryTest.php` — a pre-existing architectural
contract this session hadn't touched before — caught a real placement
mistake (`AuditAction` at the module root instead of inside `Data/`)
immediately on the first full-suite run; fixed and reverified. The audit
log settings-hub card and index page are unverified in a live
browser, same standing caveat as every other UI-heavy entry in this log.

## Known gaps worth flagging

- `resources/js/pages/workspace/settings/RoleManagement.vue` calls
  `workspaceRoute('workspace.roles.update', selectedRoleId)` with a bare
  ID — the same bug class as fixes #2/#4, not yet fixed (out of scope for
  the tasks that touched it).
- **Drag-and-drop has no keyboard or touch fallback.** The per-card status
  buttons were removed as requested; status can now only change by mouse
  drag or (for Owner/Admin) isn't exposed any other way either. Worth a
  follow-up (e.g. a status control inside the read-only/edit task modal)
  if touch/keyboard users are a real constituency for this app.
- FR14-04 ("default to my tasks, with an option to view all") is not
  implemented — the board always shows every task in the project.
- FR16-02 ("status changes reflect immediately across all active users'
  dashboards") has no live push — other open browser tabs only see a
  status change after their own next page load. Would need broadcasting
  (Echo/Pusher/Reverb), which hasn't been introduced.
- FR15-01 (auto-create tasks from a distributed meeting summary) is
  intentionally not built — it depends on the entire meetings/AI-summary
  pipeline (FR05–FR13, of which FR05–FR09 now exist).
- The **Activity** tab on the project page is still a visual placeholder
  only (`AppEmptyState`, no route/data behind it) — out of scope for the
  meetings domain.
- Meetings still have no participant list or RSVP — the meeting fields
  spec across FR05–FR09 never included participants, so none of that was
  built. A meeting is visible to (and, as of FR23, emailed to) every
  project member, not a specific invited subset. This is a deliberate
  reading of "relevant project members," not a gap in FR23 itself, but
  would need revisiting if the report expects opt-in/opt-out per meeting.
- No Zoom/Meet integration, recording, transcription, or AI summary
  (FR10–FR13) — `meeting_link` is a free-text URL the creator pastes in
  and the Join Meeting button just opens it in a new tab, exactly as
  scoped ("Do not implement Zoom integration/WebRTC/recording... yet").
- ~~2 pre-existing `TaskTest` failures~~ — fixed in entry #8; the full
  suite is green again.
- **No custom project roles** — only the fixed `manager`/`member` pair,
  by explicit instruction for this task. If workspaces later want the same
  "define your own role + permissions" flexibility `WorkspaceRole` gives
  at the workspace level, that's the natural follow-up (a
  `project_roles`-style table), not a redesign of what shipped here.
- **Adding project members is one-at-a-time**, no multi-select/bulk-assign
  UI. Fine for the current member-count scale; would want a checklist-style
  picker if workspaces grow large.
- **The projects index page's empty state doesn't distinguish "this
  workspace has no projects" from "you have no projects assigned to
  you."** Both render the same generic "No projects yet" copy
  (`resources/js/pages/projects/index.vue`) — a member who's simply
  unassigned from everything sees the same message as a genuinely empty
  workspace. Minor UX polish, not a security issue (the underlying access
  control is correct either way).
- **`WorkspacePermission`'s `ProjectsView`/`ProjectsCreate`/
  `ProjectsDelete` cases are still unused** by `ProjectPolicy` (pre-existing,
  unrelated to entry #8) — custom `WorkspaceRole`s can't currently grant
  project access more granularly than the fixed `UserRole` rank check.
  Noted here because it's adjacent to project-level access control and
  the next person touching this area will likely wonder why those enum
  cases exist but do nothing.
- ~~`MeetingPolicy` was intentionally left untouched by entry #8~~ — fixed
  in entry #9; `MeetingPolicy` now uses the same
  `workspace admin-rank OR project role-rank` model as `ProjectPolicy`/
  `TaskPolicy`.
- **Meetings still has no Manager-vs-Member distinction beyond
  create/update/delete vs. view** — unlike Tasks (which has an
  assignee-only `updateStatus` ability), a meeting has no per-user partial
  action, so `MeetingPolicy` only needed the two tiers Tasks already uses
  for its non-status abilities. Flagging simply so a future FR (e.g.
  "participants can RSVP") isn't mistaken for something already covered.
- **The Join Meeting button opens whatever URL is stored, unvalidated
  beyond scheme.** `isValidMeetingLink()` only checks for `http:`/`https:`
  — it doesn't check the link is actually reachable or actually a
  video-call URL. Acceptable for "no Zoom API yet" scope; would need
  revisiting if/when a real provider integration (FR10+) starts trusting
  this field more.
- **No dedicated "join" page or countdown/reminder UI** — FR07 was read
  literally as "open the stored link," not a lobby/waiting-room
  experience. If the FYP report's grading rubric expects more than a
  same-tab-safe external link, that's a follow-up, not something this
  task silently expanded into.
- ~~FR23 is email-only~~ — FR21–FR22 (entry #15) added the in-app
  notification bell/feed alongside the existing FR23 emails; meetings now
  notify through both channels from the same recipient resolution.
  ~~Still no user-facing preference to opt out of either channel~~ — FR25
  (entry #18) added a per-type/channel opt-out in user settings.
- **The notification bell has no live push** — it refreshes on the next
  Inertia navigation or on the explicit partial-reload fired by mark-
  read/mark-all-read, not in real time while the page sits idle. Same
  "no WebSockets/Echo/Reverb yet" boundary as everywhere else in this app
  (see FR16-02's gap above); would need broadcasting to go live.
- **The 10-most-recent-notifications list has no pagination or "view
  all" page**, per the task's own "don't build a large standalone
  notification page unless required" instruction. Older read notifications
  simply age out of the dropdown (they're still in the database, just not
  surfaced) — would need a dedicated index route if FR21/FR22's grading
  expects a full history view.
- **Mail delivery is only verified against `Mail::fake()` in tests and
  `MAIL_MAILER=log` in dev** (`.env.example`) — nobody has exercised a
  real SMTP/API provider (Postmark, SES, etc.) with these templates yet.
  The Blade views use the same table-based email-client-safe markup
  `member-invitation.blade.php` already established, but that pattern
  itself has also only ever been visually spot-checked, not tested across
  real inboxes (Gmail/Outlook rendering quirks).
- **No retry-exhaustion handling beyond Laravel's default.** Each
  Mailable sets `tries = 3` / `backoff = 30` like `MemberInvitationMail`,
  but nothing observes a mail job that fails all 3 tries (no `failed()`
  method, no alert). Acceptable for now since Log::error already fires
  at the *dispatch* try/catch layer for synchronous failures; a job that
  fails asynchronously after being successfully queued would currently
  only show up in Laravel's `failed_jobs` table, unmonitored.
- **The same stale-prop-reference bug fixed for `taskModalTarget` in
  entry #14 plausibly exists for `meetingModalTarget` and
  `deleteTaskTarget`/`deleteMeetingTarget`/`deleteColumnTarget` too** —
  none of those were audited or fixed this pass, since the task was
  explicitly scoped to the task comment/detail flow. If a meeting's
  detail modal is ever given the same kind of live-updating child data
  (e.g. participants, or comments later), it should get the same
  `watch(() => props.X, ...)` re-sync `show.vue` already has for tasks.
- **Entry #14's task-detail layout has not been visually verified in a
  browser** — see the checklist in Test results above. The redesign and
  bug fix are code-reviewed and internally consistent, but this session
  had no way to actually render and click through it.
- **No custom project roles** — only the fixed `manager`/`member` pair,
  by explicit instruction for this task. If workspaces later want the same
  "define your own role + permissions" flexibility `WorkspaceRole` gives
  at the workspace level, that's the natural follow-up (a
  `project_roles`-style table), not a redesign of what shipped here.
- **Adding project members is one-at-a-time**, no multi-select/bulk-assign
  UI. Fine for the current member-count scale; would want a checklist-style
  picker if workspaces grow large.
- **The archive's "past meeting" filter uses SQLite-specific raw SQL**
  (`datetime(scheduled_at, '+' || duration_minutes || ' minutes')` in
  `SearchArchiveAction::pastMeetingsQuery()`), matching this app's
  exclusively-`sqlite` configuration (`.env.example`, `phpunit.xml`, no
  other driver referenced anywhere). If the app is ever pointed at
  MySQL/Postgres, this one `whereRaw()` call needs a driver-aware
  rewrite (e.g. `DATE_ADD`/`+ INTERVAL`) — flagged explicitly so it isn't
  a silent production breakage.
- **Archive search is `LIKE '%term%'` via the query builder**, not a
  dedicated search engine — explicitly in scope per this task's own "no
  Elasticsearch/Meilisearch unless already present" instruction. Fine at
  current data volumes (a `%term%` scan over a per-workspace-scoped
  union of two tables); would need revisiting if any workspace's
  task/meeting history grows into the tens of thousands of rows.
- **The archive has no "type" counts on its All/Tasks/Meetings segmented
  control** (unlike the Teams/Projects list pages' filter tabs, which
  show counts computed from an already-in-memory dataset). Computing
  accurate counts here would need two extra `COUNT(*)` queries scoped by
  the *other* active filters, which felt like scope creep beyond "record
  type" as a filter — the segmented control still fully works as a
  filter, it just doesn't preview how many results each tab holds.
- **Deep-linking from an archive result (`?task=`/`?meeting=` on the
  project URL) only works if the target task/meeting is still present in
  the project's already-loaded `tasks`/`meetings` props** — true for
  every real case (nothing deletes tasks/meetings other than an explicit
  user action), but if a linked record was deleted between archiving and
  clicking, the link silently lands on the project's board/meetings tab
  with no modal and no "not found" message, rather than an explicit error.
- **The archive page has not been visually verified in a browser** — no
  browser access in this session, same standing caveat as entries #14/
  #15's UI work. The filter bar, table density, and pagination control
  are code-reviewed and covered by backend tests, not click-tested.
- **Analytics' date range filter only affects meetings, not task
  metrics** — a deliberate choice (see entry #17), not a bug, but worth
  flagging so nobody "fixes" it into applying to tasks without first
  deciding what date field that should even mean, given tasks have no
  `completed_at` column.
- **"Tasks by workflow column" groups by column *name* across projects**,
  not by a stable per-project column identity. Two different projects'
  columns both named "In Progress" merge into one bar; a custom column
  with a unique name gets its own bar. This matches the common case
  (every project starts with the same three default column names) but
  would read oddly for a workspace that renamed columns inconsistently
  across projects — flagged as a known simplification, not fixed, since
  a "by project + column" cross-tab was judged more detail than a
  workspace-wide summary card needs.
- **No caching on analytics queries**, per the task's own "do not cache
  yet unless there's a demonstrated need" instruction. Every page load
  re-runs the full aggregate set. Fine at current data volumes (a handful
  of indexed `COUNT`/`GROUP BY` queries per project scope); would be the
  first thing to add if a workspace's task/meeting volume grows large
  enough to make the page feel slow.
- **The analytics page has not been visually verified in a browser** — no
  browser access in this session, same standing caveat as every other
  UI-heavy entry in this log. The stat card grid, bar-list proportions,
  and project performance table are code-reviewed and covered by backend
  tests, not click-tested.
- **Notification preferences apply only to the six notification types that
  already exist** (three meeting, three task) — there is deliberately no
  row/UI for anything not yet built (mentions, digests, push, browser
  notifications). Adding a new notification type in the future requires
  adding a case to `NotificationType` and calling
  `NotificationPreferenceGate::filter()` at its dispatch point; it will
  not automatically appear in the settings UI as "supported" until that
  enum case declares its channels.
- **The settings `PUT` expects the full flattened preference list on every
  save**, not a partial diff — the frontend always submits every
  type/channel pair it rendered. This matches how the page is built (one
  form, one submit) but means a future UI that lets someone toggle a
  single row via an isolated request (e.g. an inline switch with instant
  save) would need a smaller, single-row endpoint instead of reusing
  `NotificationPreferenceController::update()` as-is.
- **The notification preferences settings page has not been visually
  verified in a browser** — no browser access in this session, same
  standing caveat as every other UI-heavy entry in this log.
- **Avatar display was wired only into places that already had
  `avatar_url` plumbed through** (Dashboard members/activity, Team
  roster, the header/sidebar user menu) — see entry #19. Task assignee
  avatars, task comment avatars, and project member list avatars still
  render initials-only, even for a user with a real uploaded photo,
  because those screens' underlying Data classes never carried an
  `avatar_url` field in the first place. Extending them is a legitimate,
  reasonably-sized follow-up (each is a one-field DTO addition plus a
  `:src` prop on an existing `AppAvatar` call) but was judged outside
  "where practical" for this task's scope.
- **`resources/js/components/AppHeader.vue` still references the dead,
  pre-FR26 `user.avatar` field.** Confirmed unreachable (no active layout
  imports it — `AppHeaderLayout` itself is unused), so left alone rather
  than patched; worth deleting outright in a future cleanup pass rather
  than fixing code nothing renders.
- **No image cropping/resizing.** An uploaded image is stored and served
  exactly as uploaded (beyond browser-side `object-cover` CSS framing in
  `AppAvatar`) — a very large but valid image (up to the 2 MB cap) will
  render at whatever dimensions it has. Per the task's own "do not add
  image cropping libraries" instruction; would need either a client-side
  canvas resize or a server-side image library (e.g. Intervention Image)
  if this becomes a real problem.
- **The avatar upload UI has not been visually verified in a browser** —
  no browser access in this session. This one is worth prioritizing over
  the other pending browser-verification items, since it's the first
  entry with a real file-picker/upload-progress interaction rather than
  a read-only display.
- **Audit logging covers the events explicitly listed in the FR28 task**,
  not every mutation in the app — custom workspace role CRUD
  (`Create/Update/DeleteWorkspaceRoleAction`), workspace invitation
  resend/revoke, task comment create/delete, and account deletion
  (`ProfileController::destroy`) are not audited, since none were in the
  requirement's explicit event list. Adding any of them later is a small,
  additive change: one `RecordAuditLogAction` call plus a new
  `AuditAction` case, following the exact pattern entry #20 already
  established everywhere else.
- **Board column rename isn't audited** because it doesn't exist as a
  feature yet — there is no `UpdateBoardColumnAction` in this codebase
  (columns can only be created, deleted, and reordered after creation).
  If rename is ever added, it needs its own `board_column.renamed`
  `AuditAction` case alongside it.
- **No export/PDF and no realtime streaming**, per the task's own explicit
  exclusions. The page is a standard server-paginated, filtered table —
  same shape as Archive (entry #16) — with no download or live-tail
  capability.
- **The audit settings-hub card and index page have not been visually
  verified in a browser** — no browser access in this session, same
  standing caveat as every other UI-heavy entry in this log.

### 27. FR04-03, FR04-05, FR06-01, FR32-05 — the dashboard becomes real

Implemented the four unblocked dashboard clauses identified in entry #26.
No new tables, no sprint entity, no policy or route changes.

**Scoping — one predicate, fetched before any data.**
`DashboardController::__invoke` now resolves
`$workspace->accessibleProjectsFor($user)` once and derives everything from
it: the meeting queries take `whereIn('project_id', $accessibleProjectIds)`,
and the task/project aggregates are produced by handing that same
`Collection<Project>` to `BuildAnalyticsAction::handle($accessibleProjects,
[])`. `accessibleProjectsFor()` is therefore still the single source of
"which projects can this user see" (entry #17's consolidation holds — the
dashboard is now its fourth consumer alongside Projects, Archive and
Analytics). An unassigned workspace member resolves to an empty collection
and the meeting query short-circuits before touching the database.

**FR04-05 and FR32-05 reuse the analytics aggregates rather than
re-querying.** `taskProgress` is a projection of `AnalyticsData`
(`total_tasks`, `completed_tasks`, `open_tasks`, `overdue_tasks`,
`task_completion_percentage`, `tasks_by_column`), and `projects` is its
existing `ProjectSummaryData[]` (id, name, total/completed tasks,
completion percentage). No aggregate SQL was written for this entry.

**"Sprint" is deliberately not modelled.** FR04-05 says "sprint task
completion widget", but no sprint entity exists anywhere in the schema and
the task explicitly forbade adding one. The widget therefore reports
completion across the **current accessible-project task set**, grouped by
board column. If the architecture later gains a real sprint model, the
widget's scope changes from "all accessible tasks" to "tasks in the active
sprint" and nothing else about it needs to move. FR20-02 carries the same
limitation and stays Partial for the same reason.

**FR04-03 / FR06-01 — meetings, bounded.** New
`DashboardMeetingData` (`app/Modules/Workspace/Data/`) is a deliberately
narrow projection: id, title, project id/name, `scheduled_at`,
`duration_minutes`, `join_url`, `is_past`, `url`. It is not `MeetingData` —
the dashboard has no use for description, `created_by`, `workspace_id` or
timestamps, so they are not sent. Two lists are returned, `upcomingMeetings`
(ascending) and `pastMeetings` (descending), each capped by a new
`workspace.dashboard_meeting_limit` config (default 5) so the dashboard
cannot degrade into a full meeting history. Both use the existing
`Meeting::scopeUpcoming()` / `scopePast()` rather than restating the
duration arithmetic.

`join_url` is populated **only** when `Meeting::hasValidJoinLink()` passes
(`http://` / `https://`), so a stray `javascript:` link can never reach a
`target="_blank"` anchor — the same server-side guard entry #11 introduced
for mail, now applied to the dashboard. `url` deep-links to
`workspace.projects.show?meeting={id}`, reusing the query-param modal opener
Archive already established in entry #16, so clicking a meeting opens its
detail modal rather than needing a new route.

**Frontend.** Three new components under `components/dashboard/`:
`TaskProgressCard` (percentage, progress bar, open/overdue counts, and a
per-column breakdown through the existing `AppBarList`),
`ProjectSummaryList` (name, done/open counts, mini progress bar, link to the
project), and `UpcomingMeetingsCard` (Upcoming and Recently held sections, a
Join button only when `join_url` is present, and a real empty state). Each
carries its own exported prop interface, which `Dashboard.vue` imports
instead of redeclaring shapes.

**Placeholder removed.** The hardcoded `ComingNextCard` ("AI sprint
planning / Join the waitlist") that occupied the right column is gone,
replaced by the real meetings card. `ComingNextCard.vue` itself is left in
place — it is a generic presentational component, not fabricated data, and
nothing else on this page was redesigned. The onboarding checklist's "Run
your first sprint" step still reads from `first_sprint_run`, which
`DashboardController::onboarding()` hardcodes to `false`; it is untouched
here because it belongs to the onboarding section, not to these four
clauses, but it can never complete and is worth revisiting.

**Tests**: new `tests/Feature/DashboardWidgetsTest.php` (15) — upcoming
meeting with project name and join link; past/upcoming separation; an
invalid `javascript:` link is not exposed; meetings from an inaccessible
project are absent (with `assertDontSee` on the title); cross-workspace
meetings never appear; the configured limit is honoured; task completion
counts and percentage; the zero-task state; per-project summary counts;
Admin sees every project; project Manager and project Member each see only
their assigned project; an unassigned workspace member gets empty
projects/meetings and a zero task total; and task progress excludes
inaccessible projects. One assertion was wrong on first run — `MeetingFactory`
seeds a random valid URL, so the "past meeting has no join link" case had to
set `meeting_link` explicitly rather than rely on the factory default.

**Verification**: `php artisan test --compact` → **368 passed, 2052
assertions** (354 baseline + 14 net). `vendor/bin/pint --dirty --format
agent` → passed. `npm run lint:check` → clean. `npm run build` → built in
2.70s. `npx vue-tsc --noEmit` → the same two pre-existing `TS2688`
`tsconfig.json` `types` errors as entry #24, unchanged and unrelated; no
source file is implicated.

### 28. FR14-04 — task board defaults to "my tasks"

The last long-standing unblocked board gap, flagged as far back as entry #6.
Frontend only: no route, controller, policy, migration or payload change.

**Filtering lives inside `KanbanBoard`, not in the page.** The board keeps
`localTasks` — an independent deep copy that makes drag-and-drop optimistic
(entry #6) — and re-syncs it whenever `props.tasks` changes. Passing a
*pre-filtered* array down from `show.vue` would have made every toggle look
like fresh server data and thrown away that local state mid-interaction.
Instead the board takes a `scope: 'mine' | 'all'` prop (defaulting to `all`,
so any other caller is unaffected) and derives a `visibleTasks` computed that
the `columns` computed then groups. `localTasks` stays the complete set, so
toggling the view never disturbs an in-flight move, and per-column counts
follow the filter for free.

**The page owns the control.** `show.vue` holds `taskScope`, defaulting to
`'mine'` on every load. The choice is deliberately **not persisted** —
FR14-04 says the user's own tasks are what the board shows *by default*, and
remembering a previous "all" selection would make that false on the second
visit. The toggle is the existing `AppSegmentedControl` (already used by
Archive and Teams), showing live counts for both options, and it replaces the
redundant "N tasks" line that sat in the same header slot.

**Empty-personal-board case.** Defaulting to "mine" means a user with no
assignments would otherwise land on a board of empty columns and reasonably
conclude the project has no work in it. When the personal set is empty but
the project has tasks, an inline notice states how many tasks belong to the
team and offers a one-click "View all tasks". Individual columns also read
"Nothing assigned to you." rather than "No tasks here." while the filter is
on, so the distinction between *filtered out* and *absent* is never
ambiguous.

**Authorization unchanged.** This is a view filter over data the viewer was
already entitled to — `ProjectController::show` still returns the project's
tasks scoped by `ProjectPolicy::view`, and `canDrag()` still gates dragging
on manage-rights or assignment. Nothing was hidden from the server side and
nothing new was exposed.

**Tests.** The filter is client-side and this project has no JS test runner
(unchanged since entry #10), so the behaviour itself is covered by ESLint,
`vue-tsc` and a production build plus review. What *is* now guarded is the
payload contract the filter depends on: new
`test_the_board_payload_carries_the_assignee_of_every_task` in `TaskTest`
asserts the project page exposes `auth.user.id` and a correct `assigned_to`
for both an assigned and an unassigned task. If `assigned_to` were ever
dropped from `TaskData`, the filter would silently show an empty board; this
test fails instead.

**Verification**: `php artisan test --compact` → **369 passed, 2067
assertions** (368 baseline + 1). `vendor/bin/pint --dirty --format agent` →
passed. `npm run lint:check` → clean. `npm run build` → built in 2.64s.
`npx vue-tsc --noEmit` → the same two pre-existing `TS2688` `tsconfig.json`
errors, unchanged.

### 29. FR26-02 — preview before the avatar upload commits

`AvatarSettings.vue` previously POSTed the moment a file was chosen or
dropped, so FR26-02's "display a preview, and upload it upon confirmation"
had no confirmation step at all. Frontend only — `AvatarController`,
`AvatarUpdateRequest` and the routes are untouched.

**Select → preview → confirm.** The former `upload()` split into
`selectFile()` (validate, then stage) and `confirmUpload()` (post). Both
entry points, the file input and the drop handler, now stage rather than
send. The staged file is held in `pendingFile` with an object-URL
`previewUrl`, and the avatar renders `previewUrl ?? user.avatar_url`, so the
user sees the actual image in its final circular crop before committing.
The existing client-side type and size checks still run at selection time,
so an invalid file is rejected before it can be previewed or sent.

**Object URLs are released deliberately.** `releasePreview()` revokes the
current URL and is called when a replacement file is chosen, on cancel,
after a successful upload (`onSuccess: cancelSelection`, at which point the
server-rendered `avatar_url` takes over), and from `onBeforeUnmount`. A
blob URL survives until revoked, so leaving this to the garbage collector
would leak the image for the life of the tab.

**Actions swap with state.** With nothing staged the card shows
Replace/Upload plus Remove, as before. With a file staged it shows **Save
photo** (spinner and "Saving…" while in flight), **Choose another**
(re-opens the picker without discarding the card's state) and **Cancel**
(discards and restores the saved photo). Remove is hidden while a selection
is pending, since deleting the stored photo mid-preview would be ambiguous.
The avatar gains a primary-coloured ring while staged, and the helper copy
switches to "Preview — not saved yet", so an unsaved state is never mistaken
for a saved one.

**Tests.** No backend surface changed, so no new backend test would assert
anything new; `tests/Feature/Settings/AvatarTest.php` (19 assertions across
the settings suite) still passes untouched and continues to cover the upload,
removal and validation rules the confirm step now feeds. The interaction
itself is client-side and this project still has no JS test runner, so it is
covered by ESLint, `vue-tsc` and a production build plus review — the same
standard as every other UI-only entry in this log.

**Verification**: `php artisan test --compact` → **369 passed, 2067
assertions** (unchanged, as expected). `vendor/bin/pint --dirty --format
agent` → passed. `npm run lint:check` → clean. `npm run build` → built in
2.69s. `npx vue-tsc --noEmit` → the same two pre-existing `TS2688`
`tsconfig.json` errors, unchanged.

## Next recommended task (updated 2026-08-17, after entry #29)

**FR28-01 — audit user account changes.**

The audit log covers workspace, member, project, task, board-column and
meeting events but records **nothing** when a user changes their own
account. Add `AuditAction` cases and `RecordAuditLogAction` calls for the
profile update, password change, avatar update/removal and account deletion
paths in `app/Http/Controllers/Settings/`, following the pattern entry #20
established everywhere else. Each is one enum case plus one call.

One design question to settle first: those controllers are **not**
workspace-scoped, while `audit_logs` rows are. Either record the change
against the user's current workspace, or accept that account events are
workspace-agnostic and need a nullable workspace column. The former is the
smaller change and matches how `AuditLogPolicy` already scopes reads.

Remaining unblocked order, easiest → hardest: **FR28-01** → FR20-05 →
FR31-04 → FR05-03 → FR05-05 → FR35-01/FR35-02-widgets → FR16-02 → FR20-02.

**Still decide before building:** FR02 and FR27 are Design Mismatches where
amending the report is recommended, and FR05-03 hinges on whether external
(non-account) meeting participants are in scope. See entry #26, Group 3.

**Do not schedule** Group 2 items independently — they land with FR10–FR13.
FR04, FR15, FR19, FR22 and FR23 contain only blocked work.

Two standing items unchanged: `tsconfig.json`'s `compilerOptions.types` has
listed two unresolvable entries since the initial commit, so
`vue-tsc --noEmit` exits non-zero even on a clean tree (entry #24); and a
visual browser pass is still owed for entries #14–#20, #22–#24 and #27–#29.
The avatar flow is the highest-value one to click-test, since it is the only
screen in the app with a real file-picker interaction.

## Superseded next-task note (after entry #28)

**FR26-02 — preview the selected image before uploading it.**

Smallest remaining unblocked clause and entirely self-contained:
`AvatarSettings.vue` currently uploads the moment a file is chosen or
dropped, whereas FR26-02 asks for a preview followed by upload "upon
confirmation". One component, no backend change — `AvatarController` and
`AvatarUpdateRequest` already do exactly what is needed.

After that, **FR28-01's account-change audit events** (S): add
`AuditAction` cases and `RecordAuditLogAction` calls in the
`Settings\*Controller` classes, following the pattern entry #20 established
everywhere else. Profile updates, password changes, avatar changes and
account deletion are currently audited nowhere.

Remaining unblocked order, easiest → hardest: **FR26-02** → FR28-01 →
FR20-05 → FR31-04 → FR05-03 → FR05-05 → FR35-01/FR35-02-widgets → FR16-02 →
FR20-02.

**Still decide before building:** FR02 and FR27 are Design Mismatches where
amending the report is recommended, and FR05-03 hinges on whether external
(non-account) meeting participants are in scope. See entry #26, Group 3.

**Do not schedule** Group 2 items independently — they land with FR10–FR13.
FR04, FR15, FR19, FR22 and FR23 contain only blocked work.

Two standing items unchanged: `tsconfig.json`'s `compilerOptions.types` has
listed two unresolvable entries since the initial commit, so
`vue-tsc --noEmit` exits non-zero even on a clean tree (entry #24); and a
visual browser pass is still owed for entries #14–#20, #22–#24, #27 and #28.

## Superseded next-task note (after entry #27)

**FR14-04 — default the task board to "my tasks", with a view-all toggle.**

It is the largest remaining unblocked clause that needs no new decision:
no schema change, no new authorization, no product question. The board
already receives every task it needs in `ProjectController::show`; this is a
default filter plus a toggle in `KanbanBoard.vue`, and it is a long-standing
known gap (flagged as far back as entry #6).

Two smaller items can be banked first if preferred: **FR26-02** (preview
before avatar upload, XS) and **FR28-01** account-change audit events (S).

Remaining unblocked order, easiest → hardest: FR26-02 → FR28-01 → **FR14-04**
→ FR20-05 → FR31-04 → FR05-03 → FR05-05 → FR35-01/FR35-02-widgets → FR16-02
→ FR20-02.

**Still decide before building:** FR02 and FR27 are Design Mismatches where
amending the report is recommended, and FR05-03 hinges on whether external
(non-account) meeting participants are in scope. See entry #26, Group 3.

**Do not schedule** Group 2 items independently — they land with FR10–FR13.
FR04, FR15, FR19, FR22 and FR23 now contain only blocked work.

Two standing items unchanged: `tsconfig.json`'s `compilerOptions.types` has
listed two unresolvable entries since the initial commit, so
`vue-tsc --noEmit` exits non-zero even on a clean tree (entry #24); and a
visual browser pass is still owed for entries #14–#20, #22–#24 and now #27.

## Superseded next-task note (after entry #26)

**Build the workspace dashboard's real content: FR04-03 + FR06-01, then
FR04-05 and FR32-05.**

One surface closes four unblocked sub-requirements across three FRs. The
dashboard is currently the weakest page in the app — a stat row, an
onboarding checklist, an activity feed and a hardcoded "AI sprint planning /
Join the waitlist" placeholder — while the report expects it to be the
product's hub (FR04, FR06-01, FR32-05, and the navigation target of FR01-06).
Nothing here needs new tables: upcoming meetings come from
`Meeting::upcoming()` scoped through `Workspace::accessibleProjectsFor()`,
the completion widget from the aggregates `BuildAnalyticsAction` already
computes, and the project list from `accessibleProjectsFor()` plus a task
count. It is one controller, three props, three components.

**Unblocked order, easiest → hardest:**

1. FR26-02 — preview before avatar upload (XS)
2. FR28-01 — audit user account changes (S)
3. **FR04-03 + FR06-01 — upcoming meetings on the dashboard (S)** ← next
4. FR04-05 — sprint task completion widget (S)
5. FR32-05 — projects + task summary on the dashboard (S)
6. FR14-04 — "my tasks" default with a view-all toggle (S–M)
7. FR20-05 — team-wide vs personal analytics by role (M)
8. FR31-04 — shareable workspace invite link (M, security design)
9. FR05-03 — meeting participants (M–L, new domain concept)
10. FR05-05 — generated meeting link (M–L, provider decision)
11. FR35-01 / FR35-02 widgets — role-distinct dashboard (L, needs the
    `WorkspacePermission` enforcement decision first)
12. FR16-02 — realtime propagation (L, new infrastructure)
13. FR20-02 — "current sprint" (L, no sprint entity exists)

**Decide before building anything for them:** FR02 and FR27 are Design
Mismatches where amending the report is the recommended resolution, and
FR05-03 hinges on whether external (non-account) meeting participants are in
scope. See entry #26, Group 3.

**Do not schedule** the Group 2 items independently — they land with
FR10–FR13.

Two standing items unchanged: `tsconfig.json`'s `compilerOptions.types` has
listed two unresolvable entries since the initial commit, so
`vue-tsc --noEmit` exits non-zero even on a clean tree (entry #24); and a
visual browser pass is still owed for entries #14–#20 and #22–#24.

## Superseded next-task note (after entry #25)

**Implement FR04-03 and FR04-05 — the dashboard's meetings list and sprint
task completion widget.**

Rationale: of the five remaining Partial FRs, FR04 is the only one whose
missing pieces are (a) genuinely required by the report, (b) not blocked by
FR10–FR13, and (c) buildable from data the application already stores.
`DashboardController` already has the workspace in scope; upcoming meetings
come from `Meeting::upcoming()` scoped to `accessibleProjectsFor()`, and the
completion widget from the same task/board-column counts the Analytics
module already aggregates. It is one controller, two props, two components.
FR04-04 stays out of scope until the summary pipeline exists.

The other four Partial FRs are each blocked on something other than effort:

- **FR02** and **FR27** are report-vs-design mismatches where the current
  implementation is the better engineering (signed reset links; per-workspace
  roles). Both need a **decision to amend the report**, not code. Do this
  before writing anything for them.
- **FR31-04** (open shareable invite link) is a real feature but carries a
  security design question — an open link is bearer-authority to join a
  tenant, needing its own expiry, revocation and role cap.
- **FR35** needs the `WorkspacePermission` enforcement decision made first
  (entry #25 established this obligation is FR35's, not FR34's), plus
  FR12/FR13 for its summary-approval clause.

Ordered easiest → hardest: **FR04** → FR27 (wording) → FR02 (wording, or a
medium build) → FR31 → FR35 → FR10–FR13.

Two standing items unchanged: `tsconfig.json`'s `compilerOptions.types` has
listed two unresolvable entries since the initial commit, so
`vue-tsc --noEmit` exits non-zero even on a clean tree (entry #24); and a
visual browser pass is still owed for entries #14–#20 and #22–#24, none of
which has been click-tested in a running app.

## Previous next-task note (pre-entry #21)

Backend suite confirmed green at 317 passed as of entry #20 (FR28).
`vendor/bin/pint --dirty --format agent`, `npm run lint:check`, and
`npm run build` all pass clean. A visual browser pass is now owed for
seven separate pieces of UI work (entry #14's Task Detail redesign,
entry #15's notification bell, entry #16's archive page, entry #17's
analytics dashboard, entry #18's notification preferences settings page,
entry #19's avatar upload, entry #20's audit log page) — worth doing in
one sitting before committing, since none of them have been click-tested
in a running app yet. The avatar upload flow (real `<input
type="file">`, upload progress, replace, remove) is still the
highest-value one to check first, since a file-picker interaction is
harder to fully trust from code review alone than a read-only display
like the audit log table.

Back to the FR track: with FR14–FR26, FR28, FR29, and FR32 now all
complete, **FR10–FR13 (transcription + AI summary) are the entire
remaining "not started" list** — the last untouched block in the FYP1
report. This is the largest and riskiest remaining piece: it needs a
transcription provider decision (upload-based vs. live), storage for
transcripts/summaries, and an AI summarization call, on top of the
existing meeting infrastructure (FR05–FR09, complete since entry #10).
Worth scoping in a dedicated planning pass before writing code — what
"transcription" means given this app has no audio/video capture
infrastructure today (meetings are scheduled with an external join
link, not hosted in-app), and whether the FYP1 report's wording expects
an uploaded-recording flow or something else entirely.
