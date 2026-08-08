# SprintSync — Project Progress

Living tracker for FR implementation status, decisions, and what to build next.
Source of truth for FR wording: `SprintSync FYP1 Report.docx` (FR01–FR35).

## Current FR status

| Status | Count | FRs |
|---|---|---|
| Complete | 14 | FR01, FR05, FR06, FR07, FR08, FR09, FR14, FR15, FR16, FR17, FR23, FR24, FR29, FR32 |
| Partial | 9 | FR02, FR03, FR04, FR27, FR30, FR31, FR33, FR34, FR35 |
| Not started | 12 | FR10–FR13, FR18–FR22, FR25, FR26, FR28 |

Strict completion: **14 / 35 (40.0%)**. Weighted (Complete=1, Partial=0.5): **52.9%**.

The codebase is a real multi-tenant workspace product now: auth, workspaces,
invitations, custom roles, team management, projects, a task/Kanban board,
a complete meeting lifecycle (schedule, view details, join, edit, cancel),
and email notifications on every meeting lifecycle event are all built and
tested. Still missing: transcription + AI summary (FR10–FR13),
archive/search (FR18–FR19), analytics (FR20), in-app notifications +
notification preferences (FR21–FR22, FR25), profile pictures (FR26), and
audit log (FR28).

Projects, Tasks, and Meetings (FR32, FR14–FR17, FR05) now also have
**project-level membership and access control** layered underneath them —
see work-log entries #8 and #9. This is an authorization hardening of
those existing FRs, not a newly tracked FR number on its own (no line item
in the FYP1 report maps to it directly, as far as this session could tell
without re-reading the source `.docx`), so the table above is unchanged.

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
- **FR23 is email-only** — explicitly scoped that way ("do not build the
  full in-app notification center yet"). There's no in-app notification
  bell/feed (FR21–FR22) and no user-facing preference to opt out of
  meeting emails (FR25). Both are natural follow-ups once this ships.
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

## Next recommended task

The suite is 100% green (193 passed). Entries #7–#10 (Meetings, project
membership, meeting access control, meeting lifecycle UX) are already
committed; entry #11 (`app/Mail/Meeting*Mail.php`,
`resources/views/emails/meeting-*.blade.php`,
`ResolveMeetingRecipients.php`, the 3 meeting Actions,
`MeetingController.php`, `Meeting.php`, `MeetingNotificationTest.php`) is
the only uncommitted work as of this update — review and commit it before
starting anything else.

**FR18–FR19 — Archive/search**, or **FR20 — Analytics**, are the next
largest untouched blocks with no meetings/AI dependency, so either is a
reasonable next pick independent of FR10–FR13. If staying inside the
notifications thread instead, **FR21–FR22 (in-app notification
center)** is the natural next step now that FR23 proves out the
"who gets notified" recipient logic (`ResolveMeetingRecipients` is
reusable as-is) — an in-app feed would reuse the same trigger points
(the three meeting Actions' `notify()` methods) rather than only emailing.

Save FR10–FR13 (transcription/AI summary) for last — it's the hardest,
most novel piece and should come only once there's real meeting data
(and now, real notification infrastructure) to build against.
