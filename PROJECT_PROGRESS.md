# SprintSync — Project Progress

Living tracker for FR implementation status, decisions, and what to build next.
Source of truth for FR wording: `SprintSync FYP1 Report.docx` (FR01–FR35).

## Current FR status

| Status | Count | FRs |
|---|---|---|
| Complete | 9 | FR01, FR05, FR14, FR15, FR16, FR17, FR24, FR29, FR32 |
| Partial | 9 | FR02, FR03, FR04, FR27, FR30, FR31, FR33, FR34, FR35 |
| Not started | 17 | FR06–FR13, FR18–FR23, FR25, FR26, FR28 |

Strict completion: **9 / 35 (25.7%)**. Weighted (Complete=1, Partial=0.5): **38.6%**.

The codebase is a real multi-tenant workspace product now: auth, workspaces,
invitations, custom roles, team management, projects, a task/Kanban board,
and meeting scheduling are all built and tested. Still missing: viewing a
meeting's full details/join flow and edit-notice (FR06–FR09), transcription
+ AI summary (FR10–FR13), archive/search (FR18–FR19), analytics (FR20),
in-app/email notifications (FR21–FR23), notification preferences (FR25),
profile pictures (FR26), and audit log (FR28).

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

Running the **full** suite at the end of this task shows 2 pre-existing
failures in `tests/Feature/Tasks/TaskTest.php`
(`a_task_can_be_created_with_an_assignee`, `an_owner_can_update_a_task`):

```
php artisan test --compact
Tests:    2 failed, 144 passed (448 assertions)
```

These are **not caused by FR05**. `git status` shows `TaskPolicy.php`,
`StoreTaskRequest.php`, `UpdateTaskRequest.php`, `ProjectPolicy.php`,
`CreateProjectAction.php`, and `Project.php` were modified outside this
session while FR05 was being built (a new `App\ProjectRole` enum and
`project_users` pivot table, adding project-level membership — see the
note at the end of work-log entry #7). That change is mid-flight and
currently breaks the two Task tests above; it was left untouched since
fixing or reverting someone else's in-progress work is out of scope here.
Re-run `php artisan test --compact tests/Feature/Tasks/TaskTest.php` once
that work is finished to confirm.

Covers: auth, email verification, password reset/update, profile update,
dashboard, workspace tenant isolation, workspace CRUD, workspace roles,
workspace invitations, team member management, AI assistant endpoints,
module boundaries, Projects (18 tests), Tasks (20 tests, 2 currently
failing for the reason above), Meetings (18 tests, all passing).

No JS test runner exists in this project (`package.json` only has
build/lint/format scripts) — meeting scheduling/edit/delete were verified
by ESLint + a successful production build (catches type/template errors)
plus manual code review, not by exercising them in a browser.

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
  pipeline (FR05–FR13, of which only FR05 exists so far).
- The **Activity** tab on the project page is still a visual placeholder
  only (`AppEmptyState`, no route/data behind it) — out of scope for FR05.
- FR05 has no participant list, RSVP, calendar sync, or notification on
  create/update/cancel — the report defers those to FR06–FR09/FR23 and the
  meeting fields spec for this task didn't include participants, so none
  of that was built. A meeting is visible to every workspace member with
  access to its project, not a specific invited subset.
- No Zoom/Meet integration, recording, transcription, or AI summary
  (FR10–FR13) — `meeting_link` is a free-text URL the creator pastes in,
  exactly as scoped ("Do not implement Zoom integration... yet").
- **2 pre-existing `TaskTest` failures**, caused by unrelated concurrent
  changes to Task/Project authorization discovered mid-task — see Test
  results above. Not introduced by FR05; needs follow-up once that other
  work lands.

## Next recommended task

First, **resolve the 2 `TaskTest` failures** noted above once the
concurrent `ProjectRole`/`project_users` work (observed mid-task, not part
of this session) lands or is reconciled — the suite should return to 100%
green before building further on top of Tasks/Projects authorization.

Then, **FR06–FR09 — Meeting details, join, edit, cancel**, continuing the
meetings domain FR05 just started:
- FR06 (view full meeting details) is nearly free — `MeetingData` already
  carries every field; it likely just needs a details view, similar to how
  `EditTaskModal`'s read-only mode works for non-managers on tasks.
- FR07 (join) is just "open `meeting_link` in a new tab," already present
  on `MeetingCard` — confirm the report doesn't expect more (e.g. a
  dedicated join page or in-app video).
- FR08 (edit) and FR09 (cancel/delete) are **already fully implemented**
  by this task's `EditMeetingModal`/`DeleteMeetingDialog` — re-check the
  FR wording before re-building; there may be nothing left to do for
  those two beyond report bookkeeping.
- FR23 (email triggers on schedule/update/cancel) is the natural follow-up
  once the above is confirmed — `CreateMeetingAction`/`UpdateMeetingAction`/
  `DeleteMeetingAction` are the exact seams to hook a `Mail`/`Notification`
  into, mirroring how `MemberInvitationMail` already works in this app.

Save FR10–FR13 (transcription/AI summary) for last — it's the hardest,
most novel piece and should come only once there's real meeting data
(and ideally FR23's notifications) to build against.
