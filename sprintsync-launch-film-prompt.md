Build a premium 45-second launch film for SprintSync with Remotion, then render it to MP4. Work end to end without asking for approval: design, code, render, critique, fix and re-render until it is presentation-ready.

The SprintSync source code is at /Users/alihassan/Development/FYP_Sprint_Sync_AI (Laravel + Inertia + Vue 3 + Tailwind v4). Treat it as read-only reference: read its Vue components and CSS when you need to check a detail. Never modify it, install anything into it, or run its servers.

Build the film as a separate project at /Users/alihassan/Development/sprintsync-launch-film.

====================================================================
PRODUCT
====================================================================

SprintSync is an AI-native workspace for teams that run projects for clients. It covers projects and sprints, time, team, meetings, analytics and finance in one product, with an AI assistant that works through real product tools. Every fact below has been checked against the code. Show only these things. Every on-screen string marked in quotes is real product copy; reuse it exactly.

Workspace and dashboard (resources/js/pages/Dashboard.vue)
- App shell:
  - a near-black sidebar on the left;
  - a light content panel with rounded corners floating beside it.
- Sidebar groups and items:
  - "Platform": Dashboard, Projects, My Work, Time, Team, Analytics, Archive.
  - "Money": Overview, Invoices, Clients, Expenses.
- At the top of the sidebar, a workspace switcher: a lime two-letter tile, then the workspace name and the user's role.
- At the bottom of the sidebar, the user menu.
- Dashboard header:
  - greeting "Good morning, Ali — here's Sprint Sync HQ today.";
  - title "Team Dashboard", with a lime underline bar under the word "Dashboard";
  - a dark pill button "Invite teammate" ending in a lime circle with a plus.
- Stat cards, in order: "Team tasks", "Completed", "Overdue", "Projects".
- A "Task completion" ring showing "{n}% complete".
- A Projects list. Each row reads "{x} of {y} tasks done · {z} open" with a progress bar.
- An "Online now" list with pulsing green dots.
- A live working clock, e.g. "Workspace time 6:41 PM Karachi · UTC+5".

Projects and boards
- Project page tabs: Board, Sprints, Meetings, Activity, Settings.
- Board columns: "To Do", "In Progress", "Done".
- Board controls: a "My tasks" / "All tasks" toggle and a "New task" button.
- A task card has:
  - the title;
  - a 2-line muted description;
  - a sprint pill;
  - a 24px assignee avatar with the name;
  - a due-date badge, which reads "Overdue · Oct 9" in red when late.
- Tasks have NO priority, NO labels and NO tags. Never show any of them.
- Drag and drop between columns is real, and changes appear live for teammates.
- Sprint panel on the Sprints tab:
  - status "Active";
  - health "On track" / "At risk" / "Off track";
  - "{n}% done · {m}% of time gone";
  - a burndown chart with "Remaining" and "Ideal" lines;
  - stats Open / Overdue / Cycle time / Velocity.
- Project health card, badged "AI":
  - verdicts "Healthy", "Worth watching", "At risk", "Needs attention now";
  - Open / Overdue / Unowned counts;
  - "Open work by person" bars.

My Work (cross-project tasks, resources/js/pages/my-work/index.vue)
- Eyebrow "Work", title "My Work".
- Subtitle "Your tasks from every project you are part of, in one place."
- Tasks grouped by project, with headers "{Project} · {count}".
- Each row: the title, then "{project} · {column} · {sprint} · {assignee}", plus a due chip.
- Filters: Project, Status (Open / Done), Due (Overdue / Due today / Due in 7 days).

Time tracking (resources/js/pages/time/index.vue)
- Start form:
  - "What are you working on?";
  - Project and Task selects;
  - a Billable toggle;
  - a "Start timer" button.
- Running timer:
  - "Running";
  - "{project} · {task}";
  - "Started 9:12 AM";
  - a large tabular clock;
  - buttons Lunch, Pause, Stop.
- Totals tiles: "Today", "This week", "This month".
- Workday line: "Today · Oct 9 · America/New_York (UTC-4)".
- Working timezones:
  - The workspace has a timezone, and each project can override it in Edit project → "Working timezone".
  - The project clock reads "Project time 9:41 AM New York · EDT"; the workspace clock reads "Workspace time 6:41 PM Karachi · UTC+5".
  - Timers record their project's timezone, so time lands on the right workday. Earlier time is never reclassified.
- SprintSync Desktop (Electron):
  - a menu-bar/tray timer;
  - offline-safe syncing;
  - paid lunch and idle detection.
  - It takes NO screenshots and does NO keystroke or app tracking. Never imply monitoring.

Team (resources/js/pages/people/*)
- Title "Team", with buttons "Invite link", "Import teammates", "Add team member".
- Roles: Owner, Admin, Member, Client.
- Bulk import:
  - "Download template (.xlsx)";
  - template columns first_name, last_name, email, role, department, timezone, schedule;
  - "Drop an .xlsx or .csv file here, or click to choose";
  - "Up to 500 teammates and 2 MB.";
  - "Check file".
- Import preview:
  - counts "Ready", "Warnings (will import)", "Skipped", "Errors (not imported)";
  - table columns Row / Name / Email / Role / Department / Status / Notes;
  - button "Import N teammates";
  - progress "Importing… X of Y";
  - result "Import finished — N invited · …".

Meetings (inside a project's Meetings tab, and on the dashboard)
- Fields: title, agenda, date and time (shown in the workspace timezone), duration, meeting link (Zoom / Meet / Teams URL), participants (including external guests).
- Card: an "Upcoming" badge and a "Join Meeting" button.
- Invitations are emailed.
- Past meetings can take an uploaded recording, which is transcribed.
- There are NO AI meeting summaries, and SprintSync does not host calls. Never show either.

Analytics (resources/js/pages/analytics/index.vue)
- "How each project is going", made of project health cards.
- Stat cards: Projects, Total tasks, Completed, Open, Overdue, Meetings.
- Horizontal bar lists "Tasks by workflow column" and "Tasks by assignee".
- Table "Project performance".
- There are no pie charts.

Finance (owner-only, sidebar group "Money")
- Invoices home:
  - "What has been prepared, and what is waiting for you.";
  - KPIs "Waiting to be paid", "Received this month";
  - a "Needs you" list;
  - a "New invoice" button.
- Manual invoice form:
  - bill to a client;
  - issue and due dates;
  - currency;
  - lines Description / Quantity / Rate / Amount, with "Add line";
  - "Tax and discount";
  - Subtotal / Total;
  - Notes;
  - "Create draft".
- Actions: Preview, Download PDF, Finalize, Send (To / Cc / Bcc / Message), Mark paid, Void.
- Statuses and pill colors: Draft (sky), Sent (amber), Paid (emerald), Void (muted, struck through).
- Invoice number format is exactly "INV-2026-0042". A finalized invoice gets its number; drafts show "DRAFT".
- Money is exact (integer minor units). Show totals that add up correctly to the cent.
- Multi-currency: USD, PKR, EUR, GBP, AED, CAD, AUD.

AI Assistant (resources/js/components/ai/*, app/Modules/Assistant)
- Launcher: a floating 56px round button in indigo #365aff with a white Sparkles icon and a soft indigo shadow.
- Panel:
  - 420×600, frosted dark glass;
  - macOS-style dots (#ff5f57, #ffbd2e, #28c840);
  - empty state "How can I help?";
  - input placeholder "Ask anything...".
- Messages:
  - user bubbles indigo rgb(54,90,255);
  - assistant bubbles rgba(34,34,34,0.40);
  - text 13.5px, rounded-2xl;
  - replies stream in after a shimmering "Thinking" state.
- Real tools (use these exact names, and only these):
  - read-only: get_analytics, evaluate_project, get_sprint_report, find_tasks, list_projects, list_meetings, get_workspace_info, get_guide;
  - write: create_task, update_task, delete_task, comment_on_task, schedule_meeting, edit_meeting, cancel_meeting, create_project, add_project_member, manage_sprint, invite_user, create_workspace.
- Every write action first shows a confirm card: a summary line, key: value details, then "Confirm" / "Cancel". Brand promise: "It asks before it acts." The assistant can never do anything the user's role could not do.
- Real command phrasings: "How are we doing?", "How is the current sprint going?", "Create a task to …", "Schedule a meeting on …".
- The real UI does not render tool calls as chips. Any tool visualization must be a motion-graphics layer OUTSIDE the panel (see MOTION), never drawn inside the chat UI.

Name: always "SprintSync" (one word, capital S twice), as on the landing page.

Things that do NOT exist. Never show or imply any of these:
- task priority, labels or tags;
- a global ⌘K command palette;
- AI meeting summaries;
- AI-generated retros or stakeholder updates;
- screenshots or keystroke monitoring;
- video calls hosted inside SprintSync;
- project archiving;
- Gantt charts;
- integrations marketplaces;
- mobile apps.

Demo content. The real seed data is random, so use this consistent fictional set everywhere and keep every number consistent across scenes:
- Workspace: "Sprint Sync HQ" (tile "SH"). User: Ali Hassan, Owner.
- Teammates: Sara Ahmed (Admin), Bilal Khan, Ayesha Malik, Usman Tariq, Hina Raza, Omar Farooq, Zainab Iqbal.
- Projects:

  | Project | Timezone | Sprint | Progress | Health |
  |---|---|---|---|---|
  | Website Revamp (hero) | America/New_York | Sprint 4 | 62% done, 3 days left | "At risk" |
  | Checkout Flow | — | — | 81% | — |
  | Mobile Onboarding | — | — | 40% | — |

- Website Revamp tasks:
  - "Fix the login redirect" (Sara Ahmed);
  - "Checkout bug on mobile" (Bilal Khan; this is the card that moves to Done);
  - "Audit colour contrast" (Ayesha Malik);
  - "Ship invite links" (Usman Tariq);
  - "Rework empty states" (Hina Raza, overdue).
- Meeting: "Sprint 4 review", Thu Oct 16, 4:00 PM, 45 min, 6 participants.
- Client: "Halden & Co." (fictional; never use a real company's name or logo).
- Invoice INV-2026-0042, USD:

  | Line | Qty | Rate | Amount |
  |---|---|---|---|
  | Website Revamp — Sprint 4 design and build | 1 | $4,800.00 | $4,800.00 |
  | Checkout QA and fixes | 12 | $85.00 | $1,020.00 |

  Subtotal $5,820.00, no tax, Total USD $5,820.00. Due in 14 days.
- Analytics: Projects 3, Total tasks 128, Completed 79 (62%), Open 49, Overdue 2, Meetings 4 ("2 upcoming").

====================================================================
STORY
====================================================================

Thesis: "Your team, projects, time, meetings, finance and AI — synchronized."

Arc:
1. Scatter: work lives in separate fragments.
2. Sync: one lime line pulls them into one workspace.
3. Proof: five real product moments, each handed to the next by the same line.
4. Intelligence: the assistant reads the whole workspace and acts safely.
5. Resolve: the line draws the SprintSync "S" mark.

Recurring motif: THE SYNC LINE.
- A single continuous lime (#baff1a) stroke, 3px at 1080p, with a soft lime glow (two stacked blurred copies at low opacity, no neon bloom).
- It is ONE path that never breaks across the whole film. Each scene is entered and exited along it.
- In every scene it does real work: it is the drag path of a task card, the progress arc of the timer, the wire between two timezone clocks, the conveyor that carries import rows, the burndown "Remaining" line, the underline that totals the invoice, and the trace between assistant tool calls.
- At the end it draws the exact S path of the real logo (see LOOK). This is the payoff: the line was the logo all along.

Tone: calm, exact, confident. One idea per scene. Let the UI breathe. No hype words.

====================================================================
SCENE-BY-SCENE TIMELINE
====================================================================

60 fps, 2700 frames, 45.0 s. Musical grid: 120 BPM, so 1 beat = 30 frames and 1 bar = 120 frames. Land key moments on beats (frame numbers below). Transitions are continuous camera or object moves along the sync line: no hard cuts, no cross-fades between unrelated frames.

S1 — Scatter (0:00.0–0:04.0, frames 0–240)
- Stage:
  - ink #0b0b0f with a faint 22px dot grid (rgba(255,255,255,0.05));
  - slow push-in, scale 1.00 → 1.04.
- Four real-looking fragments drift apart, at different depths with slight parallax:
  1. a task card "Fix the login redirect";
  2. a running timer chip "01:24:37";
  3. a meeting row "Sprint 4 review · Thu 4:00 PM · 45 min";
  4. an invoice row "INV-2026-0042 · Waiting to be paid".
- Each is pixel-faithful SprintSync UI, light surfaces on the dark stage, each fading in on a beat (frames 0, 30, 60, 90).
- Headline at frame 120: "Work gets scattered." Instrument Sans 600, 88px, white, tracking -0.035em, left-aligned at the safe margin.
- From frame 180 the sync line enters from the left edge, searching.

S2 — Sync (0:04.0–0:09.0, frames 240–540)
- Frames 240–330: the line threads through all four fragments. Each one snaps onto the line (spring, damping 18) and is pulled toward screen center.
- The fragments resolve into their real places in the app shell as it assembles:
  - the black sidebar (hsl(240 8% 8%), 256px) slides in;
  - the nav items cascade 2 frames apart;
  - the light content panel (hsl(210 20% 97%), rounded 12px, inset 8px) unfolds.
- Frames 330–540: the Team Dashboard settles:
  - greeting;
  - "Team Dashboard" with the lime underline drawn by the line itself;
  - the four stat cards counting up to Team tasks 128 · Completed 79 · Overdue 2 · Projects 3;
  - the "Task completion" ring filling to 62%;
  - "Workspace time 6:41 PM Karachi · UTC+5" on the clock.
- Headline over a soft dark scrim on the left third, frames 360–510: "One workspace. Everything in sync."

S3 — Projects, board and My Work (0:09.0–0:15.0, frames 540–900)
- Frames 540–600: the camera dollies into the Projects row "Website Revamp". It expands (shared-element morph) into the project page: header plus tabs Board / Sprints / Meetings / Activity / Settings, Board active.
- Frames 600–780: the board shows To Do / In Progress / Done, with the five tasks spread across the columns.
  - Bilal's "Checkout bug on mobile" lifts (scale 1.03, deeper shadow) and travels along the sync line from In Progress to Done.
  - It lands on frame 720 (a downbeat) with a soft settle.
  - The Done count ticks up.
  - The overdue badge on "Rework empty states" pulses once.
- Frames 780–900: the board slides away along the line, revealing My Work:
  - "My Work · Your tasks from every project you are part of, in one place.";
  - three project groups, "Website Revamp · 4", "Checkout Flow · 3", "Mobile Onboarding · 2", with rows in the real format.
- Caption: "Every project. Every owner. One list."

S4 — Time and working timezones (0:15.0–0:21.0, frames 900–1260)
- Frames 900–960: the My Work row "Checkout bug on mobile" morphs into the Time page start form. Project Website Revamp, task filled.
- Frame 960 (downbeat): "Start timer" is pressed (scale 0.97 → 1, lime ripple), and the card becomes "Running".
- The large clock counts deterministically from 00:00:00. It is driven by a time-compression mapping: show the clock jumping smoothly to 01:24:37 by frame 1140, with the seconds digits rolling.
- The sync line wraps the timer card as a progress border.
- Frames 1020–1200: two WorkingClocks appear, joined by the line:
  - left: "Project time 9:41 AM New York · EDT";
  - right: "Workspace time 6:41 PM Karachi · UTC+5".
  - Below them: "Today · Oct 9 · America/New_York (UTC-4)".
- Caption: "Time lands on the right day — in the right timezone."

S5 — Team import (0:21.0–0:26.0, frames 1260–1560)
- Frames 1260–1320: the line straightens into a conveyor. An .xlsx file chip slides onto the import drop zone ("Drop an .xlsx or .csv file here, or click to choose").
- Frames 1320–1440: rows stream along the line into the preview table (Row / Name / Email / Role / Department / Status), and the counts settle at:
  - "Ready 24";
  - "Warnings (will import) 2";
  - "Skipped 1";
  - "Errors (not imported) 0".
- Frame 1440 (downbeat): "Import 26 teammates" is pressed, then "Importing… 26 of 26".
- Avatars (real avatar palette, white initials) pop into the Team list in a staggered wave.
- Caption: "Bring the whole team in at once."

S6 — Meetings into analytics (0:26.0–0:31.0, frames 1560–1860)
- Frames 1560–1650: an "Upcoming" meeting card "Sprint 4 review", with 6 participant avatars and "Join Meeting".
- The line runs from the card's date into the Sprints tab, where it becomes the burndown "Remaining" line drawing itself against a dashed "Ideal" line.
- Frames 1650–1860: Website Revamp health card:
  - "At risk";
  - "62% done · 70% of time gone";
  - Open / Overdue / Unowned stats;
  - "Open work by person" bars, with Bilal's bar the longest.
- Then a quick pull-back to the Analytics stat row and "Tasks by assignee".
- Caption: "See how the sprint is really going."

S7 — Manual invoice (0:31.0–0:36.0, frames 1860–2160)
- Frames 1860–1980: the burndown line flattens into the invoice's top rule.
  - The manual invoice form fills line by line: the two lines above, with the amounts counting up and tabular numbers aligned.
  - The line underlines Subtotal, then Total USD $5,820.00.
- Frame 1980 (downbeat): "Finalize". The draft badge turns into "INV-2026-0042" with a crisp snap.
- The document lifts into the printable PDF look: white page, "INVOICE", Bill to Halden & Co., Issue/Due dates.
- Status pill transitions Draft (sky) → Sent (amber) → Paid (emerald), at frames 2040 and 2100.
- Caption: "Bill the work. Get paid."

S8 — SprintSync AI (0:36.0–0:41.5, frames 2160–2490)
- Frames 2160–2220: the indigo assistant button blooms into the frosted panel over a dimmed dashboard.
- Frame 2220: the user bubble types in "How are we doing?"
- The "Thinking" shimmer runs. Outside the panel, on the dark scrim, the sync line visits two tool nodes in sequence:
  - `get_analytics` (frame 2250);
  - `evaluate_project` (frame 2280).
  - Each node is a small mono label in a rounded pill with a lime dot.
  - These are clearly motion-graphic annotations, not part of the chat UI.
- Frames 2300–2400: the reply streams in, word by word: "Website Revamp is 62% done with 3 days left — behind pace. Two tasks are overdue, and Bilal is carrying most of the open work."
- Frames 2400–2490: the user asks "Move the checkout bug to Sara". The real confirm card appears:
  - summary 'Update "Checkout bug on mobile"';
  - "Assignee: Sara Ahmed";
  - Confirm and Cancel.
- Confirm is pressed on frame 2460 (beat). Caption: "It asks before it acts."

S9 — Resolve (0:41.5–0:45.0, frames 2490–2700)
- Frames 2490–2580: everything on screen collapses along the line toward center: panels shrink into thin lime traces.
- The line draws the exact SprintSync S path.
- Frame 2580 (downbeat): the lime rounded-square tile fills in behind it (rx 22 of 100). The S becomes ink #121216.
- Frames 2580–2700:
  - the wordmark "SprintSync" (Instrument Sans 600, 96px, white) slides out from the mark;
  - then "Run your work. Stay in sync." in muted white, 40px.
  - Hold on the final frame for at least 60 frames, with a sub-pixel breathing glow only.

====================================================================
FORMAT
====================================================================

- 1920×1080, 16:9, 60 fps.
- 2700 frames (45.0 s). If the critique rounds show it needs tightening, 38–45 s is acceptable, but keep the beat grid.
- Output: out/sprintsync-launch.mp4, H.264, yuv420p, CRF 16 or better, AAC 48 kHz stereo audio.
- Title-safe margin: 96px on every side for all text. Product UI may bleed past it, its text may not.

====================================================================
LOOK
====================================================================

Use the real SprintSync light theme (resources/css/app.css :root). Dark mode switches the brand to blue, so do not use it.

Tokens, as CSS variables in one theme.ts:

| Token | Value |
|---|---|
| stage ink | #0b0b0f |
| background | hsl(210 20% 97%) |
| card | #ffffff |
| foreground | hsl(240 10% 8%) |
| muted | hsl(240 6% 94%) |
| muted-foreground | hsl(240 4% 42%) |
| border | hsl(240 6% 88%) |
| primary / lime | hsl(78 100% 55%) ≈ #baff1a |
| primary-text | hsl(78 100% 22%) |
| sidebar | hsl(240 8% 8%) |
| sidebar-accent | hsl(240 6% 14%) |
| sidebar-border | hsl(240 5% 18%) |
| sidebar-foreground | hsl(0 0% 96%) |
| AI indigo | #365aff |
| destructive | hsl(0 74% 53%) |
| emerald | hsl(160 70% 40%) |
| amber | hsl(35 90% 55%) |
| rose | #fb7185 |
| lavender | #e4e3ff |

- Radius: base 12px. Stat cards 24px. The assistant panel uses rounded-t 30px / rounded-b 35px.
- Stat card tones: radial corner glows at 100% 0%. Lime rgba(163,230,53,.18), indigo rgba(54,90,255,.14), lavender rgba(167,139,250,.18), rose rgba(251,113,133,.16).
- Active sidebar item:
  - lime icon;
  - a 2px lime bar on the left edge, 45% of the item's height;
  - a subtle inset highlight.
- Real logo (public/favicon.svg). Reproduce it exactly:

```
<svg viewBox="0 0 100 100"><rect width="100" height="100" rx="22" fill="#baff1a"/><path d="M68 34C68 27 60 22 50 22C40 22 32 27 32 34C32 41 40 45 50 47C60 49 68 53 68 61C68 68 60 73 50 73C40 73 32 68 32 61" fill="none" stroke="#121216" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/></svg>
```

- Do NOT use resources/js/components/AppLogoIcon.vue or public/logo.svg. Those are Laravel starter-kit artwork.
- Composition: product UI floats on the ink stage as a large "screen" plane, with a 1px hairline border at rgba(255,255,255,0.08) and one soft, long, low-opacity shadow. Light comes from above-left, and the same direction holds throughout.
- Lime is the ONLY accent in non-AI scenes. Indigo appears ONLY in the AI scene. No rainbow gradients, no glassmorphism except the real assistant panel, no lens flares, no particles.

====================================================================
TYPOGRAPHY
====================================================================

- Instrument Sans: the product's real font, loaded via @remotion/google-fonts/InstrumentSans, weights 400, 500, 600 and 700. Block rendering until it loads (the package handles delayRender).
- Monospace for tool names and the timer: JetBrains Mono via @remotion/google-fonts, weight 500.
- Film headlines:
  - 600 weight, 80–96px, tracking -0.035em, line-height 0.95;
  - white on ink;
  - left-aligned on a 12-column grid (96px margins, 24px gutters).
- Captions: 500 weight, 34–40px, rgba(255,255,255,0.72). At most 7 words. Always placed in the same lower-left zone.
- Product UI text keeps its real sizes, but the camera framing must make every key string readable on a projector. Its effective on-screen size must never fall below 20px. Zoom the camera rather than shrinking the UI.
- Numbers: font-variant-numeric: tabular-nums everywhere digits change.
- Words appear by line or by word with a 6–10 frame stagger. No letter-by-letter typewriter effect except the chat input.

====================================================================
MOTION
====================================================================

- Everything is deterministic and frame-driven: useCurrentFrame, interpolate with extrapolate clamp, spring({fps, frame, config}). No Math.random. No Date.now. Any variation comes from a seeded function of the index.
- Easing vocabulary:
  - entrances Easing.bezier(0.16, 1, 0.3, 1);
  - exits Easing.bezier(0.7, 0, 0.84, 0);
  - UI snaps spring({damping: 18, stiffness: 180, mass: 0.9});
  - camera moves Easing.bezier(0.65, 0, 0.35, 1).
- Camera: a single CameraRig component applies translate, scale and a slight perspective rotateX/rotateY (at most 4°) to a 3D-ish stage. Use it for dolly-ins and shared-element morphs between scenes.
- Shared-element morphs (row → page, card → timer, line → burndown): interpolate the bounding box (x, y, w, h, radius) from the source to the target, and cross-dissolve content inside the box only.
- The sync line is a single SVG path per scene. evolvePath / strokeDashoffset draws it, and getPointAtLength places objects on it.
  - Measure paths with @remotion/paths, and keep the stroke head continuous between scenes by matching the exit point of one scene to the entry point of the next.
  - Optional tip highlight: a 6px lime dot leading the stroke.
- Depth:
  - three parallax layers: stage grid, UI plane, foreground captions;
  - a subtle depth-of-field blur on background fragments only (CSS filter blur 2–6px, static per element);
  - motion blur only on fast card moves, via @remotion/motion-blur <Trail> or a 3-sample manual trail at low opacity. Keep render time sane.
- Pace: a beat is 30 frames. Big moves land on downbeats (every 120 frames). Never move more than two focal elements at once. Hold every finished state for at least 20 frames before leaving it.

====================================================================
PRODUCT UI
====================================================================

- Recreate the UI as React components that mirror the real Vue components. Read these files first, and copy structure, spacing, copy and class semantics (translate the Tailwind classes into inline styles or CSS modules):
  - resources/js/components/AppSidebar.vue
  - resources/js/components/NavMain.vue
  - resources/js/components/workspace/WorkspaceSwitcher.vue
  - resources/js/components/NavUser.vue
  - resources/js/pages/Dashboard.vue and resources/js/components/dashboard/*
  - resources/js/components/ui/AppStatCard.vue
  - resources/js/components/WorkingClock.vue
  - resources/js/components/tasks/TaskCard.vue
  - resources/js/pages/projects/show.vue
  - resources/js/pages/my-work/index.vue
  - resources/js/pages/time/index.vue
  - resources/js/pages/people/import-show.vue
  - resources/js/components/meetings/*
  - resources/js/components/projects/SprintPanel.vue
  - resources/js/components/analytics/ProjectHealthCard.vue
  - resources/js/pages/invoices/manual-form.vue and manual.vue
  - resources/views/billing/invoice-pdf.blade.php
  - resources/js/lib/billing.ts (status styles)
  - resources/js/components/ai/* (AssistantFab, AssistantPanel, confirm card)
  - resources/css/app.css
- Icons: lucide-react, the React build of the same Lucide set the product uses (lucide-vue-next). Use the same icons:
  - LayoutGrid, FolderKanban, ListChecks, Timer, Users2, BarChart3, Archive;
  - PieChart, ReceiptText, Building2, Wallet;
  - CalendarClock, AlertCircle, Clock, Coffee, Play, Pause, Square, Sparkles, Plus, ChevronsUpDown, Bell, Video.
- Avatars: circles with white initials. Colors come from the real palette by a hash of the name: #534AB7, #0F6E56, #D85A30, #D4537E, #185FA5, #3C3489, #993C1D, #0C447C.
- Build a small UI kit in src/ui:
  - AppShell
  - Sidebar
  - StatCard
  - Badge
  - Button
  - Avatar
  - TaskCard
  - BoardColumn
  - WorkingClock
  - TimerCard
  - ImportTable
  - MeetingCard
  - Burndown
  - HealthCard
  - InvoiceDoc
  - AssistantPanel
  - ConfirmCard
- Scenes compose this kit. They never hand-draw one-off UI.
- No lorem ipsum, no fake features, no fake buttons. Every label must exist in the product or in the demo set above.

====================================================================
SOUND
====================================================================

Everything is original and generated in code. Do not use stock, licensed or downloaded audio.
- Write scripts/synth.ts, a Node script with zero dependencies, run with `npx tsx scripts/synth.ts`. It writes 48 kHz 16-bit stereo WAVs into public/audio/ with a hand-written RIFF header and Float32 synthesis.
- Use a seeded PRNG so every run produces identical bytes.

Music bed: bed.wav, 45 s at 120 BPM, in A minor / C major.
- Warm sub pulse: sine at 55 Hz with a short envelope on each downbeat.
- Soft filtered saw pad: two detuned saws through a one-pole low-pass that opens slowly from 600 to 2,400 Hz over the film.
- Muted plucked arpeggio from S3 onward: Karplus-Strong, quiet.
- Closed hi-hat ticks on the 8ths from S2: band-passed noise, 18 ms decay.
- S1 is sparse: the pad only, slightly detuned. The full bed arrives exactly at frame 240, when the sync happens.
- Build-up into S8, then a drop to the pad only under the AI answer.
- Final resolve chord at frame 2580, with a 3 s tail.
- Master the bed at about -18 LUFS, so it sits under the effects.

Effects, each its own WAV, placed with <Sequence from={frame}><Audio src={staticFile(...)} /></Sequence>:

| File | Sound | Used at |
|---|---|---|
| click.wav | 4 ms filtered noise burst plus a 2 kHz sine blip | button presses |
| whoosh.wav | 250 ms noise through a sweeping band-pass | camera moves |
| snap.wav | 30 ms pitched transient | card lands (frame 720), invoice finalize (1980) |
| tick.wav | very quiet wooden tick | timer seconds, under the S4 clock only |
| pop.wav | short soft sine drop | each avatar during import, pitch-varied by index |
| pulse.wav | 120 ms sine swell at 880 Hz with light chorus | AI tool nodes (2250, 2280) |
| resolve.wav | lime-line logo draw shimmer | 2490–2580 |

- Mix: effects peak around -10 dBFS, nothing clips. Use Audio's volume prop with interpolate for fades. Fade the bed in over frames 0–30 and out over frames 2640–2700.
- Sync check: every effect starts on the exact frame of its visual event.

====================================================================
REMOTION IMPLEMENTATION
====================================================================

Setup
- `npx create-video@latest --blank sprintsync-launch-film` in /Users/alihassan/Development, using the latest Remotion 4.x and TypeScript.
- Allowed dependencies (all free and open source): remotion, @remotion/cli, @remotion/google-fonts, @remotion/paths, @remotion/motion-blur, lucide-react, tsx (dev). Add nothing else unless it is genuinely necessary; if you do, write the reason in NOTES.md.

Structure

```
src/
  Root.tsx           <Composition id="SprintSyncLaunch" width=1920 height=1080 fps=60 durationInFrames=2700>
  Film.tsx           scene <Series> or absolute <Sequence>s + audio layer + persistent SyncLine layer
  theme.ts           tokens, easings, beat helpers (beat(n) = n*30, bar(n) = n*120)
  data.ts            the demo content set, single source of truth for every name/number
  motion/            CameraRig, SyncLine, morph helpers, stagger helpers, Trail wrapper
  ui/                the UI kit listed above
  scenes/            S1Scatter.tsx … S9Resolve.tsx
  audio/Soundtrack.tsx
scripts/synth.ts
scripts/review.sh    renders stills / contact sheets for critique
```

Rules
- Every scene receives its local frame. Scenes overlap by 30–60 frames, so a transition is an overlap of two scenes that share one moving element, never a fade to black.
- Lay the SyncLine out in one global coordinate space above the scenes, so it is genuinely continuous. Each scene exposes its line anchor points.
- Keep data in data.ts. Numbers shown in different scenes must come from the same constants. The invoice total is computed from its lines in integer cents.
- Performance:
  - avoid heavy filters on large layers;
  - use static blur values;
  - memoize path measurements;
  - target a render under 15 minutes on this Mac;
  - use --concurrency to fit the machine.
- No comments in the code. Use clear component and variable names instead.

Render

```
npx remotion render SprintSyncLaunch out/sprintsync-launch.mp4 --codec=h264 --crf=16 --pixel-format=yuv420p --audio-codec=aac
```

====================================================================
QUALITY CHECK
====================================================================

This is mandatory. Run at least 4 full critique/fix rounds, and keep going past 4 while any significant issue remains.

Each round:
1. Render the full MP4.
2. Extract a frame every 0.5 s (90 frames): `npx remotion ffmpeg -i out/sprintsync-launch.mp4 -vf "fps=2,scale=640:-1" review/rN/f_%03d.png`.
3. Build contact sheets of 12 frames each with the tile filter (`tile=4x3`).
4. Open every sheet with the Read tool and look at it.
5. Render full-resolution stills (`npx remotion still`) at every transition midpoint and every key moment: frames 120, 330, 720, 960, 1140, 1440, 1700, 1980, 2280, 2460, 2580 and 2699. Inspect those at 1:1.
6. Check audio: extract the waveform image (`showwavespic`) and confirm that transients line up with the event frames above. Check that nothing clips (use `volumedetect`, max_volume < -1 dB).
7. Write review/rN/CRITIQUE.md as a senior motion designer would. Score 1–5 on each criterion below, and list every problem with its frame number and the fix:
   - story clarity;
   - pacing and holds;
   - typography (sizes, tracking, rag, widows);
   - alignment to the 12-column grid and safe margins;
   - spacing rhythm;
   - visual hierarchy (one focal point per moment);
   - transition continuity (is the sync line ever broken or teleporting?);
   - projector readability (no key text under 20px effective);
   - product accuracy (compare against the Vue files; any invented feature is a blocker);
   - motion quality (easing, overshoot, jitter, aliasing on thin lines);
   - brand consistency (lime discipline, indigo only in AI);
   - sound sync and mix;
   - "template smell" (anything that looks like a stock After Effects pack gets redesigned).
8. Fix every problem scored below 4, and every blocker.
9. Re-render.

Product-accuracy blockers to check every round:
- tasks showing priority or labels;
- an AI summary of a meeting;
- tool names that do not exist;
- the Laravel logo;
- inconsistent numbers across scenes;
- an invoice total that does not add up;
- "Sprint Sync" written with a space on screen;
- any real company name or logo.

Final verification:
- `npx remotion ffprobe out/sprintsync-launch.mp4` shows 1920×1080, 60 fps, about 45 s, h264 + aac, yuv420p;
- the file plays from start to end without errors (decode it fully with `ffmpeg -v error -i out/sprintsync-launch.mp4 -f null -` and get no output).

====================================================================
RULES
====================================================================

- Do not ask for approval between steps or scenes. Make the design decisions yourself and record them in NOTES.md.
- Do not invent SprintSync features. When unsure, read the Vue component; if a feature is not there, it does not go in the film.
- No paid APIs or services. No Veo, Seedance, Higgsfield, Runway, image or video generation, stock footage, stock audio, or paid assets.
- No external raster images except the real favicon.svg drawn as a vector. Everything else is React, SVG, CSS or Canvas.
- Do not modify the SprintSync repository. Build everything in /Users/alihassan/Development/sprintsync-launch-film.
- Do not add comments to code.
- Keep the code maintainable: a UI kit plus scenes plus a single data file.
- Do not stop at source code. Render the final MP4, verify it, and finish the critique rounds.
- When done, report:
  1. the absolute path of the final MP4, its duration and its file size;
  2. a 9-line scene summary (one line per scene);
  3. for each critique round, the top problems found and what changed;
  4. any deliberate deviations from this brief and why.
