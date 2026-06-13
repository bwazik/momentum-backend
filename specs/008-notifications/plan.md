# Plan: Notifications

> **Spec:** 008-notifications
> **Date:** 2026-06-13
> **Status:** completed

---

## Open Questions Resolved

| # | Question (from spec) | Decision | Rationale |
|---|----------------------|----------|-----------|
| 1 | Frontend pairing | **Pair with `../frontend/specs/012-personal-workspace` (notifications center).** Roadmap marks 008 backend-only; frontend consumes these APIs. | The personal workspace already owns "My notifications center" (feature #215). |
| 2 | Recipient carried vs resolved | **Resolve recipients inside listeners via read-only service calls.** Existing Task/Tracking events carry only the model (e.g. `StageAssignmentCreated(TaskStageAssignment)`, `SlaBreached(SlaTimerInstance)`, `EscalationCreated(Escalation)`), not recipient ID lists. | Avoids modifying locked 005/006/007 event contracts. Notification module reads via `Task`/assignment relations and the `Escalation` model. No cross-module ORM writes; reads only. |
| 3 | Manager resolution on SLA breach | **Notify the escalation target created by Spec 007 via `EscalationCreated`; do NOT re-resolve managers.** On `SlaBreached`, notify active assignees only; the manager is notified through the `EscalationReceived` notification raised from `EscalationCreated`. | Spec 007 already resolves and persists the escalation target (`escalated_to_user_id`). Re-resolving would duplicate logic and risk drift. |
| 4 | Email transport in MVP | **Use platform default SMTP `from`; tenant branding deferred.** | `architecture.md` lists SMTP as the only mail service for MVP; tenant-level branding is V2 (feature #234 is logo only). |
| 5 | Bulk read-by-array vs read-all | **Ship single mark-read + mark-all-read only.** | Smallest safe change; matches spec acceptance criteria. |
| 6 | Read-notification retention | **Keep indefinitely for MVP.** | No archive/retention spec exists yet; revisit with Spec 015/archive. |
| 7 | Notifications table shape | **Use Laravel default `notifications` schema unchanged** (UUID `id` PK, `type`, `notifiable_type`, `notifiable_id`, `data`, `read_at`, `created_at`, `updated_at`). **No `public_id` column.** API routes bind by the UUID `id`. | Per user instruction: use Laravel's default notification table + system (`php artisan notifications:table`, `DatabaseNotification`, `Notifiable::notify()`). Supersedes the spec's earlier `public_id` note. UUID `id` is already non-enumerable, satisfying the security intent. |

**Spec amendment note:** the spec's "Notifications Table" criterion that adds `public_id` is superseded by Open Question 7. Update the spec checklist accordingly during review.

---

## Technical Approach

**One-line:** Build the **Notification** module under `app/Modules/Notification/` that listens to Task + Tracking domain events, resolves recipients, and sends Laravel notifications via the `database` + `mail` channels, plus 4 read/mutate APIs for the in-app notifications center.

**Key decisions:**
- **Laravel-native notifications.** Use the framework's `Notifiable` trait (already on `User`), `DatabaseNotification` model, and `Illuminate\Notifications\Notification` classes implementing `ShouldQueue`. Each notification class declares `via()` returning `['database', 'mail']`. This satisfies the user instruction and gives queued email + persisted in-app rows for free. (`coding-standards.md` → Queues & Jobs.)
- **One notification class per `NotificationType`** under `app/Modules/Notification/Notifications/`. Each provides `toArray()` (in-app `data` payload with `title_ar/en`, `body_ar/en`, `action_url`, `task_public_id`, ...) and `toMail()` localized to the recipient's `preferred_language`.
- **Listeners resolve recipients, then call `$user->notify(...)` (or `Notification::send($users, ...)`).** One listener per consumed event under `app/Modules/Notification/Listeners/`. Auto-discovered via existing `bootstrap/app.php` `->withEvents(discover: ['app/Modules/*/Listeners'])` — no manual registration.
- **Recipient resolution helper service** `NotificationRecipientResolver` (read-only) centralizes "active assignees of a stage instance", "task initiator", "all active assignees of a task". Reads Task module relations only; never writes.
- **Localization via recipient language.** Notifications read `$notifiable->preferred_language` (cast to `PreferredLanguage` enum) inside `toMail()`/`toArray()` to pick AR vs EN, AR fallback when EN empty. Use Laravel `lang/{ar,en}/notifications.php` translation files. (`security-policy.md` → localized notifications #248.)
- **Idempotency.** Listeners are keyed off terminal domain events that fire once (e.g. `StageAssignmentCreated` per assignment row, `SlaBreached` once-per-timer from Spec 007). For safety against job retries, the database channel write is naturally idempotent only if guarded; add a lightweight guard: skip if a notification of the same `type` for the same `notifiable_id` + `data.dedupe_key` already exists. `dedupe_key` = `"{event}:{entity_public_id}:{recipient_public_id}"`.
- **No Task/Tracking/IAM writes.** Module boundary Rule 4/7: Notification only reads other modules and writes its own `notifications` table. (`architecture.md`.)
- **Read APIs** on `DatabaseNotification` scoped to `$request->user()`. Cursor pagination on the list; scalar unread count. (`coding-standards.md` → Pagination.)

---

## Affected Modules / Files

### New Files

| File | Purpose |
|------|---------|
| **Migration** | |
| `database/migrations/tenant/2026_06_14_000001_create_notifications_table.php` | Laravel default notifications table (UUID `id` PK, morphs, `data`, `read_at`, timestamps). |
| **Enums** | |
| `app/Modules/Notification/Enums/NotificationType.php` | `StageAssignmentReceived`, `TaskReturned`, `TaskAdvanced`, `SlaWarning`, `SlaBreach`, `EscalationReceived`, `TaskCompleted`, `TaskCancelled`, `TaskSuspended`, `TaskResumed`. |
| `app/Modules/Notification/Enums/NotificationChannel.php` | `InApp = 1`, `Email = 2` (used for labelling/config; Laravel `via()` uses string channels). |
| **Notification classes** (extend `Illuminate\Notifications\Notification`, implement `ShouldQueue`) | |
| `app/Modules/Notification/Notifications/StageAssignmentReceivedNotification.php` | New stage/sub-stage assignment. |
| `app/Modules/Notification/Notifications/TaskReturnedNotification.php` | Task returned to a stage the user owns. |
| `app/Modules/Notification/Notifications/TaskAdvancedNotification.php` | Confirmation that a completed stage advanced. |
| `app/Modules/Notification/Notifications/SlaWarningNotification.php` | SLA warning threshold reached. |
| `app/Modules/Notification/Notifications/SlaBreachNotification.php` | SLA breach. |
| `app/Modules/Notification/Notifications/EscalationReceivedNotification.php` | Escalation assigned to manager. |
| `app/Modules/Notification/Notifications/TaskCompletedNotification.php` | Task completed (to initiator). |
| `app/Modules/Notification/Notifications/TaskCancelledNotification.php` | Task cancelled (assignees + initiator). |
| `app/Modules/Notification/Notifications/TaskSuspendedNotification.php` | Task suspended + reason. |
| `app/Modules/Notification/Notifications/TaskResumedNotification.php` | Task resumed. |
| **Listeners** (auto-discovered) | |
| `app/Modules/Notification/Listeners/SendStageAssignmentNotification.php` | on `StageAssignmentCreated`. |
| `app/Modules/Notification/Listeners/SendTaskReturnedNotification.php` | on `StageInstanceReturned`. |
| `app/Modules/Notification/Listeners/SendTaskAdvancedNotification.php` | on `StageInstanceAdvanced`. |
| `app/Modules/Notification/Listeners/SendSlaWarningNotification.php` | on `SlaWarningTriggered`. |
| `app/Modules/Notification/Listeners/SendSlaBreachNotification.php` | on `SlaBreached`. |
| `app/Modules/Notification/Listeners/SendEscalationReceivedNotification.php` | on `EscalationCreated`. |
| `app/Modules/Notification/Listeners/SendTaskCompletedNotification.php` | on `TaskCompleted`. |
| `app/Modules/Notification/Listeners/SendTaskCancelledNotification.php` | on `TaskCancelled`. |
| `app/Modules/Notification/Listeners/SendTaskSuspendedNotification.php` | on `TaskSuspended`. |
| `app/Modules/Notification/Listeners/SendTaskResumedNotification.php` | on `TaskResumed`. |
| **Services** | |
| `app/Modules/Notification/Services/NotificationRecipientResolver.php` | Read-only recipient resolution (active stage assignees, task initiator, all active task assignees). |
| `app/Modules/Notification/Services/NotificationReadService.php` | Mark single / mark-all read (transaction + cache invalidation). |
| **Controllers** | |
| `app/Modules/Notification/Controllers/NotificationController.php` | `index`, `unreadCount`, `markRead`, `markAllRead`. |
| **Requests** | |
| `app/Modules/Notification/Requests/ListNotificationsRequest.php` | Validates `read` filter (`unread`/`read`/`all`) + `per_page`. |
| **Resources** | |
| `app/Modules/Notification/Resources/NotificationResource.php` | JSON shape exposing UUID `id`, `type`, `data`, `read_at`, `created_at`. |
| **Routes** | |
| `routes/api/v1/notifications.php` | Module routes. |
| **Lang** | |
| `lang/ar/notifications.php`, `lang/en/notifications.php` | Localized email/in-app strings. |
| **Tests** | |
| `tests/Feature/Modules/Notification/NotificationDeliveryTest.php` | Event → notification creation per type. |
| `tests/Feature/Modules/Notification/NotificationApiTest.php` | list, unread-count, mark-read, mark-all, ABAC isolation, pagination. |
| `tests/Feature/Modules/Notification/NotificationLocalizationTest.php` | AR/EN selection + AR fallback. |

### Modified Files

| File | Change |
|------|--------|
| `routes/tenant.php` | `require __DIR__.'/api/v1/notifications.php';` |
| `config/logging.php` | Confirm/add `notification` channel (coding-standards already documents it). |
| `app/Support/RateLimits.php` | No change if `LIST`/`MUTATE` exist; reuse them. |

> No `bootstrap/app.php` change needed — listeners auto-discovered under `app/Modules/*/Listeners`. No new service provider required.

---

## Implementation Notes

### 1. Migration — Laravel default notifications table

**Summary:** Use the exact stock schema. No `public_id`, no `tenant_id`.

**File:** `database/migrations/tenant/2026_06_14_000001_create_notifications_table.php`

```php
Schema::create('notifications', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable'); // notifiable_type + notifiable_id, indexed
    $table->text('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();

    $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
});
```

**Standards:** tenant DB, no `tenant_id` (`context.md` rule 1). `morphs()` already indexes `(notifiable_type, notifiable_id)`; composite adds `read_at` for unread-count/filter speed (`coding-standards.md` → Index Strategy).

### 2. Enums

**File:** `app/Modules/Notification/Enums/NotificationType.php` — backed string enum (used as `dedupe_key` prefix and `data.notification_type`). TitleCase keys.

```php
enum NotificationType: string
{
    case StageAssignmentReceived = 'stage_assignment_received';
    case TaskReturned = 'task_returned';
    case TaskAdvanced = 'task_advanced';
    case SlaWarning = 'sla_warning';
    case SlaBreach = 'sla_breach';
    case EscalationReceived = 'escalation_received';
    case TaskCompleted = 'task_completed';
    case TaskCancelled = 'task_cancelled';
    case TaskSuspended = 'task_suspended';
    case TaskResumed = 'task_resumed';
}
```

`NotificationChannel` (int-backed) per coding-standards enum rule; `via()` still returns Laravel string channels `['database','mail']`.

### 3. Notification class pattern (localized, queued)

**Summary:** One class per type. `ShouldQueue` so SMTP never blocks. `via()` = database + mail. `toArray()` builds bilingual in-app payload; `toMail()` localizes by recipient language.

**File (example):** `app/Modules/Notification/Notifications/StageAssignmentReceivedNotification.php`

```php
class StageAssignmentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $taskPublicId,
        public string $taskTitleAr,
        public ?string $taskTitleEn,
        public string $stageNameAr,
        public ?string $stageNameEn,
        public string $dedupeKey,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => NotificationType::StageAssignmentReceived->value,
            'dedupe_key' => $this->dedupeKey,
            'title_ar' => __('notifications.stage_assignment.title', [], 'ar'),
            'title_en' => __('notifications.stage_assignment.title', [], 'en'),
            'body_ar' => __('notifications.stage_assignment.body', ['stage' => $this->stageNameAr], 'ar'),
            'body_en' => __('notifications.stage_assignment.body', ['stage' => $this->stageNameEn ?? $this->stageNameAr], 'en'),
            'task_public_id' => $this->taskPublicId,
            'action_url' => "/tasks/{$this->taskPublicId}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language === PreferredLanguage::English ? 'en' : 'ar';
        $title = $locale === 'en' ? ($this->taskTitleEn ?? $this->taskTitleAr) : $this->taskTitleAr;

        return (new MailMessage)
            ->subject(__('notifications.stage_assignment.subject', [], $locale))
            ->line(__('notifications.stage_assignment.body', ['stage' => $this->stageNameAr], $locale))
            ->action(__('notifications.view_task', [], $locale), url("/tasks/{$this->taskPublicId}"));
    }
}
```

**Standards:** `ShouldQueue` + `tries=3` + `backoff=[30,60,120]` (`coding-standards.md` → Queues). PII: pass IDs/titles only, never full PII payloads to logs.

### 4. Listener pattern (resolve recipients + dispatch, idempotent)

**Summary:** Resolve recipients read-only, build dedupe key, guard duplicates, send.

**File (example):** `app/Modules/Notification/Listeners/SendStageAssignmentNotification.php`

```php
class SendStageAssignmentNotification
{
    public function __construct(private NotificationRecipientResolver $resolver) {}

    public function handle(StageAssignmentCreated $event): void
    {
        try {
            $assignment = $event->assignment->loadMissing(['user', 'stageInstance.task', 'stageInstance.blueprintStage']);
            $user = $assignment->user;
            if (! $user || ! $user->is_active) {
                return;
            }

            $task = $assignment->stageInstance->task;
            $stage = $assignment->stageInstance->blueprintStage;
            $dedupe = 'stage_assignment_received:'.$assignment->stageInstance->public_id.':'.$user->public_id;

            if ($this->alreadyNotified($user, NotificationType::StageAssignmentReceived, $dedupe)) {
                return;
            }

            $user->notify(new StageAssignmentReceivedNotification(
                taskPublicId: $task->public_id,
                taskTitleAr: $task->title_ar,
                taskTitleEn: $task->title_en,
                stageNameAr: $stage->name_ar,
                stageNameEn: $stage->name_en,
                dedupeKey: $dedupe,
            ));

            Log::channel('notification')->info('Notification dispatched', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'entity_type' => 'notification',
                'entity_id' => $dedupe,
                'performed_by' => 'system',
                'source_event' => 'StageAssignmentCreated',
                'recipient' => $user->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notification')->error('Failed to send stage assignment notification', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'notification.create',
                'source_event' => 'StageAssignmentCreated',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alreadyNotified($user, NotificationType $type, string $dedupe): bool
    {
        return $user->notifications()
            ->where('type', /* notification class FQCN */ StageAssignmentReceivedNotification::class)
            ->where('data->dedupe_key', $dedupe)
            ->exists();
    }
}
```

**Standards:** try/catch + `Log::channel('notification')` with structured context (`coding-standards.md` → Error Handling). Read-only cross-module access (`architecture.md` Rule 4/7). No transaction needed for single-recipient send; fan-out listeners (breach, cancel, suspend, resume) wrap their multi-send loop in `DB::transaction()`.

### 5. Recipient resolver (read-only)

**File:** `app/Modules/Notification/Services/NotificationRecipientResolver.php`

```php
class NotificationRecipientResolver
{
    /** Active, non-completed, non-reassigned assignees of a stage instance. */
    public function activeStageAssignees(TaskStageInstance $stageInstance): Collection
    {
        return $stageInstance->assignments()
            ->where('is_completed', false)
            ->whereNull('reassigned_at')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn ($u) => $u && $u->is_active)
            ->unique('id')
            ->values();
    }

    /** All active assignees across the task's active stage instances + initiator. */
    public function activeTaskParticipants(Task $task): Collection { /* ... union, unique by id ... */ }

    public function initiator(Task $task): ?User
    {
        return $task->initiator; // belongsTo users
    }
}
```

**Standards:** No writes; only reads Task module relations (`architecture.md`). Eager-load to avoid N+1 (`coding-standards.md` → Performance).

### 6. Fan-out listener (transaction) — SLA breach example

```php
public function handle(SlaBreached $event): void
{
    try {
        $stage = $event->timer->stageInstance ?? $event->timer->subStageInstance?->parentStageInstance;
        if (! $stage) { return; }
        $assignees = $this->resolver->activeStageAssignees($stage);
        if ($assignees->isEmpty()) { return; }

        DB::transaction(function () use ($assignees, $event, $stage) {
            foreach ($assignees as $user) {
                $dedupe = 'sla_breach:'.$event->timer->public_id.':'.$user->public_id;
                if ($this->alreadyNotified($user, $dedupe)) { continue; }
                $user->notify(new SlaBreachNotification(/* ... */ dedupeKey: $dedupe));
            }
        });
        // Manager is notified separately via EscalationCreated (Open Q #3).
    } catch (\Throwable $e) {
        Log::channel('notification')->error('SLA breach notification failed', [/* context */]);
    }
}
```

**Standards:** `DB::transaction()` for multi-write fan-out (`coding-standards.md` → Transactions).

### 7. Controller + APIs

**File:** `app/Modules/Notification/Controllers/NotificationController.php`

```php
class NotificationController extends Controller
{
    use HasRateLimiting;

    public function __construct(private NotificationReadService $readService) {}

    public function index(ListNotificationsRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $query = $request->user()->notifications()->getQuery()->orderByDesc('id');
        $filter = $request->validated()['read'] ?? 'all';
        if ($filter === 'unread') { $query->whereNull('read_at'); }
        if ($filter === 'read') { $query->whereNotNull('read_at'); }

        $paginator = $query->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($n) => new NotificationResource($n));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        return response()->json(['unread_count' => $this->readService->unreadCount($request->user())]);
    }

    public function markRead(Request $request, string $notification)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->readService->markRead($request->user(), $notification); // 404 if not owner
        return response()->noContent();
    }

    public function markAllRead(Request $request)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->readService->markAllRead($request->user());
        return response()->noContent();
    }
}
```

**`markRead` ownership guard (security):** resolve via the user's own relation so cross-user access is impossible:
```php
$note = $user->notifications()->whereKey($notificationId)->firstOrFail(); // 404 if not owner
$note->markAsRead();
```

**Standards:** cursor pagination + `{data,next_cursor,has_more}` envelope (`coding-standards.md`); `LIST` on reads, `MUTATE` on writes (Rate Limiting); user-scoped queries enforce isolation (`security-policy.md`).

### 8. Routes

**File:** `routes/api/v1/notifications.php`

```php
Route::middleware(['auth:sanctum'])->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('read-all', [NotificationController::class, 'markAllRead']);
    Route::post('{notification}/read', [NotificationController::class, 'markRead']);
});
```
> Register `unread-count` and `read-all` BEFORE `{notification}/read` to avoid the wildcard swallowing them.

### 9. NotificationReadService (transaction + optional cache)

```php
public function unreadCount(User $user): int
{
    $key = (tenant()?->slug ?? 'central').":notification:unread_count:{$user->public_id}";
    return Cache::remember($key, 60, fn () => $user->unreadNotifications()->count());
}

public function markRead(User $user, string $id): void
{
    $user->notifications()->whereKey($id)->firstOrFail()->markAsRead();
    Cache::forget((tenant()?->slug ?? 'central').":notification:unread_count:{$user->public_id}");
}

public function markAllRead(User $user): void
{
    DB::transaction(fn () => $user->unreadNotifications->markAsRead());
    Cache::forget((tenant()?->slug ?? 'central').":notification:unread_count:{$user->public_id}");
}
```
**Standards:** tenant-prefixed key, 60s hot TTL, event/action invalidation (`coding-standards.md` → Caching); `DB::transaction()` on bulk update.

### Test cases (input → expected)

- **Delivery:** Fire `StageAssignmentCreated` for assignee U → `notifications` has 1 row, `notifiable_id = U.id`, `type = StageAssignmentReceivedNotification`, `data.notification_type = stage_assignment_received`. Re-fire same event → still 1 row (dedupe).
- **API isolation:** User A `GET /v1/notifications` after a notification was sent to User B → `data` is empty; A cannot `POST /v1/notifications/{B_notification_id}/read` → 404.
- **Localization:** Recipient `preferred_language = English` → `Notification::fake()` asserts `toMail` subject in EN; recipient `Arabic` → AR subject.
- **Unread count:** Send 2 → `unread-count` returns 2; `read-all` → returns 0.

---

## Execution Order

1. ✅ **Migration** — create stock `notifications` table (tenant). Depends on: nothing.
2. ✅ **Enums** — `NotificationType`, `NotificationChannel`. Depends on: 1.
3. ✅ **Lang files** — `lang/{ar,en}/notifications.php` keys for all 10 types.
4. ✅ **Notification classes** (10) — `ShouldQueue`, `via()`, `toArray()`, `toMail()`. Depends on: 2,3.
5. ✅ **NotificationRecipientResolver** — read-only resolution helpers. Depends on: Task models.
6. ✅ **Listeners** (10) — resolve + dedupe + dispatch; fan-out ones use `DB::transaction()`. Depends on: 4,5. Auto-discovered.
7. ✅ **NotificationReadService** + **NotificationResource** + **ListNotificationsRequest**. Depends on: 1.
8. ✅ **NotificationController** + **routes** + register in `routes/tenant.php`. Depends on: 7.
9. ✅ **config/logging.php** — added `notification` channel.
10. ✅ **Tests** (delivery, API, localization). 23 tests, 51 assertions.
11. ✅ `vendor/bin/pint --dirty --format agent`, then `php artisan test --filter="Modules\\Notification"` — all passing.

---

## API Contract Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/notifications` | Sanctum + `X-Tenant` | Cursor-paginated list of caller's notifications, newest first; `?read=unread\|read\|all`. |
| GET | `/api/v1/notifications/unread-count` | Sanctum + `X-Tenant` | Caller's unread count. |
| POST | `/api/v1/notifications/{notification}/read` | Sanctum + `X-Tenant` | Mark one (by UUID `id`) read; 404 if not owner. |
| POST | `/api/v1/notifications/read-all` | Sanctum + `X-Tenant` | Mark all caller's unread read. |

Response shapes: list = `{data:[...], next_cursor, has_more}`; unread-count = `{unread_count: int}`; mark endpoints = `204`.

---

## What to Test Manually

1. **Assignment:** Launch a task → Stage 1 assignee receives in-app row + queued email in their language.
2. **Return:** Return a task to an earlier stage → target stage assignees notified.
3. **Advance confirmation:** Complete a stage → completing assignee gets advance confirmation; next assignees get assignment notification (no double-notify).
4. **SLA warning/breach:** Force `warning_at`/`deadline_at` past → run `php artisan schedule:run` (Spec 007 scan) → assignees get warning then breach; manager gets escalation-received (from `EscalationCreated`), not a duplicate breach.
5. **Lifecycle:** Cancel / suspend (with reason) / resume / complete → initiator + active assignees notified; suspend body contains the reason.
6. **Localization:** Set a user to English, another to Arabic → verify email subject/body language and AR fallback when EN title empty.
7. **List + filters:** `GET /v1/notifications?read=unread` returns only unread; pagination via `next_cursor` works.
8. **Unread count + caching:** Hit `unread-count` twice within 60s (cache hit), then `read-all` → count drops to 0 immediately (cache invalidated).
9. **Rate limiting:** Exceed 60 `LIST`/min → 429 with `Retry-After`; exceed 30 `MUTATE`/min on read-all → 429.
10. **ABAC isolation:** User A cannot list or mark User B's notifications (empty list / 404).
11. **Idempotency:** Replay the same domain event (re-run a queued listener) → no duplicate notification row.
12. **Email failure isolation:** Break SMTP → in-app row still persists; mail job retries 3x then logs `failed()` to `notification` channel.
13. **Tenant isolation:** Notification created in tenant A absent from tenant B DB.
