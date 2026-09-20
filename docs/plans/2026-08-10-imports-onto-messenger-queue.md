# Plan: move imports onto a Messenger queue

Status: **proposal, nothing written yet.**
Base: `main` @ `7c7e81d`

## The change in one line

An importer stops spawning a subprocess and instead dispatches a message. Messenger owns
everything after that — claiming, ordering, retrying, parking failures.

```php
// before — ProductImportController::spawnImportProcess()
$process = new Process([$php, 'bin/console', 'app:product-import', $csvPath, ...]);
$process->disableOutput();
$process->start();

// after
$bus->dispatch(new ProductImportMessage($csvPath, $token, $primaryKey, $missingRows, $clear));
```

## Why (and why not the alternatives)

The original ask was "exit safely if another worker exists." A lock answers that badly: exit and
you lose the file, wait and you have hand-rolled a queue, and you end up with two competing
notions of "running" (the lock, and `ProductImportRunLog`'s status) that disagree the moment a
process dies.

A queue makes serialisation structural — one consumer, so two imports cannot overlap regardless
of who clicks what — and makes the work durable, which spawning never was.

**Facts that shaped this, worth re-checking if they change:**

- Imports take **~2 seconds**. So overlap is rare and latency barely matters either way.
- The mail worker is **alive ~99% of the time** (`--time-limit=595` against a 600s cron), so
  dispatched work is picked up in about a second. "Next tick" latency does not apply.
- Transport is **Doctrine on SQLite**, and queues are already separated by `queue_name`
  (`failed` does exactly this today) — so a new queue needs **no migration**.

## Files

### New

```
src/Message/ProductImportMessage.php              readonly DTO: csvPath, token, primaryKey,
                                                  missingRows, clearApprovedBalance
src/MessageHandler/ProductImportHandler.php       #[AsMessageHandler], calls the runner
src/Service/ProductImport/ProductImportRunner.php THE logic, lifted verbatim out of the command

Number1RimImportBundle\Message\RimSyncMessage      readonly DTO: force
Number1RimImportBundle\MessageHandler\...Handler  guard + existing sync logic

src/Entity/JobRun.php                             the ledger (see D7)
src/Repository/JobRunRepository.php
src/EventSubscriber/JobRunSubscriber.php          Messenger worker events -> JobRun rows
migrations/VersionXXXXXXXX.php                    creates job_run
src/Scheduler/MainSchedule.php                    #[AsSchedule], hourly RimSyncMessage
```

`ProductImportRunner` is the important one. The command's `execute()` currently holds the real
sequence — `runLog->start()`, file-exists check, wrap in `UploadedFile`, `importService->import()`,
`finish()`/`fail()`, `unlink()` in `finally`. That moves into the runner **unchanged**, and both the
handler and the command call it. Two copies of that body is exactly the failure mode #521 was about.

### Edited

```
src/Command/ProductImportCommand.php              keeps its arguments; body becomes a runner call
src/Controller/Admin/ProductImportController.php  dispatch instead of spawnImportProcess()
config/packages/messenger.yaml                    new transport + routing
```

### Deleted

```
ProductImportController::spawnImportProcess()     ~25 lines
  └── with it: PhpExecutableFinder, the APP_ENV/APP_DEBUG passing that kept the child on the
      right database, disableOutput(), and the fire-and-forget comment explaining why we never
      wait(). All of it exists only to hand-roll what Messenger does.
```

## Config

```yaml
transports:
    async:   '%env(MESSENGER_TRANSPORT_DSN)%'
    imports: 'doctrine://default?queue_name=imports&auto_setup=0'   # NEW
    failed:  'doctrine://default?queue_name=failed'

routing:
    Symfony\Component\Mailer\Messenger\SendEmailMessage: async
    App\Message\ProductImportMessage: imports                        # NEW
    Number1RimImportBundle\Message\RimSyncMessage: imports           # NEW (see "Scheduling")
```

`when@test` must also get `imports: 'sync://'`, or every test that triggers an import will queue
it and never run it.

## Scheduling — the recurring rim job

**It is not scheduled today.** Prod's crontab holds one line (the mail worker); the rim sync only
ever runs when someone clicks it in the admin UI. So "runs daily" is new behaviour, not a migration
of something existing.

Whatever schedules it should **enqueue, not execute** — so the scheduled run goes down the same path
as the manual button, and serialises against product imports instead of colliding with one on
SQLite.

### Chosen: `symfony/scheduler`, firing HOURLY with a guard in the handler

```bash
composer require symfony/scheduler
```

```php
// src/Scheduler/MainSchedule.php
#[AsSchedule]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every('1 hour', new RimSyncMessage()),
        );
    }
}
```

The guard lives **inside the rim handler**, not in a separate layer — nothing outside the job
should need to know when it last ran. The scheduler stays dumb: fire hourly, know nothing.

```php
final class RimSyncMessage
{
    // false = the timetable prodded me; true = a human asked for it
    public function __construct(public readonly bool $force = false) {}
}
```

```php
// RimSyncHandler
if (!$message->force && $status->lastSuccessAt() !== null
    && $status->lastSuccessAt() > $now->modify('-23 hours -30 minutes')) {
    return;   // not due; silent no-op
}
```

**The `force` flag is not optional.** Without it the guard also blocks the admin's "Sync now"
button — click it an hour after the nightly run and nothing happens, silently, which is worse than
the problem this plan started from. One handler, one code path; the difference between a scheduled
prod and a human request is carried by the message.

**A missing or unreadable status must fail OPEN (run), never closed.** Failing closed means the job
silently never runs again and the only symptom is absence. Failing open costs one redundant
2-second sync. `lastSuccessAt() === null` is therefore "due", not "unknown, skip".

**Why hourly rather than `0 3 * * *`.** A daily trigger has exactly one chance to fire, and
Scheduler evaluates the timetable from a *running worker* — so if nothing is alive at that instant,
the firing is gone (see "missed window" below). Firing hourly and letting the handler decide
inverts that: the schedule becomes a prompt, and `RimSyncStatus` becomes the source of truth for
whether a run is due — which also means that file stops being informational and becomes
load-bearing. See the fail-open rule above.

The failure math changes completely. A daily trigger needs one unlucky moment to skip a day; the
hourly version needs **24 consecutive misses**. It converges on "roughly daily" instead of
depending on a single instant, which is what makes it survive an outage rather than silently skip.

**Consequences of this shape, deliberately accepted:**

- **It is not an exact-time job.** It runs at *roughly* the same hour, drifting by up to an hour
  after any delay. Fine for a supplier sync; wrong if it ever has to land inside a specific window
  (before a business process, after a supplier feed publishes). If that changes, this is the design
  decision to revisit.
- **The handler runs 24× a day and no-ops 23 times.** A timestamp comparison, microseconds. But
  the skip path must stay **silent** — no log line — or `var/log/import-worker.log` fills with 23
  daily "not due yet" entries and stops being readable.
- **Stamp the attempt, not only the success.** With `lastSuccessAt` alone, a job that fails at 03:00
  retries at 04:00, 05:00 and every hour until it works — free retries, or a broken supplier API
  hammered 24×/day, depending on your view. Two timestamps (`lastAttemptAt`, `lastSuccessAt`) let
  you have both: skip if attempted within the hour, keep retrying until a *success* is ≥23h55m old.
  **This is the part most likely to be got wrong.**

**It also makes the cron-vs-Scheduler argument mostly moot.** Neither is load-bearing any more —
the guard is. Whichever fires more reliably is now a marginal gain, so if `symfony/scheduler` ever
feels like overhead, an hourly crontab `--dispatch` line is an easy fallback with no change to the
handler.

Each firing is an ordinary Messenger message routed to `imports`, so it inherits the same worker,
the same serialisation, and the same failure handling as everything else on that queue.

**Why this over a crontab line that dispatches:** the timetable becomes code — version-controlled,
reviewable, and identical on dev, staging and prod instead of three hand-maintained crontabs that
drift. For one job that is a thin argument; it earns its keep at the second and third.

**The cost:** something must consume the scheduler transport. That is NOT a separate worker — the
same worker takes both, and it must:

```yaml
transports:
    scheduler_main: 'schedule://main'
```

```
*/10 * * * * ... flock -n ~/import-worker.lock -c "... messenger:consume imports scheduler_main --time-limit=595 --env=prod"
```

**Why they must share one worker, not run as two.** A message received from a transport carries a
`ReceivedStamp`, which stops Messenger re-routing it — so a scheduled `RimSyncMessage` is handled
**in-process by whatever worker consumed it**, and never travels to the `imports` queue. A separate
scheduler worker would therefore run the rim sync at 03:00 *while* the imports worker was mid
product-import:

```
imports worker:    [product import]
scheduler worker:  [rim sync]        ← same moment, same SQLite file
```

That is the exact collision this whole plan exists to remove, reintroduced by the scheduling
mechanism. One worker consuming both transports makes serialisation structural again.

One consequence: while the worker is busy, the schedule is not being polled, so a firing can be a
few seconds late. Irrelevant for a daily job against ~2s imports; would matter for a minute-level
schedule.

Note the routing entry for `RimSyncMessage` therefore only applies to the **manual** dispatch from
the admin controller. The scheduled firing bypasses routing entirely. Same handler either way.

### Behaviour worth knowing before committing to it

**A missed window is missed, not queued.** The schedule is evaluated by a *running* worker; it is
not a persisted table the OS walks. Symfony keeps a checkpoint and catches up a recent miss, but a
worker down since yesterday will not replay a day of firings. With `--time-limit=595` on a 600s
cron there is a ~5s dead window per 10 minutes (~0.8%), and the checkpoint covers most of that.

**The hourly guard is what actually neutralises this**, which is why it was chosen: the exposure
stops being "did the worker happen to be alive at 03:00" and becomes "was it down for 24 straight
hours". The remaining risk is a long outage — cron disabled, `flock` left held by a wedged process,
box down for maintenance — where cron would fire on recovery and Scheduler shrugs. That is what D5's
alerting half is for.

**Timezone** stops mattering with `every('1 hour')` — there is no wall-clock time to get wrong. It
would come back immediately if this ever reverts to a cron expression.

### Rejected alternative, for the record

```
0 3 * * *  bin/console number1-rim-import:sync --dispatch
```

One crontab line, no dependency, no extra worker — the command dispatches and exits in
milliseconds. Strictly smaller. Rejected because the schedule should live in the repo rather than in
three separate crontabs; revisit if the fourth worker proves annoying.

## Cron — two lines

```
*/10 * * * *  messenger:consume async                                ← mail (exists, unchanged)
*/10 * * * *  flock -n ... messenger:consume imports scheduler_main  ← everything else
```

Two workers total, one of them already exists. The second is the single sequential consumer for all
import work — manual product import, manual rim click, and the daily scheduled rim job alike.

Adding more scheduled jobs later does not add cron lines: they go into the same `Schedule` and the
same worker fires them. The second line is a fixed cost for having scheduling at all.

## Decisions needed

### D1 — Does the rim importer move too, or product only first?  [DECIDED: both]

Both importers move in the same change. `Number1RimImportBundle` spawns `number1-rim-import:sync`
identically, so it gets the same treatment onto the same queue — which is also the only thing that
makes the two serialise against each other rather than colliding on SQLite. Doing product alone
would leave the bundle spawning subprocesses and defeat half the point.

**Consequence — this is one PR, not two, and it is not small:**

```
core:    ProductImportMessage, ProductImportHandler, ProductImportRunner
         ProductImportController -> dispatch
bundle:  RimSyncMessage (+ force), RimSyncHandler (+ guard)
         RimImportController -> dispatch, spawnSync() deleted
both:    JobRun entity/repo/subscriber + migration, MainSchedule,
         messenger.yaml transports + routing, two cron lines
```

**Deploy ordering matters.** Once controllers dispatch instead of spawning, imports do nothing at
all until a worker is consuming `imports`. So the cron line has to exist on each box *before* or
*with* the code, not after — otherwise uploads silently queue and never run. Applies to dev,
staging (`wholesale-dev-002`) and prod; all three need the new crontab entry.

**Rollback is not just a git revert** for the same reason: reverting the code while messages sit in
`imports` strands them. Drain the queue first, or accept that queued-but-unrun imports need
re-uploading.

- **Decision:** both, one PR.
- **TODO:**

### D2 — Retry policy  [DECIDED: throw, zero retries]

**The handler must let exceptions propagate.** Not for retries — because if it catches, Messenger
fires `WorkerMessageHandledEvent` and the `JobRun` subscriber records **succeeded** for a job that
failed. `ProductImportRunLog` would say failed and `job_run` would say succeeded, and the ledger
would be lying about exactly the case it exists for.

**No automatic retries.** Re-running a CSV that choked on bad data fails the same way and risks
double-applying whatever it wrote before dying.

```yaml
imports:
    dsn: 'doctrine://default?queue_name=imports&auto_setup=0'
    retry_strategy:
        max_retries: 0
```

A failed import then produces:

- `job_run` -> failed, with the reason (because the exception escaped)
- `ErrorLog` -> exception + stack trace
- `ProductImportRunLog` -> per-run detail
- `failed` queue -> the message itself, parked: `messenger:failed:show <id> -vv`,
  `messenger:failed:retry` once the CSV is fixed

That last one is the point of routing failures to `failed` — not extra logging, but the *message
kept*, so the same import can be re-run after fixing the cause instead of re-uploading and hoping.

The catch-and-record shape below is what the command does today, and is retained ONLY inside the
runner for the CLI path (where there is no Messenger to report to). The handler must not use it.

---

Original framing, kept for context:


The handler will catch `Throwable` and record it via `runLog->fail()`, as the command does now — so
no exception escapes and Messenger never retries. That is probably right (re-running an import that
choked on bad data just repeats the failure and duplicates partial writes), but it means the
`failed` queue stays empty for imports.

Alternative: let it throw, set `max_retries: 0`, and get the exception parked in `failed` with a
stack trace and `messenger:failed:retry` available.


### D3 — Local/dev behaviour  [DECIDED: doctrine locally, with a local cron]

Local uses the same transport as the servers, and a local crontab entry mirroring prod:

```
*/10 * * * * cd ~/Projects/wholesale-b2b-core && flock -n /tmp/wb2b-import.lock -c "php bin/console messenger:consume imports scheduler_main --time-limit=595 --env=dev"
```

`cron` is active on the dev machine (checked), so this is the same shape as prod — same lock, same
time-limit arithmetic. The point is that a message which cannot survive serialisation fails on the
laptop rather than on a server, which `sync://` can never surface because the message never leaves
the request.

**`when@test` still gets `sync://`** — not a preference. Tests cannot run a worker, so a queued
import in a test would never execute.

**Known friction, accepted:** a running worker holds compiled container and handler code, so an edit
does not take effect until that worker exits and cron starts a fresh one — up to 10 minutes. While
actively editing a handler, run the worker by hand instead (`Ctrl-C`, restart, change is live). The
lock stops the hand-run worker and the cron one from fighting.

- **Decision:** doctrine locally + local cron; `sync://` in test only.
- **TODO:**

---

Original framing, kept for context:

`.env.local` sets `MESSENGER_TRANSPORT_DSN=sync://`, but the imports DSN above is hardcoded
doctrine — so locally you'd need a worker running to test an import at all.

Either make it `%env(MESSENGER_IMPORTS_DSN)%` with a `sync://` override locally (instant, no
worker, but dev no longer resembles prod), or keep it doctrine and run
`messenger:consume imports` when testing.


### D4 — Keep `app:product-import`?  [DECIDED: keep]

Kept, and the same applies to `number1-rim-import:sync`. Both stay runnable directly, bypassing the
queue entirely — the escape hatch when the worker is wedged, the queue is drained, or a CSV needs
debugging without going through the UI.

This is what makes `ProductImportRunner` non-negotiable rather than a nicety: the command and the
handler must call one implementation, or the "run it directly" path quietly drifts from the real
one and stops being a faithful reproduction of the bug you are chasing.

**Every run must be logged, on every path.** Requirement, not a nicety — see "Run history is
currently broken for rim" below. Running a command directly bypasses Messenger, so no worker events
fire; the ledger must not have a hole there.

**The runner owns the `JobRun` row.** It is the single funnel every path goes through — handler ->
runner, command -> runner — so writing it there logs each run exactly once regardless of trigger,
with a `source` column (`queue` | `cli`) recording which.

The subscriber then acts only as a **safety net**, for failures that happen before the handler is
ever reached (message deserialisation, missing handler) where the runner never runs. It must
therefore write only when no row exists for that message, or normal runs get two rows.

**It bypasses serialisation, deliberately.** Running the command by hand while a worker is
mid-import puts two importers on SQLite at once — the thing the queue otherwise prevents. That is
allowed on purpose: reaching for the CLI means something is already wrong or urgent, and a tool
that refuses to run precisely when it is needed most is worse than one that lets an operator
proceed knowingly.

So: **no lock, no refusal, no confirmation prompt on the command.** State the consequence in the
command's description so it is an informed choice rather than a surprise, and record `source: cli`
on the `JobRun` row so an overlapping run is identifiable afterwards rather than mysterious.

- **Decision:** keep both commands.
- **TODO:**

### D5 — Guard interval + alerting  [DECIDED: 10-min tick + 23h55m, and yes alert]

**Interval: 23h55m on a 10-minute tick — and the reason matters more than the number.**

The run time is not the guard — it is the first *tick* after the guard expires. Illustrated with
hourly ticks (the principle, not the chosen values):

    run 03:00 -> eligible 02:30 -> ticks 02:00 (no), 03:00 (yes) -> runs 03:00   [pinned]

**The guard must fall strictly between two ticks.** If it lands exactly on one, it fires early and
walks backwards:

    guard 23h, hourly ticks -> eligible exactly on a tick -> runs at T+23h -> 1h earlier every day

And if the tick rate were finer, the guard would be honoured almost exactly and drift by the
difference — a 10-minute tick with a 23.5h guard would creep 30 minutes earlier per day, around the
clock.

So the rule is: **guard strictly inside (tick_period, target_period)**. With hourly ticks and a
daily target, anything in (23h, 24h) exclusive is stable; 23.5h is the safe middle. Note the
scheduler tick is INDEPENDENT of the 10-minute cron — the cron only replaces the worker and has no
bearing on drift.

**Chosen: 10-minute tick, 23h55m guard. Pinned, no drift.**

```php
RecurringMessage::every('10 minutes', new RimSyncMessage())
```
```php
if (!$force && $status->lastSuccessAt() > $now->modify('-23 hours -55 minutes')) {
    return;
}
```

23h55m is 1435 minutes — deliberately NOT a multiple of the 10-minute tick. It expires five minutes
before the 24h tick and therefore rounds up to it:

    run 03:00 -> eligible 02:55 -> next tick 03:00 -> runs 03:00   [pinned]

**It self-corrects.** A run delayed to 03:03 is eligible at 02:58, and the next tick is still 03:00 —
so it snaps back to the boundary instead of accumulating the delay. The five-minute margin is the
safety, and it is ample at a 10-minute tick.

This gives both properties at once: 10-minute recovery granularity after an outage, AND a stable
time of day — with no wall-clock anchor and therefore no timezone to get wrong.

**The value is coupled to the tick rate.** Changing either without the other reintroduces drift:
the guard must land strictly between two ticks, just under the target. If the tick ever changes,
recompute — do not carry 23h55m across.

**Alerting: yes.**

The daily prune already writes a `job_run` row, so absence of rows is the signal that the scheduler
has stopped — but that only helps someone who looks. Something should say so actively.

Cheapest form: on the job log / system page, surface "last successful <job_type>" per job with a
staleness threshold, so a stopped scheduler is visible where an operator already is. Escalating to
email is a further step and should reuse the existing mail path rather than inventing one.

Worth deciding at build time: what threshold counts as stale per job type (the rim sync at >26h,
the prune at >26h), and whether the alert fires once or repeats.

- **Decision:** 10-min tick + 23h55m guard (pinned); alert on staleness, surfaced on the job log page.
- **TODO:**
### D6 — Does the manual admin button still exist?  [DECIDED: yes, force: true]

Stays, dispatching `new RimSyncMessage(force: true)` — same handler, same queue, guard bypassed.
One code path, two triggers (the timetable, a human click), which is the structural win of
enqueueing rather than executing.

Note it still goes through the queue, so it is still serialised against a running import — unlike
the CLI escape hatch (D4), which bypasses that deliberately. Button = urgent but orderly; CLI =
break glass.

- **Decision:** yes, `force: true`.
- **TODO:**

### D7 — Is there a job ledger, or do we keep per-feature status files?
There is no unified record of what ran. `messenger_messages` is a work list, not history — Doctrine
deletes the row on ack, so a successful job leaves no trace. `failed` holds only exhausted failures.
Product imports keep per-run JSON in `var/import_runs/`; the rim sync keeps only "last run",
overwritten. Monolog is not installed. Anything added later gets nothing unless it builds its own.

This matters more after this change, not less: today "did the import run?" is answerable because a
process was spawned and wrote a run file. Once everything is a message, the interesting events —
queued at, picked up at, succeeded or failed — are exactly the ones nothing records.

Messenger already emits `WorkerMessageReceivedEvent` / `WorkerMessageHandledEvent` /
`WorkerMessageFailedEvent`, so one subscriber writing to a `job_run` table would give a single
ledger across every job type without each feature inventing its own.

Separate work from the queue migration. Ship the queue first and add the ledger after, or build
both so visibility does not regress?

- **Decision:** job ledger table — yes. Monolog — **deferred, probably not needed** (see below).
- **TODO:**

**Note on monolog vs the ledger — they are not the same thing.** Monolog provides log *files*
(levels, channels, handlers). The question above is "did this job run?", which wants a queryable
record surfaced in the admin UI, not a file that requires SSH into cPanel.

The repo already answered this once, deliberately: `ErrorLogSubscriber` writes to the `ErrorLog`
**entity**, viewable at `/admin/error-log`. That is why monolog was never installed — and why
symptoms currently land in `public/error_log` rather than `var/log/prod.log` (with no monolog,
Symfony falls back to PHP's error log).

So installing monolog is defensible on its own merits — structured levels, capturing third-party
library logs, somewhere to attach handlers — but it does **not** close D7. A job ledger still needs
the Messenger event subscriber and somewhere queryable to put the rows.

**Revisited: with the ledger in place, monolog has little left to do.**

| Question | Answered by |
|---|---|
| Did the rim sync run today? Did it fail? | `job_run` (new) |
| What blew up, and where? | `ErrorLog` -> `/admin/error-log` (exists) |
| How many rows did that import touch? | `ProductImportRunLog` (exists) |
| What did a third-party library log at info level? | monolog |

The first three are the operational questions, and all three are answered in the database and
visible in the admin UI without SSH. Monolog covers only the fourth, and costs a dependency, config
surface, unrotated files on cPanel, and — the real cost — a *second place to look*. Today "something
went wrong" has one answer: check the admin.

Deferred rather than rejected: if an incident later leaves us blind because a library logged
something unreachable, add it then, with a concrete reason. If it is added, use a `rotating_file`
handler with retention — nothing rotates logs on this box.

### The ledger, as decided

One table, written by one subscriber, covering every job type:

```
job_run
  id
  job_type      'product_import' | 'rim_sync' | ...   (message class, shortened)
  reference     import token / null                    — ties back to ProductImportRunLog
  status        queued | running | succeeded | failed
  queued_at     when dispatched
  started_at    WorkerMessageReceivedEvent
  finished_at   WorkerMessageHandled/FailedEvent
  error         failure message, null on success
  attempt       retry counter
```

```php
// src/EventSubscriber/JobRunSubscriber.php
WorkerMessageReceivedEvent  -> row to running,   stamp started_at
WorkerMessageHandledEvent   -> row to succeeded, stamp finished_at
WorkerMessageFailedEvent    -> row to failed,    stamp finished_at + error
```

Because it hangs off Messenger's own events rather than each importer, a job type added later is
recorded automatically — no per-feature status file to remember.

**Nothing existing is replaced or removed.** `job_run` becomes the fourth system-wide log alongside
the three that already have admin pages, and the boundaries between them are worth keeping sharp:

| Log | Answers | Page |
|---|---|---|
| `AuditLog` | who changed what data | `/admin/audit-log` |
| `ErrorLog` | what broke (exception, trace) | `/admin/error-log` |
| `EmailLog` | what we sent | `/admin/email-log` |
| `JobRun` | **what ran** (queued -> running -> succeeded/failed) | new |

Plus the per-document journals, untouched: `SalesOrderLog`, `EstimateLog`,
`InventoryBucketChangeLog`.

The pair to watch is `JobRun` vs `ErrorLog` — a failed job could plausibly write both. Keep them
distinct: `JobRun` records *that* it failed with a short reason; `ErrorLog` holds the exception and
stack trace. Two half-implementations of failure reporting is the thing to avoid.

`ProductImportRunLog` (JSON in `var/import_runs/`) and `RimSyncStatus` also stay — they hold per-run
DETAIL this table should not carry (row counts, skipped rows, sync payload state). The ledger
answers the coarser question of what ran and when.

### Run history is currently broken for rim

|  | Storage | Keeps history? |
|---|---|---|
| `ProductImportRunLog` | one JSON per token, `var/import_runs/<token>.json` | yes |
| `RimSyncStatus` | **one** JSON file, overwritten every run | **no — last run only** |

`RimSyncStatus` holds `status`/`pid`/`startedAt`/`finishedAt`/`message`/`result` and every sync
clobbers the previous one, so "when did the rim sync last fail?" is unanswerable as soon as a later
run succeeds. That is the gap `job_run` closes — every run, both importers, manual and scheduled
alike, one row each.

`RimSyncStatus` is not deleted: it stays as *current* state for the admin page's live progress. But
it stops being the record of what happened.

**Its `isRunning()` PID check becomes obsolete.** That method reads the stored pid and tests whether
the process is alive — a hand-rolled concurrency guard for the spawn model. Under the queue the
worker serialises everything, so it should be removed rather than left as a second, disagreeing
answer to "is a sync running?".

Surface it read-only under `/admin/system/` beside the existing error log, which is where someone
would already look.

**Growth and retention.** One row per job — a handful of imports a day plus 24 hourly rim ticks
(23 of them no-ops), so roughly 30 rows/day, ~11k/year. Trivial for SQLite.

No-op runs ARE recorded: proof the schedule is alive is exactly what D5's alerting half wants, and
absence of rows becomes the signal that something stopped firing.

**Pruning: runs DAILY, deletes rows older than a configured retention.** Same shape as the rim
sync — a `PruneJobRunsMessage` on the same 10-minute tick with a 23h55m last-run guard — so it is the same
mechanism, not a second one.

Daily rather than yearly on purpose: a yearly prune means one delete of ~11k rows, and if it is ever
missed the next one is a year away with twice the backlog. A daily prune deletes a handful of rows,
is self-correcting after any outage, and its own `job_run` row is a daily heartbeat proving the
scheduler is alive.

Retention is config, **in days**: `job_run_retention_days`, default 365. Days are the unit the
delete actually works in and can be tuned to any value without a deploy.

    DELETE FROM job_run WHERE finished_at < now - retention

Only finished rows: never delete a row still `queued` or `running`, however old, since that is
exactly the wedged-job evidence someone would be hunting for.


### D8 — Worker restart button on the job log page

An operator lever for "the worker is holding stale code" or "the worker is wedged", without SSH.
Lives on the job log / system page, beside the ledger it is meant to fix.

**Graceful, not `kill`.** `messenger:stop-workers` already does the heavy lifting: it sets a flag in
a cache pool, workers check it *between messages* and exit cleanly. A worker mid-import finishes
that import first — no partial write, no orphaned state. Nothing to hand-roll.

    1. bin/console messenger:stop-workers      -> current job completes, worker exits
    2. spawn a fresh detached worker, holding the same flock

Step 2 is only for immediacy: without it the next cron tick starts one anyway, up to 10 minutes
later. It must take the **same lock file** as the cron line, or cron will start a second worker
alongside the one the button launched.

**Force-kill is a separate, second button**, not the default. `kill -9` mid-import leaves SQLite
part-written, and under the queue the message stays claimed (`delivered_at` set) so it is not
retried until the redeliver timeout — an hour by default. That is the stranded-message failure the
deploy notes already describe for mail. Spell the consequence out on the button.

**Access:** `ROLE_TECH_SUPPORT` + CSRF. Deliberately NOT modelled on the database console — that
carries a lot of extra machinery (emailed security alerts, a minted session credential, its own
rate limiters) that none of this needs. Two plain gated POST endpoints, nothing more.

**No user input reaches the command.** The command string is fixed; nothing is interpolated. That
leaves no injection surface, which is what keeps this simple enough to be safe.

**PID handling:** the graceful path needs none — `stop-workers` signals via the cache pool, not a
signal to a process. Only force-kill needs a PID, and `pgrep -f "messenger:consume imports"` is
fragile on shared hosting (can match the wrong process, may be restricted). If force-kill is built,
have the worker write its pid at startup rather than grepping for it.

- **Decision:** graceful restart button, yes. Force-kill as a separate second control.
- **TODO:**

## Risks

**A queued import is invisible until a worker takes it.** `ProductImportRunLog` already models this
correctly (`queue()` in the controller, `start()` in the worker), so a waiting import legitimately
reads `queued`. Worth confirming the admin UI renders that state clearly rather than looking idle.

**Deploys now break two workers, not one.** `cache:clear` deletes container files an open worker is
using; the memory note about running `messenger:stop-workers` immediately after a deploy applies to
the imports worker too, and `stop-workers` only takes effect between messages.

**The queue table is in the database being imported into.** Claim/ack writes to
`messenger_messages` happen while the import writes `product_core` — same SQLite file, same
`busy_timeout`. Tiny against a 2-second import, but it is the one place "the queue removes
contention" is not literally true.

**No rollback story for a bad import.** Unchanged by this work, but worth stating: queueing makes
imports more reliable to *start*, not reversible once they run.

## What this does NOT solve

- Double-submitting the same CSV still queues two jobs that both run, serialised. If that is the
  actual annoyance, the fix is at the upload (reject a duplicate token / disable the button), not
  in the runner.
- Import speed. 2 seconds before, 2 seconds after.
