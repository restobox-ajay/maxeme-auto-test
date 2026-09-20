<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `uniq_fulfillment_region_name` — two fulfillment regions may not share a name, said by the database.
 *
 * ## What two rows of one name cost, which is why this is not tidiness
 *
 * `fulfillment_region.name` carried no constraint at all, and two regions called `Main` made the two
 * halves of the application disagree about which row the word meant:
 *
 *  - `CustomerPricingResolver::resolveFulfillmentRegionEntity('Main')` walks `findAll()` in id order
 *    and binds a cart line to whichever row is first. If that row is the one with no warehouse,
 *    `CartService::availableQuantity()` asks it for its building, gets none, and reports **0 units**.
 *  - `AdminOrderStockValidator` goes through `WarehouseFulfillmentRegionService::warehousesByLowerRegionName()`,
 *    which is keyed by `strtolower(trim(name))`, finds the link, and sees **500**.
 *
 * Same word, same screen, two answers. Create Customer rendered two indistinguishable `Main`
 * checkboxes, each with its own price-list select, and a document stores only the NAME
 * (`AbstractSalesDocument::$fulfillmentRegion` is a string snapshot, not a foreign key) — so the
 * price chosen against the second box cannot even be recovered afterwards.
 *
 * ## Why the database and not another check in PHP
 *
 * One door was closed in application code already: `WarehouseFulfillmentRegionService::createRegionForWarehouse()`
 * now ADOPTS an unpaired region of that name and REFUSES a served one, covered by
 * `WarehouseNamedAfterAnExistingRegionCest`. That fixed the path somebody had walked. It did nothing
 * about the other four that exist today, and nothing at all about the fifth written next year. An
 * index is the only form of this rule that a new caller cannot forget to ask.
 *
 * ## Why the index is on LOWER(TRIM(name)) and not on name
 *
 * Because `LOWER(TRIM(name))` is what the application ALREADY means by a region name, and a plain
 * `UNIQUE(name)` would enforce something else:
 *
 *  - `warehousesByLowerRegionName()` keys on `strtolower(trim($name))`.
 *  - `WarehouseFulfillmentRegionService::regionNamed()` matches on `strtolower(trim(...))`.
 *  - `ProductImportController::findOrCreateRegionByName()` matches on `LOWER(...)`.
 *
 * So `Main`, `main` and ` Main ` are ALREADY one region to every stock lookup in the codebase. Two
 * rows spelt that way are not two regions; they are one region spread over rows of which all but the
 * lowest id are unreachable — the same defect as an exact duplicate, wearing a different hat. A
 * plain `UNIQUE(name)` would have let the Fulfillment Regions screen keep minting them, because
 * `ValidFulfillmentRegionRequestValidator` matched with `findOneBy(['name' => $value])` and SQLite's
 * `=` on a `VARCHAR` is case-SENSITIVE.
 *
 * ## What the expression form costs, stated rather than buried
 *
 * Doctrine's mapping cannot express an index over an expression. That is not a guess — declaring
 * `#[ORM\UniqueConstraint(columns: ['LOWER(TRIM(name))'])]` on `App\Entity\FulfillmentRegion` makes
 * `doctrine:schema:create` fail outright with *"There is no column with name "LOWER(TRIM(name))" on
 * table "fulfillment_region""*. So:
 *
 *  1. `doctrine:schema:update` would want to DROP this index, because the schema it computes from
 *     the mappings does not contain it. It would want to drop `uniq_inventory_detail` for the same
 *     reason and has since `Version20260821160000`.
 *  2. Both test suites build their schema from entity metadata (`tests/_bootstrap.php` runs
 *     `doctrine:schema:create`; `DoctrineIntegrationTestCase` uses `SchemaTool`), so neither
 *     database carries this index. Only a MIGRATED database has it, which is why
 *     `bin/ci-migration-replay` is where it is proved against real rows.
 *  3. This is now the SECOND index in the chain that DBAL cannot introspect — `PRAGMA
 *     index_info(uniq_fulfillment_region_name)` reports `cid = -2, name = NULL`, exactly as
 *     `uniq_inventory_detail` does, and `Index::_addColumn()` is typed `string`. See
 *     {@see SqliteMigrationIntrospection}.
 *
 * Point 3 is the one worth weighing, and the honest reading is that it costs nothing NEW: `$schema`,
 * `doctrine:schema:update` and `doctrine:schema:validate` without `--skip-sync` are already
 * unusable on every migrated database and have been for a month, and removing this index would not
 * make any of them usable again. What it does change is that removing `uniq_inventory_detail` one
 * day would no longer restore them either — so this docblock, the trait's, and `bin/ci-migration-replay`'s
 * step 1 all have to keep naming the plural. They do.
 *
 * Against that: a plain `UNIQUE(name)` plus a case-insensitive check in the validator would leave
 * the case hole enforced ONLY in PHP, in exactly the layer whose forgetfulness this whole exercise
 * is about. The database is the point.
 *
 * ## ADD only, and it REFUSES rather than repairs
 *
 * One `CREATE UNIQUE INDEX`. No `UPDATE`, no `DELETE`, no merge, no data migration — `docs/QUEUE.md`
 * forbids writing to existing data, and which of two rows survives a merge is a decision about a
 * real installation that a migration cannot make: it would have to choose which building's stock
 * becomes unreachable.
 *
 * An index cannot be created over rows that already violate it, so the alternative to refusing
 * deliberately is dying on SQLite's own message — `UNIQUE constraint failed: index
 * 'uniq_fulfillment_region_name'` — which names neither the offending rows nor what to do. The
 * census below runs first and aborts with both. The owner has one production instance and merges by
 * hand; that message is the whole of what tells him what to merge.
 *
 * ## SQLite 3.26
 *
 * Expression indexes are 3.9+ and `GROUP_CONCAT` predates that, so the production servers are fine.
 * No `DROP COLUMN` or `RETURNING` (3.35), no generated column (3.31), no `IIF()` (3.32).
 *
 * ## Why the guards read PRAGMA rather than the injected Schema
 *
 * Touching `$schema` introspects the whole database and dies on `uniq_inventory_detail` — the index
 * this one now keeps company. {@see SqliteMigrationIntrospection} is the explanation and the fix;
 * `$schema` stays in the signature because `AbstractMigration` declares it, and is never read.
 */
final class Version20260918120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    /** Named rather than declared inline, so the index says what it is in SQLite's own error. */
    private const INDEX = 'uniq_fulfillment_region_name';

    public function getDescription(): string
    {
        return 'Add uniq_fulfillment_region_name, a UNIQUE index over LOWER(TRIM(name)) on fulfillment_region. '
            . 'ADD only; refuses with the offending names and ids if any already collide.';
    }

    public function up(Schema $schema): void
    {
        // A database that has not reached the table yet, and a re-run, are both no-ops rather than
        // failures — the same shape every other guard in this chain uses.
        if (!$this->tableExists('fulfillment_region') || $this->indexExists(self::INDEX)) {
            return;
        }

        $collisions = $this->collisions();
        $this->abortIf($collisions !== [], self::refusal($collisions));

        // Written out rather than sprintf'd around self::INDEX so this line is the literal DDL and
        // can be pinned as text: FulfillmentRegionNameIsUniqueCest builds the same index to prove
        // what it refuses, and asserts that THIS file contains the statement it built. Neither can
        // drift from the other without that assertion failing.
        $this->addSql('CREATE UNIQUE INDEX uniq_fulfillment_region_name ON fulfillment_region (LOWER(TRIM(name)))');
    }

    /**
     * Drops the index, which is the honest reversal: before this migration there was no constraint
     * on the column, and going back means exactly that. No row is touched either way.
     */
    public function down(Schema $schema): void
    {
        if ($this->indexExists(self::INDEX)) {
            $this->addSql(sprintf('DROP INDEX %s', self::INDEX));
        }
    }

    /**
     * The census, run against the live connection before a single statement is emitted.
     *
     * Deliberately the same query a person would run by hand to check, so the message below and the
     * check that produced it cannot describe different things.
     *
     * @return list<array{region_key: string, n: int, ids: string}>
     */
    private function collisions(): array
    {
        /** @var list<array{region_key: string, n: int|string, ids: string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(TRIM(name)) AS region_key, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
             FROM fulfillment_region
             GROUP BY LOWER(TRIM(name))
             HAVING COUNT(*) > 1
             ORDER BY LOWER(TRIM(name))'
        );

        return array_map(
            static fn (array $row): array => [
                'region_key' => (string) $row['region_key'],
                'n' => (int) $row['n'],
                'ids' => (string) ($row['ids'] ?? ''),
            ],
            $rows,
        );
    }

    /**
     * What the operator reads when the census finds something — the entire remedy, in one message.
     *
     * It names every colliding name and every id, because a message saying only "duplicates exist"
     * on a database the owner cannot query from the deploy shell is a message that sends him looking
     * for a SQLite client before he can start.
     *
     * @param list<array{region_key: string, n: int, ids: string}> $collisions
     */
    private static function refusal(array $collisions): string
    {
        if ($collisions === []) {
            return '';
        }

        $lines = [];
        foreach ($collisions as $collision) {
            $lines[] = sprintf('  "%s" — %d rows, ids %s', $collision['region_key'], $collision['n'], $collision['ids']);
        }

        return sprintf(
            "fulfillment_region already holds %d name(s) that this index would forbid, so it has NOT been "
            . "created and NOTHING has been written:\n\n%s\n\n"
            . "Names are compared as LOWER(TRIM(name)) because that is already what the application means by a "
            . "region name — WarehouseFulfillmentRegionService::warehousesByLowerRegionName() keys on "
            . "strtolower(trim(name)) — so the rows above are not several regions. They are ONE region each, "
            . "spread over rows of which only the lowest id is ever resolved, while the others hold stock, "
            . "prices and customer links that nothing reaches.\n\n"
            . "Merge each group by hand, then run this migration again. For each group: keep the LOWEST id "
            . "(the row the application already resolves), repoint what names the others at it, and delete "
            . "them. Three tables carry the id:\n"
            . "  warehouse_fulfillment_region.fulfillment_region_id — uniq_wfr_region gives a region exactly "
            . "ONE building, so a group whose rows have two warehouses needs a decision about where that "
            . "stock lives before the merge, not an UPDATE\n"
            . "  company_fulfillment_region.fulfillment_region_id — drop the duplicate pair rather than "
            . "repointing it if the customer is already linked to the surviving row\n"
            . "  cart_item.fulfillment_region_id\n"
            . "Documents (estimate, sales_order, invoice, quote) store the region NAME and not the id, so "
            . "their text is already ambiguous and the merge is what settles it; no UPDATE is needed there.\n\n"
            . "No repair ships with this migration on purpose: which row survives, and which building's stock "
            . "becomes the region's, is a decision about a real installation and not something a migration "
            . "may guess.",
            \count($collisions),
            implode("\n", $lines),
        );
    }
}
