# Work queue — inventory layer

State as of 2026-08-23. Written so work can continue unattended across sessions.

## TEST ECONOMICS — standing order, 2026-09-12

**This repo is too big to run its suites by default. Whitelist, never blacklist.**

- **Name the test classes you will run, from the work you did.** Not `--filter` with a broad
  alternation and exceptions — that is how one status refactor pulled in 963 tests and the
  walkthrough end-to-end suites for 36 minutes of CPU.
- **Do not run `php vendor/bin/phpunit tests` to confirm a commit.** ~1900 tests for a change that
  touched six files is poor economics, and it is not evidence about those six files.
- **Write adversarial tests surgically against your own change** — the case that would break if
  your change were wrong — rather than sweeping neighbours in the hope something catches.
- **Full suites are for major checkpoints only**, and they are a deliberate decision, not a habit.

The corollary for whoever briefs an agent: if the brief says "keep the suite green", the agent
will run the suite. Name the classes in the brief instead.

## HARD CONSTRAINTS

- ~~**DO NOT TOUCH CORE.**~~ **LIFTED 2026-09-17** by the owner, formally, in conversation. Ruled
  2026-08-23; superseded. Issues below still marked `[CORE]` are no longer blocked on this rule —
  re-read them on their own merits before starting, since some may have been overtaken by other
  work in the meantime.

  **Why it is lifted now:** production is not actively used yet, and the client wants to see these
  features. The review-cost argument below is about spending reviewed work to fix unreviewed work
  on a system people depend on right now — that cost is lower while nobody does, and visible
  feature progress is worth more than it was on 2026-08-23. Re-tighten this once production carries
  real traffic.

  **Why it existed:** core had hundreds of hours of human review. The bundles did not. That
  asymmetry is visible in the defects found on 2026-08-23 — every one was in a bundle, and almost
  all were a bundle skipping a convention core already had:

  ```
  table-header       core 32 uses    bundles  0
  no-paginate        core 40         bundles  0
  data-table etc.    core 76 classed bundles 34 bare
  js- hooks          core 445        bundles  7
  ```

  Core is the reviewed layer. Changing it to suit a bundle spends reviewed work to fix unreviewed
  work — that reasoning stands and should still weigh on any core change, freeze or not; only the
  hard block is gone. When a fix appears to need a core change, check first whether the bundle
  should instead use what core already provides.
- **Use the QA accounts, never the owner's login.** One admin exists per role (see ENVIRONMENT
  above), created with `app:create-admin`. The owner's account is off limits: do not change its
  password, its roles, or anything else on its row. If a screen needs a role you lack, log in as the
  QA account that has it — do not promote yourself or anybody else. Recreate any of them with
  `php bin/console app:create-admin --email=... --password=... --role=... --update`.
- **Conformance tests enumerate; they never hold a hand-kept list.** When a rule applies to a
  CLASS of things — every document, every list screen, every stock operation — the test must
  discover its subjects from the application itself (the route list, the entity map, the container's
  tagged services) and then assert the property. A test naming its subjects in an array passes
  forever while the next thing added quietly skips the rule, which is exactly how the repo arrived
  at: eight documents of which two implement `CommercialDocument`, 34 bare tables against core's 76
  classed ones, `no-paginate` used 40 times in core and 0 in bundles, and an inventory grid whose
  every figure could be wrong behind `see('50')`.

  Open issues implementing this: `#621` (list-screen conventions), `#627` (numeric assertions),
  `#636` (document contract), `#624` (conducted Cests). The sidebar fixture is the ANTI-pattern to
  learn from — it works, but only because someone remembers to refresh it. `#53` took the
  remembering out of the half that actually churns, WITHOUT adding a third copy of the list:
  `TechnicalDocsSidebarFixtureTest` compares `DocsRepository::list()` against the Technical Docs
  rows read back out of the committed fixture's own markup, so neither side of that check is
  written down anywhere. The rest of the fixture is still hand-refreshed.
- **Every stock-moving operation gets a conducted Cest** (`#624`, ruled 2026-08-24). Drive the real
  screens with plain form POSTs, assert `table.column` before and after at the specific row, re-read
  from the database rather than an entity fetched beforehand, create your own data, **and assert the
  row that should NOT have changed.** That last one is the cheap half and would alone have caught
  `#589`, `#591` and `#584`. A returned value, a flash message, an HTTP 200 or a bare row count is
  not proof the data moved. Four integrity bugs this session were green in a 1200-test suite and
  obvious the moment somebody conducted the transaction.
- **The app works without JavaScript.** JS is a veneer for quality of life only. Stated in
  `public/assets/js/app.js`'s opening comment; enforced by `tests/Functional/AdminNoJs*Cest.php`.
  A searchable dropdown keeps its plain fallback; repeatable rows need submit-to-add-row; a camera
  sits beside typed entry.
- **Production is the SALES-ONLY app.** Confirmed 2026-08-24: the vendor side has never been
  deployed anywhere but dev, and neither has Procurement, Warehouse Ops, Inventory Depth or
  Barcodes. So `vendor*`, `purchase_*`, `product_barcode`, `inventory_reorder_rule` and the rest of
  the programme hold no production rows, and a schema change there — including a column RENAME —
  is low risk. `company_*`, `order_*`, `invoice_*`, `quote_*`, `product_*` ARE deployed and get full
  caution. This changes the migration STRATEGY available, never whether data may be overwritten.
- **PRE-APPROVED, do not ask: the column widening in `#645` (UoM phase 2).** The ADD-only rule is
  waived for that phase specifically — quantities to `NUMERIC(14,4)`, rates to `NUMERIC(18,6)`,
  including on `sales_order_line`, `invoice_line` and `cart_item` which are in the deployed sales
  app. Granted by the owner 2026-09-08. The waiver covers the TYPE CHANGE only: no value may change,
  proved by row counts and content checksums compared by column ORDER, not by name.
- **Never write to existing data.** No backfills or repair UPDATEs in any environment. Migrations
  add columns/tables only. Put corrective SQL in the report, unrun.
- **Production SQLite is 3.26.** Local is 3.45, so tests pass on syntax the servers reject. No
  `DROP COLUMN` or `RETURNING` (3.35), generated columns (3.31) or `IIF()` (3.32) in migrations.
  Drop a column by rebuilding the table with `App\Doctrine\SqliteTableRebuild` — see
  `Version20260820160000`.
- **A migration must NEVER touch the `$schema` it is handed.** Not `$schema->hasTable(...)`, not
  `$schema->getTable(...)->hasColumn(...)`, in `up()`, `down()` or any `pre*`/`post*` hook. Reading
  it introspects the whole database, and since `Version20260821160000` this schema carries
  `uniq_inventory_detail` — a unique index over `COALESCE(...)` expressions. SQLite reports an
  expression column's name as NULL, DBAL's `Index::_addColumn()` is typed `string`, and the
  migration dies with **`Argument #1 ($column) must be of type string, null given`** naming a DBAL
  internal and an inventory index, whatever it was actually doing. The index is CORRECT and is not
  to be changed: it is what makes the inventory uniqueness rule enforceable.

  Ask the database instead — `use App\Doctrine\SqliteMigrationIntrospection;` gives you
  `tableExists()`, `columnExists()` and `indexExists()` over `sqlite_master` and `PRAGMA`. Keep
  `$schema` in the signature (Doctrine requires the parameter), just never read it.
  `Version20260917090000` is the worked example.

  **19 older migrations do use `$schema` and are fine** — they run before the index exists. They are
  also the ones people copy. `bin/ci-migration-replay` refuses anything after
  `Version20260821160000` that touches it, before it replays a thing, so a copy is caught in
  milliseconds rather than ninety seconds later as a TypeError.

  Same cause, outside migrations: `doctrine:schema:update` and `doctrine:schema:validate` **without**
  `--skip-sync` both die on any migrated database. The replay passes `--skip-sync` for this reason,
  not only for the index-name drift its older comment blamed.
- **The eight inventory tables keep their shape.** Three merges considered and declined.
- **All coding goes to worktree subagents.** Suites run SEQUENTIALLY per worktree
  (`database is locked` otherwise); cross-worktree is safe. Never edit source while a suite runs.
- **Targeted runs go direct. FULL suites go through `bin/test-slot`.**

  ```
  vendor/bin/codecept run Functional SomeCest          # targeted — direct, no slot
  vendor/bin/phpunit --filter SomeTest                 # targeted — direct, no slot
  bin/test-slot vendor/bin/codecept run                # full suite — always slotted
  bin/test-slot vendor/bin/phpunit                     # full suite — always slotted
  ```

  **What `bin/test-slot` IS.** A memory gate, and nothing else. It is a ~40-line bash wrapper: it
  reads `MemTotal` from `/proc/meminfo`, works out how many whole-suite runs the box can hold at
  once, takes an exclusive `flock` on one of that many lock files in `$TMPDIR`, and only then
  `exec`s the command you gave it — holding the slot for the life of that command and releasing it
  on exit. If every slot is taken it prints `test-slot: all N slots busy (…MB box), waiting...`
  and polls until one frees. It does not change how your tests run, choose them, or report them.
  Its whole job is to stop several agents' full suites peaking at the same moment.

  **When you need it: only for a FULL suite run.** That is the case it exists for — a full
  Codeception run is ~59 minutes and peaks at 3.74 GB in ONE PHP process (1705 tests, 19723
  assertions, 2026-09-14). Several of those overlapping gets one OOM-killed, and a killed suite
  does not fail cleanly: it surfaces as bogus "undefined method" errors that read exactly like
  real defects. A targeted run naming a specific Cest or `--filter`ing a specific test is seconds
  and a few hundred MB; it has nothing to queue behind, and slotting it only makes agents wait on
  each other for no reason.

  **Slot count is derived, not configured** — no edit is needed when the box changes. The formula
  in the script is `(MemTotal − RESERVE_MB) / SUITE_PEAK_MB`, minimum 1, with `RESERVE_MB=2500`
  (OS, Caddy, PHP-FPM, and the agents themselves at 300-400 MB each, which the gate cannot see
  because it only sees processes that call it) and `SUITE_PEAK_MB=4400` (the 3.74 GB observation
  plus headroom, since the peak grows with the test count). On this box that is
  **(16075 − 2500) / 4400 = 3 slots**, confirmed against `/proc/meminfo` on 2026-09-12. Read the
  two constants out of `bin/test-slot` rather than quoting a number from here — this line has been
  stale before: it said "1 slot on the current 3.9 GB box, 2 after the pending 10 GB upgrade"
  long after the box became a 16 GB one, and agents sized their plans around it.

  Cross-worktree runs are safe on DATA (`DATABASE_URL` uses `%kernel.project_dir%`, so each
  worktree has its own `var/data_test.db`) but not on memory, which is the whole reason for the
  gate. Never run two suites in the SAME worktree (`database is locked`).

  **The default is still a targeted run.** An hour of wall clock rarely tells you anything a run
  of the Cests covering your change did not. Run the Cests you wrote plus the ones covering what
  you touched, derived from the call sites rather than from a category sweep, and say WHY each was
  in scope. If you believe a change could break something outside that list, name the area rather
  than running everything to find out.
- **Agent count vs suite count are different limits.** Agents are cheap; suites are not. On the
  16 GB box: **5 concurrent agents, 3 concurrent suites**, the latter enforced by `bin/test-slot`
  and derived from the formula above rather than set anywhere. Agents spend most of their time
  writing, so more agents than slots is the intended ratio — the gate exists so that overlap costs
  waiting instead of corruption.

## ENVIRONMENT

**Disk is the quiet constraint.** `/` is 40 GB and was at 94% on 2026-08-23, which made SQLite
throw `disk I/O error` mid-schema-build — a failure that looks nothing like "out of space". The
cause was 3.5 GB of `var/data_dev.db.bak-*` pre-migration copies from earlier sessions. They are
now gzipped (10:1 — they were mostly empty pages from E2E residue), taking `/` to 85%. Restore any
with `gunzip`. **Check `df -h /` alongside `free -m` before a suite run**, and gzip or delete a
backup as soon as the migration it guarded is confirmed.

**Agent worktrees accumulate.** `.claude/worktrees/` held 22 finished ones (~3.4 GB) by the end of
2026-08-23. Prune them once their branch is merged — the branch ref survives, so nothing is lost:

```
git worktree list                       # check for `locked` = an agent is still in there
git -C <worktree> status --porcelain    # and that it is clean
git worktree remove --force <worktree>
git worktree prune
```

Never remove one that is `locked` or dirty. A worktree's `vendor/` should be a hardlink copy
(`cp -al`) of the main checkout's, never a symlink — a symlink makes Composer resolve `$baseDir` to
the main checkout, so the agent silently tests main's code and writes main's `var/data_test.db`.

**Adding any `docs/**.md` file changes the admin sidebar** — Technical Docs lists the directory
from the filesystem — and breaks `AdminMenuDefaultSidebarCest`'s snapshot. TWO remedies, both with
precedent, and the second one is the one this page kept forgetting to mention:

- refresh `tests/Support/Fixtures/admin_default_sidebar_nav.html` in the same commit — `77c15a4b`,
  `89ceba61`; or
- **keep the file outside `docs/`** if it is working notes rather than a technical document —
  `7ee3ef8b` moved `DECISIONS-NEEDED.md` to the repository root for exactly this reason.

Never add a docs file while a suite is running. Since `#53` you will be told which it is rather
than having to find it: `TechnicalDocsSidebarFixtureTest` fails in ~90 ms with
`docs/X.md exists but the fixture has no row for it`, and the Cest names the differing row and this
coupling instead of printing a 28 KB string diff.

**What that fixture check actually guarantees — it is NOT a byte comparison.** This page and
several briefs have told agents to prove a fixture edit "byte for byte" against
`AdminMenuDefaultSidebarCest`. That test does not require it and never has:
`AdminMenuDefaultSidebarCest::normalize()` DELETES every run of whitespace between tags from BOTH
the fixture and the rendered page before comparing them. So it guarantees **structure and text** —
the same elements in the same order, with the same attributes in the same order and the same
values, and the same text in each — and it is **insensitive to inter-tag whitespace**. It reads as
a byte comparison today only because the committed fixture happens to be a single 27,847-byte line
with zero inter-tag whitespace, which makes `normalize()` a no-op on it; regenerate or hand-edit
that file with newlines between tags and a whitespace-only change would start passing. The
normalisation is deliberate and stays (matching the old hand-typed template's mixed tabs/spaces
was the thing the refactor existed to stop). Ask for a refreshed fixture that renders the same
DOM — do not ask anyone to prove bytes that nothing checks.

```
admin      http://admin.wholesale-b2b-core.localhost      (http, NOT https; NOT admin.localhost)
storefront http://wholesale-b2b-core.localhost
vhost      /etc/caddy/conf.d/wholesale-b2b-core.caddy
db         var/data_dev.db  — must be chmod 666, PHP-FPM runs as www-data
seed       app:seed-demo-data  then  app:seed-warehouse-data
qa logins  qa-super-admin@example.test · qa-tech-support@example.test
           qa-admin@example.test · qa-plant-staff@example.test
           all: qa-local-only-2026   (LOCAL DEV ONLY, never an env with real data)
           (--force on the first purges the second; re-run both)
```

## MERGED — main d39517ff

`#581` write_off bucket + status allocation · `#582` buckets log themselves ·
`#584` cumulative transfer pair · `#585` reason-first adjustment screen ·
`#586` credit memos · `#589` short-pick scoped to the picker's bin · demo seeders ·
`#596` sales returns (RMA) ·
`#590` P1 lot edit reaches the lot it edits, bin close refuses stranded stock ·
`#618` `#619` `#620` the UI audit trio ·
`#583` incoming_quantity writer · `#613` row actions and refusing deletes ·
`#597` reorder points + low-stock screen · `#587` transfer drift check + discrepancies screen ·
`#590` P2 the transfer line table is the entry form ·
`#591` `#592` pick recorded against the picker's bin, one bin per claim ·
`#605` `#606` vendor master data mirrors the customer side ·
`#594` 28 tests now assert the row · `#614` the create bar (the ONE core edit) ·
`#607` `#608` `#609` barcodes, in a new BarcodeBundle

PHPUnit 2100 / Codeception 1317, both green (2026-08-24, on the tip — the tree WITH upstream
`#623` merged in). Migrations applied.

**Upstream is moving too.** PR `#623` (e2e test instances) merged on origin while this work was in
flight and touches core: `.env`, `config/services*.yaml`, `src/Doctrine/TestInstanceConnectionFactory.php`
and three commands. It gives each e2e agent its own SQLite database, selected by request header.
Merged in cleanly — no overlap with `modules/`. Two consequences: **fetch before assuming main is
yours**, and someone other than this session is landing core changes, so the `[CORE]`-blocked items
below may have an owner who is already in there.

## IN FLIGHT — started 2026-08-23, two worktree agents

- nothing — the queue is empty. What remains is BLOCKED, below, or awaiting the owner.

All agents are briefed with: do not touch core (incl. `app.css` / `app.js`), no writes to existing
data, no-JS baseline, the list-screen conventions from the UI audit, and `bin/test-slot` for every
suite run. None of them push; merges come back through the main session.

## UI AUDIT — done and merged 2026-08-23, all 36 bundle screens

`4 ok · 6 broken · 26 inconsistent`, grouped into ten causes and filed as three issues.
All three merged at `060c2d15`. What they changed, for reference:

- `#618` scroll regions used outside `table-card` — **fixes all 6 broken screens**, 13 total.
  Same root as `#612`; do it FIRST or the trio work is decorative.
- `#619` one shared list-screen skeleton across 22 list screens — lands `#612`, `#616`, and the
  three filter forms that cannot be submitted without JS. Copy
  `templates/admin/company_fulfillment_region/index.html.twig` verbatim.
- `#620` row-level vocabulary sweep — empty states (17 screens), 6 orphaned sort fields, row
  actions, 4 hints inside labels, 3 filters read with no input. All one-line edits.

The 4 screens that are fine: `/inventory-depth`, `/warehouse-ops`, `/procurement` landings, and
`/procurement/purchase-orders/{id}/print` — which correctly uses the `is_pdf ? pdf_layout : layout`
switch, and is the model for `pick_list_print.html.twig` which does not.

## QUEUE, in order

Set 2026-09-08.

1. `#643` UoM phase 1 — the unit model. **In flight.**
2. `#627` ban `see()` on a number — assert the cell, not the page. Tests only.
3. `#621` assert the list-screen conventions structurally. Tests only.
4. `#645` UoM phase 2 — widen the quantity and money columns. ADD-only waiver PRE-APPROVED.

`#627` and `#621` sit before `#645` deliberately: phase 2's entire claim is that every existing
number reads back identically, and that claim is only as good as the assertions making it.
`see('50')` matching `'1050'` is a numeric-assertion bug, and phase 2 is nothing but numbers.

### Queued 2026-09-12 — slugify the status vocabulary keys

Owner: *"i thought slug is on-hold not On Hold lol are we not slugifying it?"* and
*"i thought centralizing it meant we change it in 1 place."*

**We are not slugifying, and the code calls them slugs anyway.** `StatusVocab` says `slug` 34
times — `slugs()`, `labelFor(string $slug)`, *"a status with an empty slug"* — while every key in
`CoreStatusVocabularyProvider` is byte-identical to its own label: `'On Hold' => 'On Hold'`,
`'Partially Invoiced' => ['label' => 'Partially Invoiced', ...]`. The name and the thing disagree,
which is a fair reason to expect `on-hold` and find `On Hold`.

**Why it is not already one place.** Centralizing gave us one place for which statuses exist, what
they are labelled on screen (`labelFor()`), who may write them (the gate), and a typo guard. It did
NOT centralize the stored value, because we chose the human string AS the key, and the key IS the
column value. There is no indirection between what the code types and what the database holds, so
`isStatus('On Hold')` hardcodes the stored value at every call site.

**Cost — it is a string-to-string change, end to end.** Nothing changes type, nothing changes
shape, no logic moves. The same value sits in the same column and is compared the same way; it is
spelled differently. A regex over 25 known literals plus a data migration does almost all of it, and
the typo guard catches whatever the regex misses: a stale literal throws `LogicException` naming the
vocabulary and its contents, at the call site, rather than silently taking the wrong branch.

The bulk — 127 comparisons in `src/`/`templates/`, ~1,420 literals in `tests/` — is sed.

Only three things need a human to look at them, and they are still string-to-string:

- **Flash messages that echo the raw value.** *"Invoice X is now Pending"* would start saying *"is
  now pending"*. Swap to `statusLabel()`, which is the right call there regardless.
- **The data migration.** One `UPDATE ... SET status = ... WHERE status = ...` per value per table.
- **`?status=On+Hold` in grid filter URLs.** Bookmarked and emailed links stop matching.

Everything else follows the regex. Templates already slugify at the point of use —
`invoice.status|lower|replace({' ': '-'})` for a CSS class — which is the argument for storing the
slug: the code already wants one.

**Only two keys have a space at all**: `On Hold` and `Partially Invoiced`. The other 23 are single
words, so this is mostly a lowercasing. Worth deciding once, deliberately, rather than tacking onto
whatever touches status next.

Then `#646` phase 3 (blocked on `#637`/`#638` landing — they add line tables and phase 3 changes
every line table) and `#644` phase 4.


**Empty.** `#602` and `#603` were the last entries; both were read on 2026-08-24, both turned out to
be core-only, and the owner chose to keep the freeze. They are in the blocked list now. `CreditMemo` is
   `src/Entity/CreditMemo.php`, so a follow-up touching the document itself is blocked; one touching
   only the bundle's restock side is not.

That is the end of the queue as it stood. What remains after it is the blocked list below, which
is now the larger pile — six items, all waiting on the core freeze.

## BLOCKED — needs core

- ~~`#614` inline create bar~~ **DONE 2026-08-24** — the one core edit authorised under the freeze,
  because it is a single additive rule. `.inline-form-grid` joins `.address-form-grid` and
  `.product-form-grid` as a third exemption from the one-field-per-row admin override. Applied to
  `/admin/bundles/warehouse-ops/transfers` only so far; #614 says it applies app-wide, so rolling it
  across other create bars is a follow-up. **Verified structurally, NOT visually** — no browser tool
  was available in the session that wrote it.
- ~~`[CORE]` `#615` document prefix collector~~ **DONE** — `app.document_prefix_provider`, collected
  by `App\Service\Document\DocumentPrefixCatalogue` exactly as the shipped email templates are.
  `/admin/settings/document-prefixes` renders whatever registered; core declares its five through
  `CoreDocumentPrefixProvider` rather than staying special, and ProcurementBundle's three moved
  there off its own settings screen. `credit_memo_number_prefix` and `sales_return_number_prefix`
  are settable for the first time. The screen now leaves an unsubmitted field alone instead of
  reading it as empty — that is what made a fourth field possible, and it is why #586 and #596 both
  recorded that they could not expose theirs.
- `[CORE]` `#595` inventory grid region selector
- `[CORE]` `#602` an invoice can be cancelled after a credit note is applied to it. `Invoice::cancel()`
  refuses when payments exist and not when credit applications do, so the credit evaporates. Nothing
  at risk today — `credit_memo_application` has 0 rows. Fix is option (1): refuse, matching what the
  same method already does for payments.
- `[CORE]` `#603` invoice balance and payment status ignore applied credit notes. **The one with a
  visible cost while it waits:** an invoice credited in full reads Not Paid with the full amount
  owing, and the credit note already says the money was spent, so the two documents disagree about
  the same row. `Invoice::getBalance()` and `src/Enum/InvoicePaymentStatus.php`. Do it early once the
  freeze lifts — every credit note issued meanwhile widens the disagreement.
- `[CORE]` `#601` units of measure — `product_core.unit` is a `VARCHAR(80)` free-text field on
  `src/Entity/ProductCore.php`. Two shapes: a bundle-side table keyed to `product_core.id` (the
  `#597` trick, buildable now) or `product_core.unit` becoming a real foreign key with every
  inventory quantity gaining a unit beside it (blocked). **Recommended: the blocked one.** The
  bundle-side version buys a dropdown and a conversion nothing applies, and the moment someone
  receives in cases and picks in units the two halves disagree with no way to say which is right —
  the same two-places-hold-one-fact shape behind `#589`, `#590` and `#591`. Reasoning is on the issue.
- `[CORE]` vendor custom fields — the object-type registry is three PRIVATE constants in `src/`
  (`CustomFieldDefinition::OBJECT_TYPE_*`, `CustomFieldValueRepository::MAP`,
  `CustomFieldController::OBJECT_TYPES`) with no extension point, so a bundle cannot add
  `custom_field_value_vendor`. Found while building `#605`.
- `[CORE]` `#593` shipments — checked 2026-08-23 and parked. The #586/#596 seam needs core to
  dispatch, and core has no shipment action to dispatch FROM: nothing on an order or invoice says
  the goods left. Core's only events are `CartSyncedEvent`, `CreditMemoIssuedEvent`,
  `InventoryBucketsReconciledEvent`, `SalesReturnReceivedEvent`. So `sold` has no producer and the
  staging bin never drains — known and accepted, not a defect to chase.
- `#617` no-JS audit — the AUDIT is read-only and fine; any fix touching core is blocked

## ASPIRATIONAL — do not start

`#598` kits · `#599` reporting · `#600` valuation/COGS · `#604` variants ·
`#625` reordering policy (maximum inventory, order increment)

`#625` was filed and parked the same day, 2026-08-24. `#597` as shipped is already at **Zoho
parity**: reorder point per (product, warehouse), a low-stock screen, and a "Raise a purchase
order" link. Zoho has no maximum-inventory field and computes no order quantity — the PO opens
with a blank quantity for a human. Everything past that (Maximum Qty., Order Multiple, Minimum
Order Quantity) is Dynamics' levers tier, which the owner already ruled is a separate bundle.
Note Order Multiple collides with `#601`: rounding to a vendor's case size IS a unit-of-measure
conversion, so build them together or build case-rounding twice.

Reorder trigger is `<=`, ruled and closed — matches Dynamics, NetSuite, Zoho, Fishbowl and SAP MRP.
No per-rule operator setting: level 10 with `<=` is the same rule as level 9 with `<`, so the
choice is redundant with the number the user already types.

They sell components, not packages.

**What "accounting is parked" actually covers, because it was being over-applied:** general ledger,
COGS, inventory valuation and landed cost. Nothing else. The sell side has `invoice_payment`,
`fee_lines`, `tax_lines`, derived balances and a payment status, and none of it needed an accounting
module — so recording money on a document, itemising it, and deriving a balance from it are IN scope
on both sides. Using the parking decision to defer purchase-side payments and tax breakdown was
wrong and was withdrawn on 2026-09-09; see `#655`.

## FROM THE UI AUDIT, STILL OPEN

- `#621` assert the list conventions — 35 templates changed with no test. Nothing checks that a
  `.table-card` on a list screen carries `no-paginate`, that a scroll region appears only on a list
  screen inside the trio, or that filter/header/body cell counts and the empty state's `colspan`
  agree. Drive it from the route list, not a fixture, so new screens are covered on arrival.
- `#622` two controllers accept `sort`/`dir` and ignore them — `/warehouse-ops/transfers`
  hard-codes ordering by id; the tracking worklist sorts by a column it never renders. Left
  deliberately: a header link for a sort that does nothing is worse than silence.
- `data-table` was NOT applied to the 34 bare tables. In core that class only adds a 260px ellipsis
  truncation to first-column links, which would clip vendor names on `/procurement/vendors`.
  Everything else it gives already comes from `.table-card table`.
- Core uses `.table-scroll-region` on list screens ONLY — zero detail or document pages. That is
  the convention; the audit's deviation from the issue text followed it.

## FROM #596, STILL OPEN

- `returned` is now in `documentBackedStatuses()`, so the adjustment screen refuses it on BOTH sides.
  No shipped reason ever named it, so nothing broke — but a declined RMA's units can be *seen*
  (RMA detail warns, links to the stock screen) and not *acted on*. Disposition belongs on the RMA;
  it was deliberately not built.
- ~~`sales_return_number_prefix` joins `credit_memo_number_prefix` in being set by code default
  only.~~ **DONE with `#615`** — both are on Settings → Document Prefixes.
- `js-memo-search` on the credit-memo grid has no handler anywhere — inert decoration from #586.

## THINGS FOUND THAT NOBODY WAS LOOKING FOR

- **`see('50')` is a substring match over the whole page.** `AdminInventoryCest` asserted the
  inventory grid that way, so with `sales_hold_quantity` rendered in the Hold column,
  `hold_quantity` in Sales Hold, and 1000 added to both the editable quantity and Available, all
  three tests stayed green — `'1050'` contains `'50'`. Every figure on `/admin/inventory` could have
  been wrong, including the transfer bucket reading 50 at a warehouse holding 40. Filed as `#627`.
- **Two security tests were not testing security.** `AdminBundleManagementCest`'s plain-admin check
  and the fulfillment-region unknown-row check both posted a FORGED CSRF token, so the 403 came from
  `CsrfProtectionSubscriber` and the controller body never ran. Deleting the `ROLE_TECH_SUPPORT`
  check left the first one green. Fixed in `#594`.
- **The detached-entity pattern `#594` was named for is NOT endemic here** — two sites, one real
  no-op. Settled by probe. So it was never why the real defects survived; `see()` was.
- **The barcode screens were never in the sidebar.** `WarehouseOpsMenuItem` contributed exactly one
  line — the bundle hub — a deliberate #552 decision, so no string in the admin nav contained
  "barcode", "label" or "scan". Fixed in `#607`.

- **`inventory_reconciliation_discrepancy` had four writers and zero readers** until `#587`.
  Core's `app:inventory-recalc`, cart hold's check, the product import's approved recalc and
  Inventory Depth's detail check have all been recording findings with no route, no template and no
  repository to read them back — the admin email each row triggers was the only way anyone learned
  one existed. `/admin/bundles/warehouse-ops/discrepancies` now reads ALL sources, not just
  transfers, or the other four stay unread.
- **CORRECTED 2026-08-24: "`sold` has no writer, so staging never drains" is NOT a defect.**
  This entry previously said it was. The owner: *"Sold is cleared when admin syncs inventory with
  his 3rd party source"*, and *"for some systems we are not the source of truth. Sometimes we are.
  This setup accommodates both."* `ProductImportService` takes `sync_source` plus
  `clear_approved_balance` / `clear_received_balance` as OPT-IN booleans, so the mode is chosen per
  import: a synced instance has its count rebaselined from outside (`rebaselineCoreRow()` for the
  cache, `DimensionalImportProvider` for `inventory_detail`), and needs no dispatch document for
  stock to be right. Only a source-of-truth instance feels the gap. `#593` matters for those, and
  is not blocking in general. **Never assume this app owns the stock count.**
- **`js-memo-search` on the credit-memo grid has no handler anywhere** — inert decoration from #586.

## DECISIONS TAKEN UNATTENDED — flag if you disagree

Made overnight 2026-08-23 while the owner was asleep, under "if something comes back genuinely
broken, you fix it". Each is defensible but none was signed off:

- **`#597` fires at or BELOW the reorder level**, not strictly below as the issue's pseudocode said.
  Under `<`, a level of 0 fires only once the row is already oversold, contradicting the same
  issue's "0 means order when you hit nothing left".
- **`#597`'s level lives in a new `inventory_reorder_rule` table**, not a column on
  `product_inventory`, because that entity is core. Same grain, one row per (product, warehouse).
  No row = unmanaged, so `reorder_point` is NOT NULL and 0 is a real level.
- **With ProcurementBundle off, `#597` reads `incoming_quantity` as 0** and says "not counted" on
  screen. The column keeps its value. Trusting an unmaintained forecast would hide a shortage
  behind stock that cannot arrive.
- **A transfer line quantity larger than the source holds is STATED, not refused** (`#590` P2) —
  the row prints "N available at <warehouse>" in red and the save goes through, on the reasoning
  that a draft is a plan and `dispatch()` is the enforcement point. One line to change if you
  disagree.
- **`#618` removed ALL scroll regions from multi-section pages**, not one each as the issue said —
  keeping one still clamps the page to 100vh while neighbouring sections cannot shrink. Core uses
  them on list screens only.
- **`docs/QUEUE.md` is listed in the admin sidebar** and its fixture was refreshed rather than the
  file moved out of `docs/`.

## RULINGS

- `sold` = shipped. No dispatch event today; a dispatch layer writes it later (#593).
- `sold` and `staged` get no bucket — the invoice hold already covers them. `staged` deleted.
- `returned` maps to `quarantine_quantity`, kept as its own status.
- Transfers are a **rare** workflow here; the line-entry component they share is not.
- `/admin/inventory` keeps one row per product; a region filter is the fix for width, never
  row-splitting.
