<?php

declare(strict_types=1);

namespace App\Contract\ReferenceData;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One bundle's (or core's) shipped reference data, seeded ONCE by the central seeder.
 *
 * ## What this replaces
 *
 * Reference lists in this application used to be created as a side effect of RENDERING the config
 * screen that owns them — `UnitOfMeasureController::index()` called `ensureSeeded()`,
 * `TrackingPolicyController::index()` called `ensureDefault()`, `AdjustmentController::form()`
 * called `ensureCatalogue()`, and five fee screens plus the Canadian tax screen called
 * `ensureBySlug()`/`ensureProvinceRows()` before rendering a row. So the first person to open a
 * screen created the data by looking at it, and until somebody did, everything downstream behaved
 * as though the concept did not exist. Worse, they were GET actions that wrote.
 *
 * Seeding is now one thing that happens at one moment (the first admin login,
 * {@see \App\EventSubscriber\AdminLoginReferenceDataSeedSubscriber}), and the config screens are
 * pure readers.
 *
 * ## Registering one
 *
 * Implement this interface anywhere under `src/` or a bundle's own `src/` and it is picked up
 * automatically — no registry to edit, no `services.yaml` entry, nothing to add to the central
 * seeder. #[AutoconfigureTag] below plus this app's blanket `autoconfigure: true`
 * (`config/services.yaml`, and every bundle's own `modules/<Bundle>/config/services.yaml`) tags it
 * as `app.reference_data_seeder`, which {@see \App\Service\ReferenceData\ReferenceDataSeeder}
 * collects via #[AutowireIterator('app.reference_data_seeder')]. This is deliberately the same
 * seam {@see \App\Contract\Onboarding\OnboardingCheckInterface} uses, for the same reason and in
 * the same shape — a bundle added next year seeds on its own first login with zero edits to core.
 *
 * ## The three rules an implementation MUST obey
 *
 * 1. **Insert only what is missing.** Look each row up by its natural key first. The central seeder
 *    will normally not call you twice (see the marker below), but an installation upgraded from the
 *    old lazy-seeding code ALREADY HAS the rows and no marker, so the first real call must find them
 *    and write nothing.
 * 2. **Never update a row that exists.** `docs/QUEUE.md`: *"Never write to existing data."* These
 *    are configurable rows — an admin who has relabelled a reason, changed a fee's tax class or
 *    zeroed a rate must not have it silently restored. The seed is a floor, not a template.
 * 3. **Never resurrect a row somebody deleted.** The per-bundle marker in
 *    `reference_data_seed_mark` is what makes this possible to obey: once your key is marked, you
 *    are never called again, so a row deleted afterwards stays deleted. That is the whole reason
 *    the marker is per bundle rather than one global "we have seeded" flag — a bundle installed
 *    later has no mark of its own and still seeds.
 *
 * ## Failure
 *
 * `seed()` MAY throw. The central seeder rolls that seeder's transaction back (so its mark is NOT
 * claimed and it is retried on the next login), logs it, and carries on with the others — the same
 * way `OnboardingChecklistService::runOneSafely()` refuses to let one bad check blank the page for
 * the rest. This runs on the login path, so a broken seeder must never cost anyone their session.
 */
#[AutoconfigureTag('app.reference_data_seeder')]
interface ReferenceDataSeederInterface
{
    /**
     * Stable identifier for this seeder, e.g. 'core.unit_of_measure' or
     * 'inventory_depth.adjustment_reason'.
     *
     * **This string is PERSISTED** — it is the natural key of the "already seeded" mark, so
     * renaming it re-seeds on the next login and can resurrect rows a customer deleted. Pick it
     * once. Namespace it with the owning bundle so two bundles cannot collide.
     *
     * A string the implementation declares, deliberately, rather than any of the three things that
     * were available for free: the class FQCN (moves the first time somebody renames a namespace,
     * and taking a mark with it), the container service id (the same string plus a container
     * implementation detail), or an auto-increment (not stable across installations at all, and
     * meaningless in a `WHERE` clause). The mark has to survive refactors the row it guards knows
     * nothing about, so the identity is declared by the only party that can promise it is stable.
     *
     * ## One mark per SEED BATCH, not per bundle — and why that is the non-cornering choice
     *
     * A bundle may register as many seeders as it likes; each key marks itself independently. So a
     * bundle that ships a second batch of reference data next year registers a SECOND seeder with a
     * NEW key (`inventory_depth.gl_account`, say, beside `inventory_depth.adjustment_reason`). The
     * new key has no mark, so it seeds on the next admin login on every installation — including
     * ones live for years — while the old key stays marked and its rows are never revisited.
     *
     * That is ADD-only in the `docs/QUEUE.md` sense: a new mark row appears, and no existing mark
     * row and no existing seeded row is ever written to. The alternative — one mark per bundle with
     * a version column that gets UPDATEd when the batch grows — is exactly the write-to-existing-data
     * this repository forbids, and it is why this is a key rather than a (bundle, version) pair.
     */
    public function getKey(): string;

    /** Short human-readable name for the log line and the seed report, e.g. "Units of measure". */
    public function getLabel(): string;

    /**
     * Create the rows that are missing and return how many were created.
     *
     * `persist()` only — do NOT `flush()` and do NOT open a transaction. The central seeder owns
     * both: it claims the mark and flushes your entities inside ONE transaction, so a seeder that
     * dies halfway leaves neither half-written rows nor a mark saying it is done.
     */
    public function seed(): int;
}
