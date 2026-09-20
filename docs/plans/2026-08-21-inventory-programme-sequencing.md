# Sequencing: the inventory programme (#546, #548, #550, #552, #555)

Status: **execution tracker.** The design lives in the five plan docs this references; nothing is
re-specified here. This exists because the five are a dependency graph rather than a list, and the
graph is not obvious from any one of them.

## The graph

```
#546 warehouse / fulfillment region split ──┬── #548 back orders
                                            │
                                            └── #550 inventory depth ──┬── #552 warehouse operations
                                                                       │
                                                                       └── #555 procurement (also needs #539)
```

| Plan | Issue | Packaging | Depends on |
|---|---|---|---|
| `2026-08-21-warehouse-fulfillment-region-split.md` | #546 | core | — |
| `2026-08-21-back-orders.md` | #548 | core | #546 |
| `2026-08-21-inventory-depth-lot-serial-expiry.md` | #550 | **bundle** | #546 |
| `2026-08-21-warehouse-operations.md` | #552 | **bundle** | #550 |
| `2026-08-21-procurement-vendor-po-bill-receiving.md` | #555 | **bundle** | #550, #539 |

#539 (invoice as a separate entity) is **already merged** — all six stages landed on `main`, so
#555's second prerequisite is satisfied.

## Why the order is what it is

**#546 goes first and alone** because it renames the entity everything else keys on.
`ProductInventory`, both reservation ledgers and every bucket column are keyed on what is currently
`FulfillmentRegion` and becomes `Warehouse`. Building anything against the pre-rename names means
touching the same files twice — #548's own plan says so in its Prerequisite section, and #550
repeats it.

**#548 and #550 are independent of each other.** Backorders extend the bucket machinery; inventory
depth adds a layer beneath the number without changing what the number means. Neither reads the
other's tables, so they run in parallel once #546 has merged.

**#552 and #555 both sit on #550**, because both need somewhere to record *where* stock physically
is — a pick round walks bins, and receiving captures lot and serial. They do not read each other,
so they also run in parallel.

## What "bundle" means for #550, #552 and #555

Each of those three plans has a "This can be a bundle" section, and the shared rule in all of them
is **data outlives the bundle**: removing it loses the breakdown, not the number. A product at 47
across three bins is still at 47 with the bundle gone. Follow each plan's own section rather than
inventing a packaging convention — `modules/` already holds a dozen worked examples.

## Standing constraints for every stage

These are not in the individual plans and have each cost real time on this codebase already.

- **The migration chain is shared.** Check the highest `migrations/Version*.php` before choosing a
  timestamp. Two parallel stages picking the same number is a merge conflict that only shows up
  when they meet — it happened between #539's stages 3 and 4.
- **`tests/Support/Fixtures/admin_default_sidebar_nav.html` snapshots the whole rendered sidebar,
  including the `docs/` tree.** Any new document, and any nav change, turns all three assertions of
  `AdminMenuDefaultSidebarCest` red. This has broken `main` twice in one day. The docs listing is
  the one part of the sidebar that moves without anyone touching menu code, and is probably worth
  excluding from the snapshot.
- **Run PHPUnit and Codeception one at a time.** The box has under 4 GB of RAM against a 3 GB
  Codeception memory limit; two suites thrash swap, and a concurrent run corrupts `var/cache/test`
  and locks `var/data_test.db`. "Database is locked" means contention, not a failure.
- **Copy `vendor/` into a worktree, never symlink it.** Composer resolves `$baseDir` through the
  real path, so a symlinked vendor loads the *main* checkout's `src/` — which surfaces as hundreds
  of "class not found" errors that look like broken code and are not.
- **Commit as work completes, not at the end.** Sessions restart; uncommitted work is at risk.
- **Migrations that write existing data are authorised only where a plan says so.** No production
  instance holds order data as of 2026-08-21, which is what makes that cheap — a fact with a shelf
  life. Re-confirm before assuming it still holds.

## Status as of 2026-08-21

All five are **merged and closed**: #546, #548, #550, #552, #555 — plus #539, which #555 depended on.

### Follow-on work, designed but not built

These finish the scope of what is already merged. They are not aspirational — #564 is a defect in
shipped code.

| | What | Depends on |
|---|---|---|
| **#564** | `incoming` / `received` / `quarantine` buckets, and `syncCoreTotal()` stops writing `quantity`. **Receiving currently writes a number the client's external system owns**, so the next import either erases those units or double-counts them. | — |
| **#565** | Importing a dimensional product: advanced bin/lot/serial/expiry columns, sentinels for unknowns, and the reconciliation rules including the negative sentinel row. | #564 |
| **#562** | Prove the app behaves identically with the three optional bundles **off**. Nothing does today: bundles default Active, so the whole suite only ever exercises them on. | — |
| **#563** | Bundles cannot contribute to the shipped email catalogue, so #555's purchase-order email is invisible in Settings until an admin creates the row. | — |

Also still open, and the one with the most design weight behind it: **whether fulfilment should ever
decrement `quantity`.** #552 built a ship action and removed it; the incoming/received doc explains
why that was right — the number is not ours to write. No issue yet, because the answer may simply be
"never", in which case there is nothing to build.

## Parallelism

**Two agents at a time is the hard limit** — under 4 GB of RAM against a 3 GB Codeception limit, so
two full suites at once thrash swap and get killed. One suite at a time across the whole machine;
check `pgrep -f codecept` before starting one, because memory looks fine until the second run ramps. The graph allows exactly that at each level: #548
with #550, then #552 with #555. Each stage branches from `main` *after* its prerequisite has
merged, not from its prerequisite's branch — stacked branches were what forced the manual
integration merges in #539.
