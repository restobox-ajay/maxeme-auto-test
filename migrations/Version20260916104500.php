<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The one-time upgrade that keeps the lights on when the bundle default flips.
 *
 * ## What changed above this line
 *
 * `BundleStatusRepository::isActive()` used to read:
 *
 *     return $status === null || $status->getStatus() === STATUS_ACTIVE;
 *
 * Absence of a row meant ENABLED. Every installation that predates this migration is therefore
 * running its bundles with NO rows at all — that IS the enabled state, and on a long-lived instance
 * it is the normal one, because nothing ever had to write a row for a bundle nobody switched off.
 * It now reads:
 *
 *     return $status !== null && $status->getStatus() === STATUS_ACTIVE;
 *
 * Flip that without doing anything else and every bundle on every existing database goes dark in
 * the same instant: procurement, tax, fees, shipping, inventory depth, payments, barcodes. Not one
 * of them has a row, and absence now means off.
 *
 * ## Order of operations, which is the whole job
 *
 *   1. establish the live set of bundles      -> the modules/* glob below
 *   2. write an Active row for each with none -> the INSERT below
 *   3. only then does the new default apply   -> guaranteed by 1 and 2 being a MIGRATION
 *
 * Step 3 is the reason this is a migration and not a console command. `doctrine:migrations:migrate`
 * is already part of every deploy and it runs before the application serves a request; a deploy
 * step somebody has to remember is a deploy step somebody eventually forgets, and the cost of
 * forgetting this one is the entire application inert with no error message anywhere — every gate
 * politely answering "that bundle is off". There is no louder failure available to a command,
 * because the failure is silence. So it goes where it cannot be skipped.
 *
 * ## Why it does not need the kernel's bundle list, and could not have used it
 *
 * Worth stating plainly, because "ask the kernel which bundles are loaded" is the obvious
 * implementation and it is not available here. A migration under doctrine/doctrine-migrations-bundle
 * 3.7 is handed a DBAL Connection and nothing else — no container, no kernel, no services (the
 * container-aware migration was removed in 3.0, and every migration in this chain is plain SQL for
 * that reason). {@see \App\Bundle\InstalledBundleDirectory}, which the console commands use, needs
 * `KernelInterface` and so cannot be reached from here.
 *
 * It does not matter, because the question is answerable from the filesystem. `config/bundles.php`
 * discovers modules by exactly this glob and exactly this `src/<Name>.php` test; the only check
 * repeated below that it does not repeat is `class_exists()`, deliberately left out so this
 * migration never triggers autoloading of application code that may not exist by the time the chain
 * is replayed. The consequence of that omission is a folder that looks like a module but does not
 * declare its bundle class getting a row nothing ever reads — a harmless spare row, in the safe
 * direction.
 *
 * The duplication of five lines of glob is deliberate and is the lesser evil. A migration that
 * calls into `src/` is a migration that breaks when `src/` moves on, and this chain has to replay
 * years from now on a database built before any of this existed.
 *
 * ## Additive only
 *
 * `docs/QUEUE.md`'s ADD-only rule holds: this INSERTs rows and does not UPDATE or DELETE a single
 * one. `WHERE NOT EXISTS` is what enforces it — an installation that already made a choice about a
 * bundle keeps that choice, including an explicit Inactive, which must NOT be swept up and switched
 * on by an upgrade step. Somebody turning a bundle off is a decision on the record and this
 * migration is not entitled to overrule it.
 *
 * That said, this is an INSERT in a migration, and QUEUE.md's rule as written says migrations add
 * columns and tables. The owner approved this specific write: the rows being added are the explicit
 * form of a state the database was already in, so the value of `isActive()` for every existing
 * source is identical before and after. Nothing's behaviour changes; only its representation.
 *
 * ## What a crash leaves behind
 *
 * `AbstractMigration::isTransactional()` is true and SQLite has transactional DDL, so the INSERT
 * below is atomic: either every row lands or none does. There is no partially-upgraded database in
 * which some bundles have rows and others do not, which is the state worth fearing, because it is
 * the one where an installation looks half-working and the missing half is silent.
 *
 * The window that does exist is between the new CODE being deployed and this migration completing.
 * In it, bundles read Inactive. Three things about it:
 *
 *  - it is the ordinary deploy window every schema change has, and the ordinary mitigation applies
 *    — run migrations before cutting traffic to the new release;
 *  - a crash rolls back to zero rows, which is the same state as "not yet run", so the recovery is
 *    to run it again rather than to work out how far it got;
 *  - re-running is safe and cheap. `WHERE NOT EXISTS` makes this idempotent, and
 *    `app:bundle:activate --all-present` reaches the same end state from the shell with no HTTP
 *    request, no logged-in user and no bundle active, for a database that somehow missed it.
 *
 * A half-applied run therefore cannot leave a bundle OFF that was ON; it can only leave everything
 * as it was before the deploy.
 *
 * ## What this does NOT do
 *
 * It does not pre-activate anything on an installation that has no modules on disk, and it writes
 * nothing for the four hub bundles (FeeBundle, PaymentBundle, ShippingBundle, TaxBundle) beyond
 * what the glob finds — they are matched by the glob like any other module, and no gate anywhere
 * asks about them, so their rows are inert either way.
 *
 * ## Fresh installs
 *
 * A database created from scratch runs this as part of the chain, so it starts with every module
 * then on disk marked Active. That is the safe direction and it matches what a fresh install of the
 * previous code did. A module added to `modules/` AFTER this migration has run gets no row and is
 * inert until a person activates it, which is the new rule working as intended.
 *
 * ## The stamp
 *
 * `Version20260916104500` is above every stamp on any ref, tag, working tree or reflog at the time
 * of writing — the highest found anywhere was `Version20260915142000`, sitting unmerged in another
 * agent's worktree, with `Version20260914113000` the highest on main. Off the daily `090000`
 * pattern on purpose, for the reason `Version20260912161500` gives: two agents both reaching for
 * "tomorrow at 09:00" is how two branches collide on one stamp and silently drop each other's work,
 * and no test suite would see it — `tests/_bootstrap.php` builds the schema from Doctrine metadata
 * and never runs the chain, so only `bin/ci-migration-replay` ever would.
 */
final class Version20260916104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bundles are inert until activated: write the Active rows that were previously implicit.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->installedSources() as $source) {
            // INSERT ... SELECT ... WHERE NOT EXISTS rather than INSERT OR IGNORE: the latter would
            // also swallow a genuine constraint failure, and this one has to be loud if the table
            // is not what it expects. Plain enough for SQLite 3.26 on the production servers —
            // no RETURNING (3.35), no IIF (3.32), no generated columns (3.31).
            $this->addSql(
                <<<'SQL'
                    INSERT INTO bundle_status (source, status)
                    SELECT :source, 'Active'
                    WHERE NOT EXISTS (SELECT 1 FROM bundle_status WHERE source = :source)
                    SQL,
                ['source' => $source],
            );
        }
    }

    /**
     * Every first-party module directory on disk, by the same convention `config/bundles.php` uses.
     *
     * Runs at migration-generation-free time, i.e. when `up()` is called, so it sees the modules
     * shipped in the release being deployed — which is exactly the set whose bundles were enabled
     * by absence a moment earlier.
     *
     * @return list<string>
     */
    private function installedSources(): array
    {
        // migrations/ sits at the project root, so this resolves to <project>/modules.
        $moduleDirs = glob(dirname(__DIR__) . '/modules/*', \GLOB_ONLYDIR) ?: [];
        sort($moduleDirs);

        $sources = [];
        foreach ($moduleDirs as $moduleDir) {
            $name = basename($moduleDir);

            if (is_file($moduleDir . '/src/' . $name . '.php')) {
                $sources[] = $name;
            }
        }

        return $sources;
    }

    /**
     * Deliberately empty, and that is the honest reversal.
     *
     * Deleting the rows this migration wrote would be indistinguishable from deleting rows a person
     * wrote — `bundle_status` records no author and no time, so by the moment `down()` runs there is
     * no way to tell an Active row inserted here from one an administrator created by pressing
     * Activate afterwards. Removing both would silently discard somebody's decision; removing
     * neither leaves an installation with explicit rows that the old code reads exactly as it read
     * their absence, since under the old rule an Active row and no row meant the same thing.
     *
     * So migrating down is lossless here, which is the rare case where doing nothing is correct
     * rather than lazy.
     */
    public function down(Schema $schema): void
    {
    }
}
