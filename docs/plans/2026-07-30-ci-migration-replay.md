# Plan: a CI job that replays the migration chain from empty

**Status:** implemented as `bin/ci-migration-replay`, run locally. The GitHub Actions job that used
to run it (`.github/workflows/ci.yml`) was removed — it re-did work the dev box already does, and it
stalled often enough to waste runner minutes. Run `php bin/ci-migration-replay` yourself before
pushing migration changes.
**Independent** — does not depend on any open PR
**Suggested branch:** `ci/migration-replay`

---

## The problem

Neither test suite runs migrations.

- PHPUnit: `tests/DoctrineIntegrationTestCase.php` builds the schema with Doctrine's `SchemaTool`
  straight from the entity mappings.
- Codeception: `tests/_bootstrap.php` shells out to `doctrine:schema:create` — which, as its own
  comment notes, "does not run migrations".

Both are the right choice for test speed. The consequence is that **the migration chain is never
executed by anything except a developer's own database**, and a developer's database already has the
old migrations applied, so only the newest one ever gets exercised.

That is not hypothetical. While building PR #54 the chain turned out to be unable to build from empty
at all: **three migrations were broken** and had to be repaired before the region work could be
verified. All 566 PHPUnit and 325 Codeception tests were green the entire time the chain was broken,
because the suites never touched it. A fresh deploy — or any new developer's first `migrate` — would
have failed.

There are now 83 migrations. Nothing guards them.

---

## What the job must actually check

The critical part: **do not trust "N migrations executed"**. Two failure modes pass that check.

1. **Silent data loss.** `Version20260730150000` dropped five columns from `admin_order`, which under
   SQLite means Doctrine's ALTER TABLE emulation rebuilds the table via a temp copy. The first version
   of that migration reported complete success while `DROP TABLE` cascaded and **deleted every address
   snapshot it had just backfilled** — because `PRAGMA foreign_keys = OFF` is silently ignored inside a
   transaction. The fix was `isTransactional(): false`. Exit code 0 throughout.
2. **Schema drift from the mappings.** A migration can succeed and still leave a schema the entities
   disagree with.

**Measured on `origin/main` (2026-07-30):** replaying the chain from empty succeeds, then
`doctrine:schema:validate` **fails** — 26 tables differ from their mappings (index names, FK
`ON UPDATE` clauses). None of it is a missing or extra column; it is naming drift accumulated across
83 migrations, most of it from hand-written DDL that named constraints differently from what Doctrine
generates. So step 2 cannot simply be switched on: either land a cleanup migration first, or start the
job with `--skip-sync` (mapping validation only, which **does** pass) and add the database comparison
once the drift is cleared. Say which was chosen in the job, so a green build is not read as more than
it is.

So the job needs three assertions, in order:

```
1. migrate from empty          → doctrine:migrations:migrate --no-interaction   (exit 0)
2. schema matches the entities → doctrine:schema:validate                       (exit 0)
3. seeded rows survived        → a command/script that counts them              (exit 0)
```

Step 3 is the one that catches the interesting bugs, and it needs data present *before* the destructive
migrations run — which means the seed has to be injected mid-chain, not at the end.

### Shape for step 3

Two workable options:

- **Split the migrate call.** `migrate Version20260730135959` → seed → `migrate latest` → assert counts.
  Precise, but hard-codes a version number that goes stale.
- **A dedicated fixture step (recommended).** Run the full chain against a database pre-seeded with a
  small SQL fixture inserted right after the schema-creating migrations, then assert row counts for
  `admin_order`, `admin_order_address`, `estimate`, `estimate_address`, `geo_country`, `geo_province`.
  Add a `app:ci-assert-migration-integrity` console command (test env only) so the assertions live in
  PHP and are readable, rather than as a wall of shell.

Either way the failure message must say *what* was lost, not just that a count differed.

---

## Job outline

`.github/workflows/ci.yml`, PHP 8.2 (`composer.json` requires `>=8.2`; development is on 8.4 — matrix
both if cheap):

```yaml
jobs:
  tests:            # phpunit + codecept, the fast feedback that already exists locally
  migration-replay: # this plan
```

Steps for `migration-replay`:

1. `actions/checkout`, `shivammathur/setup-php`, `composer install --no-interaction`
2. Delete any committed database and start from nothing — note PR #45 untracked
   `var/data.db`, so a fresh checkout has no database, which is exactly the state to test.
3. `doctrine:database:create`
4. `doctrine:migrations:migrate --no-interaction --allow-no-migration`
5. Seed fixture
6. `doctrine:schema:validate`
7. Integrity assertions (step 3 above)
8. Upload the resulting SQLite file as an artifact on failure — makes a red build debuggable without
   reproducing locally.

## Also worth adding while touching CI

Cheap, and each one has already cost time in this repo:

- `php -l` / `composer validate`
- `doctrine:schema:validate --skip-sync` on the mappings alone
- The existing suites, with the memory setting from
  `config/packages/test/doctrine.yaml` (`profiling_collect_backtrace: false`) — without it the suite
  peaked at 1.99 GB and 3m03s; with it, 446 MB and 39s. A CI runner will OOM without it.

---

## Verification

- Land the job on a branch with a **deliberately broken** migration and confirm it goes red; then fix
  and confirm green. A migration-integrity job that has never failed has not been tested.
- Confirm it catches the specific PR #69 bug: temporarily revert `isTransactional(): false` in
  `migrations/Version20260730150000.php` and check the job reports the vanished snapshot rows.
