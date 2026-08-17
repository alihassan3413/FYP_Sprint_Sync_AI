# SprintSync — Functional Requirements

Verbatim extraction of FR01–FR35 from `SprintSync FYP1 Report-1.docx`
(section 2.3.1, Tables 2.3–2.37).

This file is the **requirement source of truth**. It records what the report
asks for and nothing else — no implementation status, no architectural
decisions, no notes about the current codebase. Implementation status is
tracked separately in `PROJECT_PROGRESS.md`.

Wording, terminology and sub-requirement numbering are reproduced as they
appear in the report. Where the report's own numbering is inconsistent
(`FR01-`, `FR02:`, `FR_03:`), the title text itself is preserved and only the
heading format is normalised.

---

## FR01 — Login

| Req. No | Functional Requirement |
|---|---|
| FR01-01 | The system shall enable the user to enter their registered email address on the login screen. |
| FR01-02 | The system shall allow the user to enter an alphanumeric password. |
| FR01-03 | The system shall display an error message in case of invalid credentials. |
| FR01-04 | The system shall allow the user to click the Sign In button to submit their credentials. |
| FR01-05 | The system shall verify the submitted credentials against the records in the database. |
| FR01-06 | The system shall redirect the user to their respective role-based dashboard upon successful authentication. |
| FR01-07 | The system shall redirect the user to the login page with an error message in case of failed authentication. |

## FR02 — Reset Password

| Req. No | Functional Requirement |
|---|---|
| FR02-01 | The system shall enable the user to click the Forgot Password hyperlink on the login screen. |
| FR02-02 | The system shall redirect the user to the Reset Password screen. |
| FR02-03 | The system shall allow the user to enter their registered email address. |
| FR02-04 | The system shall send a 6-digit one-time code to the specified email address. |
| FR02-05 | The system shall allow the user to enter the received code and click a Resend Code hyperlink if not received. |
| FR02-06 | The system shall redirect the user to the Set New Password screen upon successful verification. |
| FR02-07 | The system shall allow the user to enter and confirm a new password and redirect to login upon success. |

## FR03 — Logout

| Req. No | Functional Requirement |
|---|---|
| FR03-01 | The system shall enable the user to click a Logout option from the navigation menu. |
| FR03-02 | The system shall terminate the user's session and redirect them to the login screen upon logout. |
| FR03-03 | The system shall automatically log out the user after a defined period of inactivity. |

## FR04 — View Dashboard

| Req. No | Functional Requirement |
|---|---|
| FR04-01 | The system shall display a role-appropriate dashboard to the user upon successful login. |
| FR04-02 | The system shall display the count of unread notifications in the dashboard header. |
| FR04-03 | The system shall display a list of upcoming scheduled meetings on the dashboard. |
| FR04-04 | The system shall display pending summary reviews awaiting Scrum Master approval on the Scrum Master dashboard. |
| FR04-05 | The system shall display a sprint task completion widget showing progress across all task statuses. |

## FR05 — Schedule Meeting

| Req. No | Functional Requirement |
|---|---|
| FR05-01 | The system shall enable the Scrum Master to navigate to the Schedule Meeting section from the dashboard. |
| FR05-02 | The system shall allow the Scrum Master to enter a meeting title, date, time, duration, and optional agenda. |
| FR05-03 | The system shall allow the Scrum Master to add participant email addresses to the meeting invitation list. |
| FR05-04 | The system shall validate all mandatory fields and display an error message if any required field is empty. |
| FR05-05 | The system shall generate a meeting link and store the meeting record in the database upon successful submission. |
| FR05-06 | The system shall dispatch email invitations with the meeting link, date, time, and agenda to all participants. |

## FR06 — View Meeting Details

| Req. No | Functional Requirement |
|---|---|
| FR06-01 | The system shall enable all authenticated users to view a list of upcoming and past meetings on their dashboard. |
| FR06-02 | The system shall allow the user to click on a meeting entry to view its full details including title, date, time, participants, and status. |
| FR06-03 | The system shall display the current status of each meeting: Scheduled, Completed, Pending Review, or Distributed. |

## FR07 — Join Meeting

| Req. No | Functional Requirement |
|---|---|
| FR07-01 | The system shall enable all invited and authenticated users to join a scheduled meeting by clicking the Join Meeting button. |
| FR07-02 | The system shall open the meeting link in a new browser tab when the Join Meeting button is clicked. |
| FR07-03 | The system shall deny access to any user who is not listed as a participant for that meeting. |

## FR08 — Edit Meeting

| Req. No | Functional Requirement |
|---|---|
| FR08-01 | The system shall enable the Scrum Master to edit the title, date, time, duration, or agenda of a scheduled meeting before it begins. |
| FR08-02 | The system shall dispatch updated invitation emails to all participants when a meeting is edited. |

## FR09 — Cancel Meeting

| Req. No | Functional Requirement |
|---|---|
| FR09-01 | The system shall enable the Scrum Master to cancel a scheduled meeting before it begins. |
| FR09-02 | The system shall remove the meeting record from the active schedule and send a cancellation notification to all invited participants. |

## FR10 — Auto-Transcribe Meeting

| Req. No | Functional Requirement |
|---|---|
| FR10-01 | The system shall automatically detect the completion of a meeting and begin the transcription process. |
| FR10-02 | The system shall generate a full speech-to-text transcript of the meeting recording and store it in the database. |
| FR10-03 | The system shall flag the transcript with a low-confidence warning if the audio quality is insufficient. |
| FR10-04 | The system shall notify the Scrum Master with a manual transcript upload option if the transcription process fails. |

## FR11 — Generate AI Summary

| Req. No | Functional Requirement |
|---|---|
| FR11-01 | The system shall automatically generate a structured meeting summary from the stored transcript upon completion of transcription. |
| FR11-02 | The system shall organize the summary into three categories: Decisions Made, Action Items with assigned owners and due dates, and Blockers Identified. |
| FR11-03 | The system shall store the AI-generated summary in the database with a status of Pending Review. |
| FR11-04 | The system shall create a notification alerting the Scrum Master that a new summary is ready for review. |
| FR11-05 | The system shall notify the Scrum Master with a manual summary entry option if the generation process fails. |

## FR12 — Review AI Summary

| Req. No | Functional Requirement |
|---|---|
| FR12-01 | The system shall enable the Scrum Master to navigate to the Pending Reviews section from the dashboard. |
| FR12-02 | The system shall display all summaries with Pending Review or Draft Saved status in reverse chronological order. |
| FR12-03 | The system shall allow the Scrum Master to open a pending summary and view its content organized into Decisions Made, Action Items, and Blockers. |
| FR12-04 | The system shall enable the Scrum Master to edit, add, or remove any entry within the summary. |
| FR12-05 | The system shall enable the Scrum Master to reassign the owner of any action item to a different team member. |
| FR12-06 | The system shall allow the Scrum Master to save the review as a draft without sending, setting status to Draft Saved. |
| FR12-07 | The system shall deny access to the summary review and editing interface for Developer and Team Lead roles. |

## FR13 — Approve and Send Summary

| Req. No | Functional Requirement |
|---|---|
| FR13-01 | The system shall provide an Approve and Send button on the summary review interface, visible only to authenticated Scrum Masters. |
| FR13-02 | The system shall format the finalized summary into an email containing decisions, action items with assignees and due dates, and blockers. |
| FR13-03 | The system shall dispatch the formatted summary email to all registered meeting participants upon approval. |
| FR13-04 | The system shall update the summary status to Distributed and display a success confirmation to the Scrum Master. |
| FR13-05 | The system shall retain the Pending Review status and show an error notification if the email dispatch fails. |

## FR14 — View Task Board

| Req. No | Functional Requirement |
|---|---|
| FR14-01 | The system shall enable all authenticated users to navigate to the Task Board from the dashboard. |
| FR14-02 | The system shall display tasks organized into three status columns: To Do, In Progress, and Done. |
| FR14-03 | The system shall display each task card with the task title, assignee name, and due date. |
| FR14-04 | The system shall show the currently logged-in user's assigned tasks by default, with an option to view all team tasks. |

## FR15 — Create Task

| Req. No | Functional Requirement |
|---|---|
| FR15-01 | The system shall automatically create task records from the action items in a distributed meeting summary, linking each task to its assigned owner. |
| FR15-02 | The system shall enable the Scrum Master to manually create a new task by entering a title, selecting an assignee, and optionally setting a due date. |

## FR16 — Update Task Status

| Req. No | Functional Requirement |
|---|---|
| FR16-01 | The system shall enable any authenticated user to update the status of a task assigned to them by selecting a new status. |
| FR16-02 | The system shall immediately reflect all task status changes across all active users' dashboards. |

## FR17 — Edit and Delete Task

| Req. No | Functional Requirement |
|---|---|
| FR17-01 | The system shall enable the Scrum Master to edit the title, assignee, or due date of any existing task. |
| FR17-02 | The system shall enable the Scrum Master to delete a task from the Task Board, removing it from the system. |

## FR18 — View Meeting Archive

| Req. No | Functional Requirement |
|---|---|
| FR18-01 | The system shall enable all authenticated users to navigate to the Meeting Archive from the dashboard. |
| FR18-02 | The system shall display all past meetings in reverse chronological order, showing the title, date, participant count, and summary status. |
| FR18-03 | The system shall allow the user to click on any meeting entry to view its full approved summary and original transcript. |

## FR19 — Search Meeting Archive

| Req. No | Functional Requirement |
|---|---|
| FR19-01 | The system shall enable authenticated users to search the archive using a keyword input field. |
| FR19-02 | The system shall enable users to filter results by date range using start and end date inputs. |
| FR19-03 | The system shall search across meeting titles, decisions, action items, and blocker text when a keyword is entered. |
| FR19-04 | The system shall display a no-results message when no meetings match the entered criteria. |

## FR20 — View Sprint Analytics

| Req. No | Functional Requirement |
|---|---|
| FR20-01 | The system shall enable all authenticated users to navigate to the Analytics section from the dashboard. |
| FR20-02 | The system shall display a chart showing task completion percentages across To Do, In Progress, and Done for the current sprint. |
| FR20-03 | The system shall display a chart showing blocker frequency per meeting over the most recent sprints. |
| FR20-04 | The system shall display a summary widget showing total meetings held, total action items generated, and total blockers identified. |
| FR20-05 | The system shall show team-wide analytics to Scrum Masters and Team Leads, and personal task analytics to Developers. |

## FR21 — View Notifications

| Req. No | Functional Requirement |
|---|---|
| FR21-01 | The system shall display all unread notifications in a notification panel accessible from the dashboard header. |
| FR21-02 | The system shall mark a notification as read when the user clicks on it. |
| FR21-03 | The system shall allow the user to click on a notification to navigate directly to the relevant summary or task. |

## FR22 — In-App Notification Triggers

| Req. No | Functional Requirement |
|---|---|
| FR22-01 | The system shall create an in-app notification for the Scrum Master when a new AI-generated summary is ready for review. |
| FR22-02 | The system shall create an in-app notification for a user when a new task is assigned to them from a distributed summary. |
| FR22-03 | The system shall create an in-app notification for all participants when a meeting they are invited to is cancelled. |

## FR23 — Email Notification Triggers

| Req. No | Functional Requirement |
|---|---|
| FR23-01 | The system shall send an email to all invited participants when a new meeting is scheduled, containing the join link, date, time, and agenda. |
| FR23-02 | The system shall send a summary email to all participants when a meeting summary is approved and distributed by the Scrum Master. |
| FR23-03 | The system shall send a cancellation email to all invited participants when a scheduled meeting is cancelled. |

## FR24 — Change Password

| Req. No | Functional Requirement |
|---|---|
| FR24-01 | The system shall provide a Change Password option in the user profile settings page. |
| FR24-02 | The system shall allow the user to enter their current password for verification and a new password for confirmation. |
| FR24-03 | The system shall update the password in the database upon successful verification and display a confirmation message. |
| FR24-04 | The system shall display an error if the current password is incorrect or if the new and confirm passwords do not match. |

## FR25 — Manage Notification Preferences

| Req. No | Functional Requirement |
|---|---|
| FR25-01 | The system shall provide toggles in the profile settings page to enable or disable email notifications per event type. |
| FR25-02 | The system shall suppress the corresponding email for any event type that the user has disabled. |
| FR25-03 | The system shall save the user's preferences in the database when the Save Settings button is clicked. |

## FR26 — Change Profile Picture

| Req. No | Functional Requirement |
|---|---|
| FR26-01 | The system shall allow the user to click a profile picture edit icon in the settings page. |
| FR26-02 | The system shall open a file browser allowing the user to select an image file, display a preview, and upload it upon confirmation. |
| FR26-03 | The system shall update the user's profile picture record in the database upon successful upload. |

## FR27 — Assign and Manage User Roles

| Req. No | Functional Requirement |
|---|---|
| FR27-01 | The system shall allow user roles (Scrum Master, Developer, Team Lead) to be assigned at the time of account registration. |
| FR27-02 | The system shall restrict each user's accessible features, pages, and data according to their assigned role. |
| FR27-03 | The system shall return an access denied response for any user who attempts to access a feature outside their role's permissions. |

## FR28 — View Audit Log

| Req. No | Functional Requirement |
|---|---|
| FR28-01 | The system shall record all key actions performed within the platform, including summary approvals, task status changes, and user account changes. |
| FR28-02 | The system shall store each log entry with the action type, the user who performed it, and the timestamp of the action. |
| FR28-03 | The system shall enable the Scrum Master to view the audit log for any meeting or summary, showing a chronological history of all changes made. |

## FR29 — Create Workspace

| Req. No | Functional Requirement |
|---|---|
| FR29-01 | The system shall enable a registered user to create a new workspace by entering a workspace name. |
| FR29-02 | The system shall designate the user who creates the workspace as the Workspace Owner (Scrum Master). |
| FR29-03 | The system shall store the workspace record in the database linked to the creating user. |
| FR29-04 | The system shall redirect the Scrum Master to the newly created workspace dashboard upon successful creation. |
| FR29-05 | The system shall allow a user to be the owner of multiple workspaces simultaneously. |

## FR30 — Edit Workspace

| Req. No | Functional Requirement |
|---|---|
| FR30-01 | The system shall enable the Workspace Owner to edit the workspace name. |
| FR30-02 | The system shall enable the Workspace Owner to view all members currently in the workspace. |
| FR30-03 | The system shall enable the Workspace Owner to remove any member from the workspace. |
| FR30-04 | The system shall enable the Workspace Owner to delete the workspace, removing all associated projects, tasks, meetings, and member associations. |
| FR30-05 | The system shall allow any authenticated user to switch between multiple workspaces they belong to from the navigation. |

## FR31 — Invite Team Members

| Req. No | Functional Requirement |
|---|---|
| FR31-01 | The system shall enable the Workspace Owner to invite team members to the workspace by entering their email address. |
| FR31-02 | The system shall send an email invitation to the specified address containing a workspace join link. |
| FR31-03 | The system shall generate a unique, time-limited invite link for the workspace with an expiry period of 7 days. |
| FR31-04 | The system shall allow the Workspace Owner to generate and share an invite link that any user can use to join the workspace within the 7-day expiry window. |
| FR31-05 | The system shall reject any invite link that has expired and display an expiry notification to the user attempting to use it. |
| FR31-06 | The system shall allow the invited user to accept the invitation and join the workspace, whereupon they are assigned a default role. |
| FR31-07 | The system shall enable the Workspace Owner to revoke a pending invite link before it expires. |

## FR32 — Create and Manage Projects

| Req. No | Functional Requirement |
|---|---|
| FR32-01 | The system shall enable the Workspace Owner to create a new project within a workspace by entering a project name and optional description. |
| FR32-02 | The system shall allow multiple projects to exist within a single workspace simultaneously. |
| FR32-03 | The system shall enable the Workspace Owner to edit the name or description of an existing project. |
| FR32-04 | The system shall enable the Workspace Owner to delete a project, removing all associated tasks and meeting references. |
| FR32-05 | The system shall display all projects within a workspace on the workspace dashboard, showing project name and task summary. |
| FR32-06 | The system shall restrict project access to workspace members only, enforced by workspace role permissions. |

## FR33 — Create and Manage Custom Roles

| Req. No | Functional Requirement |
|---|---|
| FR33-01 | The system shall enable the Workspace Owner to create custom roles within a workspace by entering a role name. |
| FR33-02 | The system shall enable the Workspace Owner to define granular permissions for each custom role, selecting from a predefined list of permission options (e.g., manage meetings, approve summaries, manage tasks, view analytics, manage members, manage projects). |
| FR33-03 | The system shall enable the Workspace Owner to edit the name or permissions of any existing custom role within the workspace. |
| FR33-04 | The system shall enable the Workspace Owner to delete a custom role, reassigning affected members to a default role. |
| FR33-05 | The system shall enforce that each workspace maintains its own independent set of roles and permissions, separate from other workspaces. |
| FR33-06 | The system shall display the list of all roles and their assigned permissions in the workspace settings page. |

## FR34 — Assign Roles to Workspace Members

| Req. No | Functional Requirement |
|---|---|
| FR34-01 | The system shall enable the Workspace Owner to assign any defined workspace role to any member within the workspace. |
| FR34-02 | The system shall enable the Workspace Owner to change the role of any existing workspace member at any time. |
| FR34-03 | The system shall immediately apply permission changes when a member's role is updated, without requiring the affected user to log out. |
| FR34-04 | The system shall prevent the Workspace Owner from removing their own Workspace Owner role. |
| FR34-05 | The system shall display each member's currently assigned role in the workspace member list. |

## FR35 — Role-Based Dashboard and Visibility Control

| Req. No | Functional Requirement |
|---|---|
| FR35-01 | The system shall render a distinct dashboard layout for each user based on their assigned workspace role and its associated permissions. |
| FR35-02 | The system shall display only the navigation items, widgets, and action buttons that the authenticated user's role has permission to access. |
| FR35-03 | The system shall restrict task visibility so that users only see tasks within projects they have permission to access, as defined by their workspace role. |
| FR35-04 | The system shall restrict meeting scheduling and summary approval actions to users whose role includes the corresponding permission. |
| FR35-05 | The system shall display a workspace switcher in the navigation allowing users who belong to multiple workspaces to switch context, with the dashboard re-rendering according to their role in the selected workspace. |
| FR35-06 | The system shall display an access denied message for any page or action a user attempts to access outside their role's permitted scope. |
