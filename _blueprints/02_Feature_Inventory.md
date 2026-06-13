# Feature Inventory

## Configurable Task Lifecycle Management Platform

> **Phase**: Feature Discovery
> 
> **Status**: Baseline v1 — complete feature set across all domains

---

## Priority Legend

**MVP** — Must exist before the first paying customer signs.
**V2** — Real and valuable, builds on the MVP foundation.
**V3** — Legitimate but complex or narrow. Third release or later.

---

## The Three Core Concepts

Every feature in this document exists to serve one of these three concepts.

**Blueprint** — A reusable template defining how a specific type of work gets done.
It specifies the stages, optional sub-stages, who is assigned to each stage or sub-stage,
how long each step is allowed to take, what must happen to exit each step, and how
stages connect to each other.
A Blueprint is created once and governs many task instances.

**Task** — A single instance of work launched from a Blueprint. When a task is
created, the Blueprint's rules become the task's lifecycle rules. Each task tracks
exactly which stage or sub-stage it is at, who is currently assigned to it, and for how long.

**Stage / Sub-stage** — The atomic unit of accountability. A stage is a named,
time-bounded phase. A stage may contain sub-stages when the work needs smaller
internal steps. Each stage or sub-stage may have one assignee or multiple assignees,
with a defined completion rule. Stage-level and sub-stage-level assignment is what
makes accountability precise: when a task is late, the system knows not just that the
task is late, but which stage or sub-stage is late, who is assigned to it, and for how
many days.

---

## Domain 1 — Organization & Structure Management

| # | Priority | Feature |
| --- | --- | --- |
| 1 | **MVP** | **Create organization entity** — Register the top-level organization with official Arabic and English name. |
| 2 | **MVP** | **Create departments** — Add directorates, sections, and units as a nested hierarchy. |
| 3 | **MVP** | **Nest departments under parent departments** — Reflect real org chart structure (Section → Directorate → Sector). |
| 4 | **MVP** | **Create positions (job slots)** — Define job titles independently of the person filling them. Positions persist when staff change. |
| 5 | **MVP** | **Set reporting line per position** — Define which position each position reports to, driving escalation chain logic. |
| 6 | **MVP** | **Set authority grade per position** — Assign a grade tier (Minister = 1, Undersecretary = 2, Director = 3…) so the system understands seniority for escalation routing and Blueprint assignment rules. |
| 7 | **MVP** | **Deactivate a department or position** — Mark as inactive after restructuring without deleting history. |
| 8 | **V2** | **View organization chart** — See the full hierarchy as a visual tree diagram. |
| 9 | **V2** | **Export organization chart** — Download the org chart as a printable document. |
| 10 | **V2** | **Set financial delegation threshold per position** — Specify approval authority limits per position, relevant if the platform extends to procurement-adjacent workflows. |

---

## Domain 2 — User & Profile Management

| # | Priority | Feature |
| --- | --- | --- |
| 11 | **MVP** | **Create user account** — Register an employee with name (Arabic + English), email, mobile, and employee ID. |
| 12 | **MVP** | **Assign user to a position** — Place a user in a position, giving them the authority grade and capability-based visibility attached to that position. |
| 13 | **MVP** | **Transfer user to a different position** — Move a user when they change position or department. Position-based stage/sub-stage assignments resolve to the new occupant automatically. |
| 14 | **MVP** | **Deactivate a user account** — Disable access for a departed employee without deleting their task history or stage records. |
| 15 | **MVP** | **Edit personal profile** — Update mobile number, preferred language, and notification preferences. |
| 16 | **MVP** | **Set interface language preference** — Choose Arabic or English as primary display language. |
| 17 | **V2** | **View user directory** — Browse all employees, searchable by name, department, or position. |
| 18 | **V2** | **View a user's current workload** — See how many active stage/sub-stage assignments are currently assigned to a colleague before assigning more. |

---

## Domain 3 — Blueprint Management

*The Blueprint is the platform's central innovation. It is the organization's formal definition
of how a specific type of work gets done — before any specific instance of that work exists.*

### 3A — Blueprint Definition

| # | Priority | Feature |
| --- | --- | --- |
| 19 | **MVP** | **Create Blueprint** — Open a new lifecycle template with Arabic and English name. |
| 20 | **MVP** | **Set Blueprint category** — Classify the Blueprint (e.g., Ministerial Directive Response, HR Request, Procurement Study, Citizen Complaint) for filtering and reporting. |
| 21 | **MVP** | **Set Blueprint scope** — Define whether the Blueprint is available organization-wide or restricted to a specific department. |
| 22 | **MVP** | **Write Blueprint description** — Describe what type of work this Blueprint handles. |
| 23 | **MVP** | **Activate Blueprint** — Make the Blueprint available for new task creation. |
| 24 | **MVP** | **Deactivate Blueprint** — Remove from active use; all in-flight tasks continue under their Blueprint snapshot. |
| 25 | **MVP** | **Blueprint is read-only once a task is launched under it** — The moment any task is created from a Blueprint, its stage definitions, transitions, and SLA rules are locked. Admins see a lock indicator. To make changes, they must deactivate and duplicate (MVP) or publish a new version (V2). This is what guarantees that a task's rules cannot be changed underneath it mid-lifecycle. |
| 26 | **MVP** | **Duplicate Blueprint** — Copy an existing Blueprint as a starting point for a similar new one. |
| 27 | **MVP** | **Browse Blueprint library** — View all Blueprints with filtering by category, scope, and active status. |
| 28 | **V2** | **Version Blueprint** — Publish a new version of a Blueprint; in-flight tasks remain on the version they started with. |
| 29 | **V2** | **View Blueprint version history** — See all past versions with change notes and creation dates. |
| 30 | **V2** | **View Blueprint usage count** — See how many active tasks are currently running under each Blueprint version. |
| 31 | **V2** | **Preview Blueprint as visual flow diagram** — See all stages and transitions rendered as a diagram before creating tasks. |
| 32 | **V2** | **Enable department-scoped Blueprint creation** — Allow positions with `blueprint.create.department` to create Blueprints scoped to their granted department only. In MVP, Blueprint creation is limited to tenant admins or users with organization-level Blueprint creation capability. |

### 3B — Stage Definition (within Blueprint)

| # | Priority | Feature |
| --- | --- | --- |
| 33 | **MVP** | **Add stage to Blueprint** — Create a named phase within the Blueprint lifecycle. |
| 34 | **MVP** | **Set stage name** (Arabic + English) — e.g., "Director Assignment", "Legal Review", "Undersecretary Sign-off". |
| 35 | **MVP** | **Set stage type** — Categorize as: Action, Review, Approval, Decision, or Information Gathering. |
| 36 | **MVP** | **Set stage description** — Explain what assigned users must do during this phase. |
| 37 | **MVP** | **Set stage sequence order** — Define the position of the stage in the standard flow. |
| 38 | **MVP** | **Set stage assignment specification** — Define the position, authority grade, department, assignment group, or assignment rule responsible for this stage. The actual user or users are resolved at task runtime via the Assignment Resolution Method (see #39). |
| 39 | **MVP** | **Set stage assignment resolution method** — Define how the system resolves stage assignees at runtime. Three MVP methods: (1) **Specific Position** — resolves to whoever currently holds the exact named position in the org; (2) **Department Head** — resolves to the configured department-head position of the specified department; (3) **Manual Assignment at Launch** — task creator nominates one or more assignees when creating the task, useful when the responsible people vary per task. If a required resolved position is vacant, the system alerts an admin and blocks launch. |
| 39A | **MVP** | **Add sub-stage to stage** — Define one or more ordered sub-stages inside a stage when the stage needs smaller internal steps before it can be completed. Sub-stages inherit the parent stage context but have their own assignees, SLA, exit requirements, and completion status. |
| 39B | **MVP** | **Set assignment cardinality and completion rule** — Configure whether a stage or sub-stage has one assignee or multiple assignees, and whether completion requires one assignee, all assignees, or a designated lead assignee to complete. |
| 40 | **V2** | **Least Workload resolution method** — Among all users eligible for this stage or sub-stage (matching position, authority grade, department, or capability criteria), resolve to whoever has the fewest active stage/sub-stage assignments at the moment of assignment. Uses workload capacity data from Domain 20. |
| 41 | **V2** | **Round Robin resolution method** — Among eligible users for this stage or sub-stage, rotate assignments sequentially so work is distributed evenly over time. |
| 42 | **MVP** | **Set stage/sub-stage SLA** — Define the maximum working hours or days allowed for this stage or sub-stage. |
| 43 | **MVP** | **Set SLA warning threshold** — Configure when the warning alert fires for a stage or sub-stage (e.g., at 75% of SLA elapsed). |
| 44 | **MVP** | **Define stage/sub-stage exit requirements** — Specify what assignees must complete before advancing: attach a document, record a decision, fill a required field, or complete all required sub-stages. In V2, this is replaced by a structured Stage Form. |
| 45 | **MVP** | **Define advance transition** — Specify which stage follows this one upon successful completion. |
| 46 | **MVP** | **Define return transition** — Specify which earlier stage this stage can be sent back to, and for what reasons. |
| 47 | **MVP** | **Set stage escalation rule** — Define who receives an alert when this stage's SLA is breached, resolved from the org hierarchy. |
| 48 | **V2** | **Define stage form** — Attach a structured output form to a stage or sub-stage in the Blueprint. Instead of a free-text completion note, the assigned user must fill defined fields before advancing. Different stages collect different data: Legal Review collects Opinion + Risk Level + Notes; Budget Review collects Available Amount + Budget Code + Recommendation. |
| 49 | **V2** | **Add field to stage form** — Define individual form fields with a name (Arabic + English), field type (Decision dropdown, Short Text, Long Text, Number, Date, File Upload), and whether the field is required or optional. |
| 50 | **V2** | **Stage form response stored as permanent output** — When an assignee submits a form, the field values are stored as an immutable record tied to that stage or sub-stage instance. Analytics can aggregate decision outcomes across task instances of the same Blueprint. |
| 51 | **V2** | **Mark stage as optional** — Flag a stage that can be skipped under defined conditions. |
| 52 | **V2** | **Define optional stage skip condition** — Specify the rule that determines whether the stage is skipped (e.g., task priority = Routine). |
| 53 | **V2** | **Define branching condition** — Create a decision point where the next stage depends on the outcome recorded at the current one (e.g., "Approved → Stage 5 / Rejected → Stage 2"). |
| 54 | **V2** | **Create parallel stage group** — Define two or more stages that must be active simultaneously. |
| 55 | **V2** | **Set parallel group completion rule** — All parallel stages must complete (AND) or just one (OR) before the task advances. |
| 56 | **V2** | **Define sub-task trigger for stage** — When this stage is entered, one or more child tasks are automatically created. The parent stage cannot exit until all children close. |
| 57 | **V2** | **Reorder stages in Blueprint** — Change stage sequence via drag-and-drop. |
| 58 | **V2** | **Delete a stage from Blueprint** — Only permitted if no in-flight tasks are currently at or past that stage. |

---

## Domain 4 — Task Management

| # | Priority | Feature |
| --- | --- | --- |
| 59 | **MVP** | **Create task from Blueprint** — Open a new task by selecting a Blueprint and filling in context fields. |
| 60 | **MVP** | **Set task title** (Arabic + English). |
| 61 | **MVP** | **Set task description and context** — Background and instructions visible to all stage and sub-stage assignees throughout the task's life. |
| 62 | **MVP** | **Set task priority** — Routine, Urgent, or Critical. |
| 63 | **MVP** | **Set overall task due date** — Target completion date for the entire task, separate from individual stage SLAs. |
| 64 | **MVP** | **Add external reference(s)** — Link the task to external identifiers from internal or external systems: correspondence number, contract number, ministerial decision number, authority reference number, vendor reference, or other. |
| 65 | **MVP** | **Assign manual stage/sub-stage assignees at launch** — For any stage or sub-stage in the Blueprint set to "Manual Assignment at Launch," the task creator nominates one or more assignees before the task goes live. |
| 66 | **MVP** | **Attach supporting documents at task creation** — Upload files all stage and sub-stage assignees will access throughout the task. |
| 67 | **MVP** | **Record task initiator** — Capture the person who opened the task, distinct from all stage and sub-stage assignees. Initiator receives updates but owns no stage or sub-stage unless the Blueprint assigns one to their position. |
| 68 | **MVP** | **Set task confidentiality level** — Three levels: **Public** (visible to all authorized users in the org), **Internal** (visible only to task participants and their authorized managers), **Confidential** (visible only to explicitly named participants, configured governance participants, or justified audited override users). Admins can rename these levels in System Administration. |
| 69 | **MVP** | **Save task as draft** — Save an incomplete task before officially launching it. |
| 70 | **MVP** | **Launch task** — Finalize creation, resolve and notify Stage 1 or Stage 1 sub-stage assignees, start SLA timers. |
| 71 | **MVP** | **Cancel task with reason** — Close a task before completion. Mandatory reason required. All active assignees and the initiator are notified. |
| 72 | **MVP** | **Suspend task** — Pause an entire task when the work cannot proceed due to an external dependency that affects the whole matter, not just one stage or sub-stage (e.g., "Pending court ruling", "Awaiting regulatory clearance"). All SLA timers pause. All active assignees are notified. The task appears on the follow-up board with a Suspended status. |
| 73 | **MVP** | **Resume suspended task** — Re-activate a suspended task. All SLA timers restart. Active assignees are notified. The suspension reason and duration are permanently logged in the audit trail. |
| 74 | **MVP** | **View task overview** — Current stage/sub-stage, active assignees, SLA health, and overall progress at a glance. |
| 75 | **MVP** | **View full task timeline** — Every stage and sub-stage passed through, each assignee, their duration, and the outcome. |
| 76 | **V2** | **Link task to a related task** — Connect tasks that are dependent on or related to each other. |
| 77 | **V2** | **Duplicate task** — Create a new task from the same Blueprint with similar context as an existing one. |
| 78 | **V2** | **Create recurring task** — Define a task that auto-creates on a schedule (weekly, monthly, quarterly) from a specified Blueprint. |
| 79 | **V2** | **Manage recurring task series** — Edit or cancel future instances without affecting past completed ones. |

---

## Domain 5 — Stage Lifecycle Management

*The operational core of the platform. These features deliver precise accountability.*

| # | Priority | Feature |
| --- | --- | --- |
| 80 | **MVP** | **View current active stage/sub-stage** — Any authorized user can see exactly which stage or sub-stage a task is at. |
| 81 | **MVP** | **View current assignees** — The exact person or people currently bearing accountability for the active stage or sub-stage. |
| 82 | **MVP** | **View stage/sub-stage SLA countdown** — Time remaining and time already elapsed in the current stage or sub-stage. |
| 83 | **MVP** | **View expected stage/sub-stage exit time** — The latest acceptable time for assigned users to complete and advance. |
| 84 | **MVP** | **Submit stage/sub-stage output** — Assigned users mark required actions complete (attach documents, record decisions, fill required fields) according to the configured completion rule. In V2, this uses the structured Stage Form if one is defined. |
| 85 | **MVP** | **Add stage/sub-stage completion note** — Free-text summary of what was done and any recommendations for the next step. |
| 86 | **MVP** | **Advance task to next stage/sub-stage** — The active step advances when its completion rule is satisfied. The next stage or sub-stage assignees are immediately notified and take accountability. |
| 87 | **MVP** | **Return task to a previous stage/sub-stage** — An authorized active assignee sends the task backward with a mandatory written reason. Target assignees are notified; accountability reverts. |
| 88 | **MVP** | **View stage/sub-stage history** — All stages and sub-stages the task has passed through, in order, with assignees and durations. |
| 89 | **MVP** | **View return history** — All returns made on this task with reasons, timestamps, and the parties involved. |
| 90 | **MVP** | **Override stage/sub-stage assignment** — Authorized users can reassign one or more assignees of an active stage or sub-stage. Mandatory reason. Fully logged in audit trail. |
| 91 | **V2** | **Insert ad-hoc stage** — Director grade and above can insert an unplanned stage into a task's live progression. Blueprint is not affected. Logged as a lifecycle deviation. |
| 92 | **V2** | **Skip optional stage** — When a stage is marked optional in the Blueprint, an authorized user can skip it with a mandatory reason. |
| 93 | **V2** | **Branch task to alternate route** — At a decision stage, route to one of multiple possible next stages based on the recorded outcome. |
| 94 | **V2** | **Mark stage as externally blocked** — Pause a single stage (and optionally its SLA timer) when only that stage depends on an external party. For cases where the whole task must stop, use Suspend Task instead. |
| 95 | **V2** | **Unblock stage** — Resume stage progression when the external dependency resolves. |
| 96 | **V2** | **View parallel stage status** — When multiple stages are active simultaneously, see all owners and SLA health in one view. |

---

## Domain 6 — Follow-Up & Tracking

*Replaces manual phone calls, spreadsheets, and WhatsApp coordination.*

| # | Priority | Feature |
| --- | --- | --- |
| 97 | **MVP** | **Unified follow-up board** — Every active task in the organization on one screen: current stage/sub-stage, active assignees, priority, SLA health. |
| 98 | **MVP** | **Filter by task status** — Active, Suspended, Overdue, At Risk, Completed, Cancelled. |
| 99 | **MVP** | **Filter by current stage** — Show only tasks at a specific stage type. |
| 100 | **MVP** | **Filter by current assignee** — Every task currently assigned to a specific person. |
| 101 | **MVP** | **Filter by department** — Narrow to tasks whose current active assignees belong to a specific department. |
| 102 | **MVP** | **Filter by priority** — Urgent or Critical tasks only. |
| 103 | **MVP** | **Filter by Blueprint type** — Tasks running on a specific Blueprint category. |
| 104 | **MVP** | **Filter by date range** — Tasks created, due, or completed within a period. |
| 105 | **MVP** | **Filter by external reference** — All tasks linked to a specific correspondence number, contract, authority reference, vendor reference, or other external identifier. |
| 106 | **MVP** | **See current assignees per task** — "Who has the ball right now" is one click away, including multiple assignees where configured. |
| 107 | **MVP** | **See time elapsed at current stage/sub-stage** — Working hours or days the current step has been active. |
| 108 | **MVP** | **SLA health indicator per task** — Green (on track), Amber (at risk), Red (SLA breached), Grey (Suspended). |
| 109 | **MVP** | **View overdue task list** — Every task past its stage SLA, sorted by days overdue. |
| 110 | **MVP** | **View at-risk task list** — Tasks approaching their stage SLA deadline. |
| 111 | **MVP** | **Log a manual follow-up action** — Record a phone call or message made to follow up. Permanently stored in task history so the next person knows what was already tried. |
| 112 | **MVP** | **Sort board by any column** — By deadline, priority, department, stage type, or time-at-stage. |
| 113 | **MVP** | **Stage-level bottleneck indicator** — Which stage type, in which department, currently holds the most overdue or at-risk tasks. |
| 114 | **V2** | **View follow-up action history per task** — Full log of all follow-up actions. |
| 115 | **V2** | **Pin critical tasks to personal watch list** — Bookmark without owning a stage. |
| 116 | **V2** | **Save board filter configuration** — Save frequently used filter sets. |
| 117 | **V2** | **Bulk status view** — Select multiple tasks for a combined SLA health summary. |

---

## Domain 7 — SLA & Escalation

*SLA operates at the stage level. Every breach is traceable to a specific stage,
a specific owner, and a specific duration.*

| # | Priority | Feature |
| --- | --- | --- |
| 118 | **MVP** | **Create SLA policy** — A named policy with warning threshold and breach threshold. |
| 119 | **MVP** | **Assign SLA policy to a Blueprint stage** — Link a named policy to specific stages. |
| 120 | **MVP** | **Exclude non-working days from SLA countdown** — SLA timer pauses on weekends and official public holidays. |
| 121 | **MVP** | **Configure public holiday calendar** — Admin adds official holidays per country or entity. |
| 122 | **MVP** | **SLA warning notification to active assignees** — Automatic alert when warning threshold is crossed. |
| 123 | **MVP** | **SLA breach alert to active assignees and managers** — Immediate notification when a stage or sub-stage SLA is violated. |
| 124 | **MVP** | **Auto-escalate on SLA breach** — Task escalated automatically to the active assignees' direct superior(s) resolved from the org hierarchy. |
| 125 | **MVP** | **Manual escalation before SLA breach** — Any authorized user can escalate with a reason if they believe the task is at risk. |
| 126 | **MVP** | **Add escalation reason** — Mandatory written context attached to every escalation. |
| 127 | **MVP** | **Receive escalation notification** — Manager gets an immediate alert with full task and stage context. |
| 128 | **MVP** | **Resolve escalation** — Manager reassigns stage, extends deadline, or closes escalation with an action note. |
| 129 | **V2** | **Pause SLA timer for a blocked stage** — Suspend countdown when a single stage is blocked on external input. |
| 130 | **V2** | **Resume SLA timer** — Restart countdown when the block is cleared. |
| 131 | **V2** | **Chain escalation** — If the first-level manager does not act within a grace period, escalate to the next level. |
| 132 | **V2** | **SLA performance report** — Percentage of stage instances completed on time, by stage type, Blueprint, and department. |
| 133 | **V2** | **Escalation history per task** — All escalations, recipients, actions, and outcomes. |

---

## Domain 8 — Notifications & Alerts

| # | Priority | Feature |
| --- | --- | --- |
| 134 | **MVP** | **Stage/sub-stage assignment received notification** — Immediate alert to new assignees the moment they take accountability. |
| 135 | **MVP** | **SLA warning notification** — Configurable alert as the stage deadline approaches. |
| 136 | **MVP** | **SLA breach notification** — Immediate alert when a stage SLA is violated. |
| 137 | **MVP** | **Task returned to your stage notification** — Alert when a task is sent back to a stage you own. |
| 138 | **MVP** | **Task advanced from your stage notification** — Confirmation when a stage you completed advances. |
| 139 | **MVP** | **Escalation received notification** — Immediate alert with full context when a task is escalated to you. |
| 140 | **MVP** | **Task completed notification to initiator** — Alert to the task opener when the final stage is closed. |
| 141 | **MVP** | **Task cancelled notification** — Notify all active assignees and the initiator. |
| 142 | **MVP** | **Task suspended notification** — Notify all active assignees and the initiator when a task is suspended, with the suspension reason. |
| 143 | **MVP** | **Task resumed notification** — Notify all active assignees and the initiator when a suspended task restarts. |
| 144 | **MVP** | **Notification via in-app** — Alerts appear inside the platform interface. |
| 145 | **MVP** | **Notification via email** — Alerts sent to the user's registered email address. |
| 146 | **V2** | **Comment or mention notification** — Alert when someone @mentions you in a task comment. |
| 147 | **V2** | **Delegation activity notification** — Inform the delegator when someone acts on their behalf. |
| 148 | **V2** | **Notification via SMS** — Critical alerts as text messages. |
| 149 | **V2** | **Notification via WhatsApp** — Alerts via WhatsApp for organizations that use it as primary mobile channel. |
| 150 | **V2** | **Configure personal notification preferences** — Each user chooses which events trigger which channels. |
| 151 | **V2** | **Do-not-disturb schedule** — Mute non-critical notifications outside working hours. |

---

## Domain 9 — Analytics & Dashboards

| # | Priority | Feature |
| --- | --- | --- |
| 152 | **MVP** | **Executive dashboard** — Total active tasks, overdue count, at-risk count, suspended count, and completion rate across the organization. |
| 153 | **MVP** | **Stage-level bottleneck view** — Which stage type, in which department, causes the most delays organization-wide. The insight that makes the platform irreplaceable for an undersecretary. |
| 154 | **MVP** | **Department performance view** — Completion rates, average stage delay, and active task count per directorate. |
| 155 | **MVP** | **Director/Manager dashboard** — Department-specific view: task list, active stage/sub-stage assignments per employee, pending actions, overdue items by team member. |
| 156 | **MVP** | **Task aging report** — All open tasks sorted by how long they have been waiting at their current stage. |
| 157 | **MVP** | **Red/Amber/Green status per department** — Color-coded health indicators at a glance. |
| 158 | **MVP** | **Drill-down from summary to individual tasks** — Click any metric to see underlying tasks. |
| 159 | **MVP** | **Date range filter on all reports** — Change the reporting window to any custom period. |
| 160 | **V2** | **Blueprint performance report** — Average time per stage across all task instances of a given Blueprint. Reveals which stages are chronically slow. |
| 161 | **V2** | **Stage form analytics** — Aggregate structured outcomes from Stage Forms (e.g., "62% of Legal Review stages resulted in 'Approve', 28% in 'Request Revision'"). Only available when Stage Forms are defined on a Blueprint. |
| 162 | **V2** | **Individual performance view** — Tasks completed, tasks overdue, average stage turnaround time per employee. |
| 163 | **V2** | **Stage SLA compliance report** — Percentage of stage instances that met their SLA, by stage type and department. |
| 164 | **V2** | **Compare periods** — This month vs last month vs same period last year. |
| 165 | **V2** | **Export dashboard as PDF** — Printable executive briefing from dashboard data. |
| 166 | **V2** | **Scheduled weekly performance summary** — Auto-generated weekly digest delivered to defined recipients. |
| 167 | **V2** | **Task volume by Blueprint type** — Tasks created per Blueprint category over a period. |
| 168 | **V3** | **Predictive SLA risk** — AI-assisted flag of tasks likely to breach their SLA based on historical stage patterns. |

---

## Domain 10 — Comments & Collaboration

| # | Priority | Feature |
| --- | --- | --- |
| 169 | **MVP** | **Add comment to any task** — A note visible to all authorized participants on that task. |
| 170 | **MVP** | **Reply to a comment** — Threaded conversation without leaving the task context. |
| 171 | **MVP** | **Attach file to a comment** — Include a supporting document within a comment. |
| 172 | **MVP** | **View full comment history** — All comments permanently preserved in chronological order. |
| 173 | **V2** | **Mention a specific user in comment** — @name to notify and bring someone into the discussion. |
| 174 | **V2** | **Internal comments** — Visible only to your own department, not other task participants. |
| 175 | **V2** | **Edit your own comment** — Within a time limit; edit is logged. |

---

## Domain 11 — Document & Attachment Management

| # | Priority | Feature |
| --- | --- | --- |
| 176 | **MVP** | **Upload file attachments** — PDF, Word, Excel, and image files on any task, stage output, or comment. |
| 177 | **MVP** | **Preview document inline** — View a PDF or image without downloading. |
| 178 | **MVP** | **Download attachment** — Save a local copy. |
| 179 | **MVP** | **View version history** — All previous versions of an attached document. |
| 180 | **MVP** | **Replace document with a new version** — Upload revised file as current version; all older versions preserved. |
| 181 | **V2** | **Link document to multiple tasks** — Same file attached to more than one task. |
| 182 | **V2** | **Set document access restriction** — Limit who can view or download a sensitive attachment. |

---

## Domain 12 — Search & Discovery

| # | Priority | Feature |
| --- | --- | --- |
| 183 | **MVP** | **Full-text search** — Task titles, descriptions, stage notes, and comments. |
| 184 | **MVP** | **Search by external reference number** — Enter a correspondence, contract, authority reference, vendor reference, or other external number to pull up all linked tasks instantly. |
| 185 | **MVP** | **Search by current assignee** — All tasks currently assigned to a specific person at an active stage or sub-stage. |
| 186 | **MVP** | **Search by Blueprint type** — All tasks running on a specific Blueprint. |
| 187 | **MVP** | **Filter search results** — By status, priority, date range, department, or Blueprint. |
| 188 | **MVP** | **Hijri date search** — Enter a Hijri date and retrieve matching records. |
| 189 | **MVP** | **View recent activity** — Quick access to the last 20 items you viewed or worked on. |
| 190 | **V2** | **Advanced search with multiple criteria** — Combine several filters in one query. |
| 191 | **V2** | **Saved searches** — Save a frequently used filter set to run again with one click. |

---

## Domain 13 — Archive & Records Management

| # | Priority | Feature |
| --- | --- | --- |
| 192 | **MVP** | **Automatic archiving on task completion** — Closed tasks move to archive automatically. |
| 193 | **MVP** | **Browse archived tasks** — Search and retrieve any completed or cancelled task with full history. |
| 194 | **MVP** | **Immutable archive** — Archived records cannot be edited or deleted by anyone. |
| 195 | **V2** | **Set retention period** — How long different task categories must be kept before disposal review. |
| 196 | **V2** | **Export records for compliance** — Package task histories and audit trails for submission to a state audit bureau. |
| 197 | **V2** | **Reopen archived task** — Authorized admins can reopen a completed task if circumstances require. |
| 198 | **V2** | **Bulk archive closed tasks** — Move multiple completed tasks to archive in one action. |

---

## Domain 14 — Audit Trail

| # | Priority | Feature |
| --- | --- | --- |
| 199 | **MVP** | **Automatic action logging** — Every stage action, advance, return, override, escalation, suspension, resume, comment, file upload, and download is logged with user identity, timestamp, and IP address. |
| 200 | **MVP** | **Immutable log** — The audit log cannot be modified or deleted by anyone, including system administrators. |
| 201 | **MVP** | **View item audit trail** — For any task, the complete chronological history from creation to present. |
| 202 | **V2** | **Export audit log** — Download audit history of specific tasks or date ranges as a structured file. |
| 203 | **V2** | **User activity report** — All actions performed by a specific user over a defined period. |

---

## Domain 15 — Delegation & Out-of-Office

| # | Priority | Feature |
| --- | --- | --- |
| 204 | **MVP** | **Set authority delegation** — Delegate your stage/sub-stage assignment authority to a colleague for a specified date range. Incoming assignments resolve to the delegate during this period. |
| 205 | **MVP** | **Limit delegation scope** — Specify that the delegation covers only specific Blueprint types or stage types. |
| 206 | **MVP** | **Auto-expire delegation** — Delegation deactivates automatically at the specified end date. |
| 207 | **MVP** | **Set out-of-office status** — Mark yourself as unavailable so task routers and managers can see it. |
| 208 | **MVP** | **View active delegations in organization** — See all currently active delegations and who is acting on whose behalf. |
| 209 | **V2** | **Receive delegation activity summary on return** — See all stage actions taken on your behalf during absence. |
| 210 | **V2** | **Audit delegation history** — Review all past delegations, who acted under them, and what was actioned. |

---

## Domain 16 — Personal Workspace

| # | Priority | Feature |
| --- | --- | --- |
| 211 | **MVP** | **My tasks view** — All tasks where I currently own the active stage, sorted by SLA urgency. |
| 212 | **MVP** | **My pending actions** — All stages I own with unfulfilled exit requirements — what I must do today. |
| 213 | **MVP** | **My overdue stages** — All stage instances I own that have passed their SLA. |
| 214 | **MVP** | **My stage history** — All stages I have owned in the past, completed or returned. |
| 215 | **MVP** | **My notifications center** — All unread alerts, reminders, and escalations. |
| 216 | **MVP** | **My workload summary** — Count of active stages I own and their SLA health breakdown. |
| 217 | **V2** | **My watch list** — Tasks I'm monitoring without owning a stage. |
| 218 | **V2** | **My calendar view** — Stage SLA deadlines in a calendar layout. |
| 219 | **V2** | **My delegation status** — Whether I currently have an active delegation given or received. |
| 220 | **V2** | **My recent activity** — Quick access to the last 20 items I interacted with. |

---

## Domain 17 — External Reference Linking

*The platform does not manage correspondence documents. It links to them by
reference number so every task retains its external context. External references
may come from the same tenant organization or from another ministry, authority,
agency, university, hospital, company, vendor, or outside system.*

| # | Priority | Feature |
| --- | --- | --- |
| 221 | **MVP** | **Add external reference to task** — Link the task to an external identifier from the same organization or any outside entity/system. |
| 222 | **MVP** | **Categorize reference type** — Correspondence (وارد/صادر), Contract, Ministerial Decision, Authority Decision, Meeting Minute, External Organization Request, Vendor Reference, or Other. |
| 223 | **MVP** | **Enter reference number and issuing entity** — The exact number, issuing entity name, issuing entity type, and source system or organization it belongs to. |
| 224 | **MVP** | **Add multiple references per task** — A task may be linked to more than one external reference. |
| 225 | **MVP** | **Search tasks by external reference number** — Find all tasks linked to a specific internal or external reference across the tenant organization. |
| 226 | **V2** | **View all tasks linked to the same external reference** — See tasks grouped under a shared reference number. |
| 227 | **V2** | **External reference summary view** — Collective status of all tasks sharing a common reference. |

---

## Domain 18 — System Administration

| # | Priority | Feature |
| --- | --- | --- |
| 228 | **MVP** | **Manage organization structure** — Add, edit, or deactivate departments and positions. |
| 229 | **MVP** | **Manage users** — Create, deactivate, and transfer user accounts. |
| 230 | **MVP** | **Configure working calendar** — Official working week and calendar system. |
| 231 | **MVP** | **Configure public holiday calendar** — Annual official holidays per country or entity. |
| 232 | **MVP** | **Configure security classification levels** — Define and rename the three tiers (default: Public, Internal, Confidential). |
| 233 | **MVP** | **Set system language defaults** — Arabic or English as platform-wide primary language. |
| 234 | **MVP** | **Configure branding** — Upload organization logo. |
| 235 | **MVP** | **Manage Blueprint library** — Activate, deactivate, duplicate, or review all Blueprints from a central admin view. |
| 236 | **MVP** | **Configure Blueprint creation permissions** — In MVP, Blueprint creation is limited to tenant admins or users with explicit Blueprint creation capability. |
| 237 | **MVP** | **Manage access permissions (Policy-Based ABAC)** — Control account types, positions, authority grades, capabilities, scoped grants, and relationship-based visibility. |
| 238 | **MVP** | **View system audit logs** — Full user activity log for security and compliance monitoring. |
| 239 | **V2** | **Configure SLA policies** — Create, edit, or retire SLA rules organization-wide. |
| 240 | **V2** | **Configure escalation rules** — When, how, and to whom tasks escalate at each level. |
| 241 | **V2** | **Manage notification templates** — Edit the text of all automated notification messages. |
| 242 | **V2** | **Configure document retention policies** — How long each task category's records must be kept. |

---

## Domain 19 — Multi-Language & Localization

| # | Priority | Feature |
| --- | --- | --- |
| 243 | **MVP** | **Full Arabic RTL interface** — Every screen, form, table, and notification renders correctly in right-to-left Arabic. |
| 244 | **MVP** | **Full English LTR interface** — Complete feature set equally available in left-to-right English. |
| 245 | **MVP** | **Bilingual field entry** — Every content field supports both Arabic and English input. |
| 246 | **MVP** | **Hijri date display** — All dates in Hijri format alongside Gregorian equivalent throughout the platform. |
| 247 | **MVP** | **Hijri date entry** — Users can input dates in Hijri format natively in any date field. |
| 248 | **MVP** | **Localized notifications** — Automated alerts sent in the recipient's chosen language preference. |
| 249 | **V2** | **Arabic numeral formatting** — Eastern Arabic-Indic or Western Arabic numerals per user preference. |

---

## Domain 20 — Workload Management

*Workload management is not AI — it is a capacity counter.
Without it, a manager can unknowingly assign a 43rd active stage to someone
who is already overwhelmed, while a colleague in a comparable position or eligibility group has five.*

| # | Priority | Feature |
| --- | --- | --- |
| 250 | **V2** | **Set workload capacity limit per position** — Define the maximum number of active stage/sub-stage assignments a position holder should carry at one time (e.g., Director of Legal = max 15 active assignments). This is a soft limit that triggers warnings, not a hard block. |
| 251 | **V2** | **Overload warning on stage assignment** — When the system is about to assign a stage to a user who has reached or exceeded their capacity limit, display a warning to the task creator or manager before they confirm. They can proceed or choose someone else. |
| 252 | **V2** | **Workload distribution view for managers** — A heatmap or ranked list showing every person in the department, their active stage count, and their capacity status (Available, Near Capacity, Overloaded). Supports Director-level workload balancing decisions. |
| 253 | **V2** | **Stage assignment recommendation for manual stages** — When a task creator is performing a manual stage assignment (feature #39, Manual at Launch), the system suggests the eligible person with the most available capacity rather than leaving the choice entirely to intuition. The creator is free to override. |

---

## Domain 21 — User Onboarding & Training

*The name for this is In-App Access-Profile-Based Onboarding. Each user, upon first login or
when triggered by an admin, is walked through a guided training journey tailored to
their access profile, derived from account type, position, authority grade, capabilities,
monitoring scope, and task participation. A department leader sees a leadership journey.
A user who owns stages sees a stage-owner journey. Each section ends with a short knowledge check.
This is not a separate learning management system — it is built into the platform itself.*

*Why MVP: GCC government organizations will not adopt a new digital platform without
formal training. If users — especially senior staff — encounter the system without
guidance, they abandon it. In-app onboarding directly reduces the implementation
failure rate. It also positions the product as enterprise-ready.*

### 21A — Onboarding Journeys

| # | Priority | Feature |
| --- | --- | --- |
| 254 | **MVP** | **Access-profile-based onboarding journey** — When a user logs in for the first time, the system detects their access profile from account type, position, authority grade, capabilities, monitoring scopes, and task participation, then launches the appropriate guided journey. Each journey covers only the features and workflows relevant to that access pattern. |
| 255 | **MVP** | **Step-by-step guided walkthrough** — Each journey consists of sequential steps that highlight real UI elements, explain what they are, and show what the user should do. Not a video — an interactive overlay on the actual platform. |
| 256 | **MVP** | **Journey: Organization-wide leadership** — Covers: reading the executive dashboard, understanding Red/Amber/Green status, drilling down into a department's overdue tasks, and understanding how to read an escalation notification. |
| 257 | **MVP** | **Journey: Department leadership** — Covers: the department dashboard, viewing team workload, seeing who currently holds each task, overriding a stage/sub-stage assignment, reading the bottleneck view, and approving a stage. |
| 258 | **MVP** | **Journey: Follow-up / monitoring user** — Covers: the follow-up board, filtering by owner and status, logging a manual follow-up action, reading SLA indicators, and triggering a manual escalation. |
| 259 | **MVP** | **Journey: Stage assignee / employee** — Covers: the personal workspace, understanding My Tasks vs My Pending Actions, submitting stage/sub-stage output, advancing or returning a task, and reading a task timeline. |
| 260 | **MVP** | **Resume journey** — If a user closes the journey mid-way, their progress is saved. They can resume from where they left off at any time from their profile. |
| 261 | **MVP** | **Re-launch journey** — Users can re-run their assigned onboarding journey at any time from their profile settings. Useful when onboarding new staff to an existing account. |
| 262 | **MVP** | **Skip journey with confirmation** — Experienced users can skip the journey, but only after confirming they understand what they are skipping. This is logged. |
| 263 | **V2** | **Admin-triggered onboarding reset** — Admin can force-reset a user's onboarding status, prompting the journey again on next login. Useful when a user is transferred to a new position or receives materially different capabilities. |
| 264 | **V2** | **Custom journey content** — Organization admin can edit the text and steps of each journey to use their own terminology, org chart titles, and workflow names instead of the platform defaults. |

### 21B — Knowledge Checks

| # | Priority | Feature |
| --- | --- | --- |
| 265 | **MVP** | **Knowledge check at end of each journey section** — After each major section of the guided journey, the user answers 3–5 multiple-choice questions to confirm they understood what they just saw. Questions are access-profile-specific and scenario-based (e.g., "A task assigned to you has been at your stage for 4 days and the SLA is 3 days. What should you do?"). |
| 266 | **MVP** | **Pass/Fail threshold per section** — Each knowledge check requires a minimum score (default: 70%) to be considered passed. If the user fails, they must re-view the relevant walkthrough steps before attempting again. |
| 267 | **MVP** | **Re-take knowledge check** — Users can re-attempt any failed check after reviewing the material. Number of attempts is logged. |
| 268 | **MVP** | **Journey completion status** — A user's profile shows whether they have completed their full onboarding journey and passed all knowledge checks (Complete, In Progress, Not Started). |
| 269 | **V2** | **Admin-authored questions** — Organization admin can add, edit, or replace knowledge check questions to match their specific workflows and Blueprint names. |

### 21C — Training Administration

| # | Priority | Feature |
| --- | --- | --- |
| 270 | **MVP** | **Admin training dashboard** — A view showing all users in the organization, their onboarding journey status (Complete / In Progress / Not Started), and the date they completed. |
| 271 | **MVP** | **Filter by department** — Admin can see onboarding completion rates per directorate. |
| 272 | **MVP** | **Notify incomplete users** — Admin can send a reminder notification to all users who have not yet completed their onboarding journey. |
| 273 | **V2** | **Onboarding completion report** — Export a summary of all users, their journey status, quiz scores, and completion dates. Useful for official training records required by some government entities. |
| 274 | **V2** | **Onboarding completion certificate** — Generate a simple printable certificate confirming a user has completed their assigned access-profile training. Some government HR departments require this for system access records. |

---

## Domain 22 — Documentation / Help Center

*A built-in knowledge base that provides employees with self-service guidance inside the platform. Think of it as an internal help center containing operational articles, step-by-step instructions, and platform documentation — accessible without leaving the application.*

| # | Priority | Feature |
| --- | --- | --- |
| 275 | **MVP** | **Create help article** — Author a new article with title (Arabic + English), rich text body content, step-by-step instructions, and inline images or screenshots. |
| 276 | **MVP** | **Set article category** — Classify each article under a tenant-defined category for organized browsing. |
| 277 | **MVP** | **Bilingual article content** — Each article supports full Arabic and English content. Arabic is required; English is optional. |
| 278 | **MVP** | **Publish / unpublish article** — Toggle article visibility. Unpublished articles are drafts visible only to article managers. |
| 279 | **MVP** | **Browse article library** — View all published articles, filterable by category. |
| 280 | **MVP** | **Search articles** — Full-text search across article titles and body content in both languages. |
| 281 | **MVP** | **View article** — Read a published article inline within the platform. |
| 282 | **MVP** | **Edit article** — Update article content, title, category, or images after initial creation. Changes take effect immediately on published articles. |
| 283 | **MVP** | **Delete article** — Soft-delete an article. Removed from the library but preserved in the database for audit purposes. |
| 284 | **MVP** | **Set article display order** — Control the order in which articles appear within a category. |
| 285 | **V2** | **Article version history** — Track all edits to an article with author, timestamp, and diff. |
| 286 | **V2** | **Contextual help links** — Link a specific article to a platform screen or feature so a help icon can surface the relevant guide in context. |
| 287 | **V2** | **Article feedback** — Users can rate articles as helpful or not helpful to guide content improvements. |
| 288 | **V2** | **Article view analytics** — Track how often each article is viewed to identify popular and underused content. |
| 289 | **V2** | **Pin featured articles** — Mark selected articles as featured so they appear prominently at the top of the help center. |

---

## Domain 23 — Platform Administration

*The Central Management layer reserved exclusively for the platform operators (Gov TMS Super Admins). These features execute against the Central Management Database and govern the lifecycle of tenant organizations.*

| # | Priority | Feature |
| --- | --- | --- |
| 290 | **MVP** | **Create new tenant** — Provision a new tenant database, register the domain slug, and create the initial tenant administrator account. |
| 291 | **MVP** | **Suspend tenant** — Disable access for an entire tenant (e.g., for non-payment or compliance breach). All tenant users are locked out, but data remains intact. |
| 292 | **MVP** | **Initiate impersonation session** — A Platform Admin generates a temporary, traceable session to log into a specific tenant environment for troubleshooting without requiring a user password. |
| 293 | **MVP** | **Central impersonation audit log** — The initiation of an impersonation session is logged in the Central DB. Subsequent actions within the tenant are logged in the tenant's audit trail under the impersonator's identity. |
| 294 | **MVP** | **Platform Admin management** — Add or revoke other Platform Admin accounts. |
| 295 | **V2** | **Cross-tenant system metrics** — View aggregated platform usage (total active users, storage consumed, total tasks) across all tenants for billing and monitoring. |
| 296 | **V2** | **Tenant subscription management** — Track billing cycles, tier limits, and automated suspension for overdue accounts. |
| 297 | **V2** | **Global announcement** — Push a system maintenance alert or feature release note to all users across all tenants. |

---

## Priority Summary

| Priority | Approx. Count | Description |
| --- | --- | --- |
| **MVP** | **~178** | Must exist before first paying customer signs |
| **V2** | **~113** | Real and valuable; second major release |
| **V3** | **~5** | Legitimate but complex or narrow |
| **Total** | **~296** |  |

---

## MVP Scope — Included and Deferred

**Fully in MVP:**
Organization Structure · User Management · Blueprint Management (sequential stages,
three assignment resolution methods, sub-stages, one-or-many assignees, SLA per stage/sub-stage, advance/return transitions, edit lock) ·
Task Creation and Lifecycle · Stage Lifecycle (advance, return, override, history) ·
Task Suspension and Resume · External Reference Linking · Follow-Up Board ·
Stage-Level SLA Enforcement · Basic Escalation (auto + manual, one level) ·
In-App and Email Notifications · Executive Dashboard · Director Dashboard ·
Bottleneck View · Task Aging Report · Document Attachments · Comments (basic) ·
Audit Trail · Full-Text Search · Archive · Delegation · Personal Workspace (core) ·
User Onboarding (access-profile journeys + knowledge checks + admin training dashboard) ·
Multi-Language · Documentation / Help Center (article library, categories, bilingual content, search) ·
System Administration (core) · Platform Administration (core tenant management, impersonation)

**Deferred to V2:**
Stage Forms · Parallel Stages · Optional and Conditional Stages · Branching Transitions ·
Blueprint Versioning · Ad-Hoc Stage Insertion · Sub-Tasks · Least Workload and
Round Robin Resolution · Workload Management Domain · Advanced Analytics ·
Period Comparison · Blueprint and Stage Form Analytics · SMS and WhatsApp Notifications ·
Chain Escalation · Recurring Tasks · Saved Searches · Advanced SLA Controls ·
Committee Management · Bulk Operations · Department-Scoped Blueprint Creation ·
Custom Journey Content · Admin-Authored Quiz Questions · Onboarding Reports and Certificates ·
Article Version History · Contextual Help Links · Article Feedback · Article View Analytics

**Deferred to V3:**
Predictive SLA Risk (AI) · G2G Integration · Digital Signature Integration ·
ERP/SAP Integration · Security Clearance Domain

---

## What is Deliberately Excluded

- Correspondence document management (handled by existing government systems)
- Formal letter registration and routing
- G2G secure messaging
- Digital signature integration (UAE PASS, Nafath, Tasdeeq)
- ERP/SAP/Oracle integration
- Procurement workflow module
- Database schema / ERD (Visibility & Access session → ERD)
- ABAC policy model detail (Visibility & Access session)
- Multi-tenancy implementation (technical decision)
- Authentication model (technical decision)

---

*Document version:  1.0*
*Next: Module Boundary Map*
