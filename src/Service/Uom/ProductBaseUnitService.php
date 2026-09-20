<?php

declare(strict_types=1);

namespace App\Service\Uom;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Assigning — and refusing to re-assign — a product's base unit (#601, phase 1 #643).
 *
 * ## The refusal this class exists for
 *
 * **A product's base unit cannot change once anything is denominated in it.** Flip a product from
 * `g` to `kg` while `product_inventory.quantity` reads 5000, and that row silently becomes 5000 kg
 * and every historical document changes meaning at once — no movement written, no audit entry,
 * nothing to reconcile against. Business Central forbids exactly this once ledger entries exist, and
 * for exactly this reason. A product that needs a different base is a new product, or a deliberate
 * restatement done by a person who knows what the numbers mean.
 *
 * Setting the base unit for the first time is not a change and is always allowed — that is the
 * `unit_id` column being filled in for a product that never had one.
 *
 * ## What counts as "denominated in it"
 *
 * Discovered from Doctrine's metadata rather than from a hand-kept list of tables: **any entity that
 * points at a product AND carries a quantity**. That is the definition, not a proxy for one — a row
 * that names a product and holds a number of them is holding a number in that product's base unit,
 * whatever table it happens to live in.
 *
 * The rule picks up, today, `product_inventory` (every bucket, so a product whose `quantity` is 0
 * but whose `received_quantity` is 500 is still locked), `sales_order_line`, `invoice_line`,
 * `estimate_line`, `credit_memo_line`, `sales_return_line`, `cart_item`, the two reservation tables,
 * and — the moment their bundle is installed — `transfer_order_line`, `pick_task`,
 * `purchase_order_line`, `vendor_bill_line`, `goods_receipt_line`, `inventory_detail` and
 * `inventory_movement`. Nothing had to enumerate them.
 *
 * Phase 3 (#646) did have to come back for one line — see {@see NOT_A_QUANTITY}. It added a SECOND
 * quantity-named column to fourteen of those tables, and that one is not denominated in the base
 * unit: `quantity_entered` counts packages. The base column beside it is unchanged and still in the
 * sweep, so no row stopped locking; what would have changed without the exclusion is the sentence
 * the admin reads, which would have named a column measured in cases as a reason the base unit is
 * locked.
 *
 * **Zero does not lock.** A row recording none of a product says nothing about what unit it would
 * have been in, and every product has a `product_inventory` row whether or not it has ever held
 * stock — so mere existence would lock the column against ever being set at all.
 */
final class ProductBaseUnitService
{
    /**
     * Mapped integer/decimal fields whose name matches a quantity but which are not one.
     *
     * `maxBackorderQuantity` is a configured CAP — "never backorder more than 50 of this" — not a
     * count of anything held or ordered. It is settable on a product that has never moved, so
     * treating it as a denominated figure would lock the base unit of a product with no history.
     *
     * `quantityEntered` (phase 3, #646) is a number of PACKAGES, not a number of base units: 50 with
     * `unit_id` pointing at BOX-12 is fifty boxes. The figure denominated in the base unit is
     * the column beside it — `sales_order_line.quantity` and its thirteen siblings — and that column
     * is always present and always in the sweep, so the row still locks. Counting the entered figure
     * as well would report the same row twice under two labels, one of which is not in base units at
     * all: "locked by sales_order_line.quantity (3), sales_order_line.quantity_entered (3)".
     *
     * @var array<string, string>
     */
    private const NOT_A_QUANTITY = [
        'maxBackorderQuantity' => 'a configured backorder cap, not a quantity held',
        'quantityEntered' => 'a number of units of entry, denominated in unit_id and not in the base unit',
    ];

    /**
     * Doctrine types a quantity can actually be stored as.
     *
     * `quantity` is App\Doctrine\Type\QuantityType, the `NUMERIC(14, 4)` column phase 2 (#645) gave
     * the inventory layer. It is listed beside `integer` rather than instead of it because the
     * sweep below runs over every mapped field in the application, and a bundle or a table this
     * phase did not reach still declares its quantities as plain integers.
     *
     * @var list<string>
     */
    private const NUMERIC_TYPES = ['integer', 'bigint', 'smallint', 'decimal', 'float', 'quantity'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Points $product at $unit, or refuses — and keeps the legacy `product_core.unit` label in step.
     *
     * The check and the write are one method on purpose: a caller cannot reach `setBaseUnit()`
     * without passing the guard, because the setter is the last line of the method that runs it.
     *
     * ## Why this writes the old free-text column too
     *
     * `product_core.unit` is the VARCHAR(80) an admin used to TYPE, and roughly forty places still
     * read it — order lines, invoices, estimates, credit memos, the catalog. #643 added
     * `base_unit_id` beside it and deliberately left it alone, because nothing consumed the new
     * column yet.
     *
     * That left a product able to declare one thing and display another: `base_unit_id -> KG` while
     * the label said `EA`, with no way to tell which was right. Two places holding one fact is the
     * shape behind #589, #590 and #591, and the free-text box was the writable one.
     *
     * So the declaration is now the only input, and the label is derived from it here. Every
     * existing reader keeps working unchanged, and the two can no longer disagree — which
     * `EveryProductsUnitLabelMatchesItsBaseUnitTest` asserts by enumerating the products rather than
     * trusting this comment.
     *
     * Assigning null leaves the label ALONE rather than clearing it. A product that has never
     * declared a base unit still has whatever was typed on it years ago, and silently blanking a
     * label forty readers display would be a worse answer than leaving a legacy value visible until
     * somebody declares one.
     *
     * Does not flush. The product form applies a dozen fields and commits once, and a service that
     * flushed here would commit half a form the validator has not finished with.
     *
     * @throws UnitOfMeasureRefusal when the product already has a different base unit and something
     *                              is denominated in it
     */
    public function assign(ProductCore $product, ?UnitOfMeasure $unit): void
    {
        $current = $product->getBaseUnit();

        if ($this->sameUnit($current, $unit)) {
            // Already pointing there — but the label may still be a legacy value that never agreed
            // with it, so bring it into step rather than returning on the id check alone.
            $this->syncLabel($product, $unit);

            return;
        }

        if ($current !== null) {
            $blockers = $this->blockers($product);

            if ($blockers !== []) {
                $where = [];
                foreach ($blockers as $label => $count) {
                    $where[] = sprintf('%s (%d)', $label, $count);
                }

                throw new UnitOfMeasureRefusal(sprintf(
                    'The base unit of "%s" is %s and cannot be changed: %s already hold quantities counted in it. '
                        . 'Changing it now would restate every one of those figures at once without moving a thing. '
                        . 'A product that needs a different base is a new product.',
                    $product->getSku() ?? ('product #' . (string) $product->getId()),
                    $current->getCode(),
                    implode(', ', $where),
                ));
            }
        }

        $product->setBaseUnit($unit);
        $this->syncLabel($product, $unit);
    }

    /**
     * Whether two unit references mean the same unit.
     *
     * Identity first, then ids — and ids only when BOTH are non-null, which is the part that
     * matters. The check this replaces was `$current?->getId() === $unit?->getId()`, and that reads
     * as "same unit" for a case it is badly wrong about: a product with NO base unit being assigned
     * a UnitOfMeasure that has not been flushed yet compares `null === null` and returns early,
     * so the assignment silently does nothing and the caller has no way to tell.
     *
     * It never bit anything because every caller in the app hands over a unit loaded from the
     * database, which always has an id. That is luck rather than design, and it is the kind of luck
     * that runs out inside a fixture, an import, or a console command that builds a unit and
     * assigns it in the same breath.
     */
    private function sameUnit(?UnitOfMeasure $current, ?UnitOfMeasure $unit): bool
    {
        if ($current === $unit) {
            return true;
        }

        if ($current === null || $unit === null) {
            return false;
        }

        $currentId = $current->getId();

        return $currentId !== null && $currentId === $unit->getId();
    }

    /**
     * Writes the declared unit's code onto the legacy label column.
     *
     * The CODE rather than the name or the label, because that is the shape the column already
     * holds and the shape every reader already prints: `EA`, not `Each` and not `EA — Each`.
     * Changing what those forty readers display is a separate decision from stopping the field
     * being typed, and this change is only the second one.
     */
    private function syncLabel(ProductCore $product, ?UnitOfMeasure $unit): void
    {
        if ($unit === null) {
            return;
        }

        if ($product->getUnit() !== $unit->getCode()) {
            $product->setUnit($unit->getCode());
        }
    }

    /**
     * The unit a legacy free-text label names, or null when it names none. Writes nothing.
     *
     * `product_core.unit` is the VARCHAR(80) an admin used to type, and it holds BOTH shapes of the
     * same fact: some rows say `EA`, some say `Each`. Those are one unit. #643 seeded
     * `unit_of_measure` FROM the distinct values this very column already held, so a label is
     * either one of those rows under one of its two names, or it is not a unit at all.
     *
     * Matching on the CODE alone — what the #601 backfill did — therefore answered "no unit" for
     * every row that spelled it out, and the form then told an admin, about the label `Each`, that
     * it "is not one of the units above". That is false, and the owner hit it. Code OR name,
     * trimmed and case-insensitive, is the whole of the intended mapping rather than a heuristic.
     *
     * Code is tried before name, and the order matters because nothing stops a row `EACH`/`Unit`
     * sitting beside `EA`/`Each`: the column was built to hold symbols, so a label is likeliest to
     * be one.
     *
     * What this deliberately does NOT resolve is a value that was never a unit. `12/Case` is the
     * real example — the product import's template guide used to document its `unit` column with
     * exactly it — and it is a PACK SIZE. Under #659 a pack IS a unit — `CASE-12`, with a ratio of 12 on
     * {@see \App\Entity\UnitOfMeasure} — but which term a given `12/Case` meant is a person's
     * call. Answering `EA` for it would invent a fact nobody stated, so it answers null and the form
     * names it on screen for a human to resolve.
     *
     * ## Resolving is not declaring
     *
     * Nothing here touches the product, and that is the point. The form pre-selects this answer so
     * the admin confirms it with the save they were making anyway; the declaration itself happens
     * in {@see assign()}, on a request a person made. Declaring on render would be a write to
     * existing data that nobody asked for, which this repo forbids outright — and it would do it
     * on a GET.
     */
    public function resolveLegacyLabel(?string $label): ?UnitOfMeasure
    {
        $normalized = mb_strtolower(trim((string) $label));

        if ($normalized === '') {
            return null;
        }

        return $this->unitMatching('code', $normalized) ?? $this->unitMatching('name', $normalized);
    }

    /**
     * The one unit whose $field equals $normalized, compared trimmed and lower-cased.
     *
     * Ordered by code so the answer is the same on every render. `code` is unique and can only ever
     * match one row; `name` is not, and an unordered `setMaxResults(1)` over two units sharing a
     * name would pre-select whichever the storage engine felt like that day.
     */
    private function unitMatching(string $field, string $normalized): ?UnitOfMeasure
    {
        $match = $this->em->createQuery(
            'SELECT u FROM ' . UnitOfMeasure::class . ' u'
            . ' WHERE LOWER(TRIM(u.' . $field . ')) = :label'
            . ' ORDER BY u.code ASC'
        )->setParameter('label', $normalized)->setMaxResults(1)->getOneOrNullResult();

        return $match instanceof UnitOfMeasure ? $match : null;
    }

    /**
     * Everything already denominated in $product's base unit, as `table.column => row count`.
     *
     * Empty means the base unit is still free to change. Public because the product form shows it:
     * "locked by product_inventory.received_quantity (500)" tells an admin why, where a greyed-out
     * select tells them nothing.
     *
     * @return array<string, int>
     */
    public function blockers(ProductCore $product): array
    {
        $blockers = [];

        foreach ($this->quantityBearingReferences() as [$entityClass, $productField, $quantityField, $label]) {
            $count = (int) $this->em->createQueryBuilder()
                ->select('COUNT(r)')
                ->from($entityClass, 'r')
                ->andWhere('r.' . $productField . ' = :product')->setParameter('product', $product)
                ->andWhere('r.' . $quantityField . ' <> 0')
                ->getQuery()->getSingleScalarResult();

            if ($count > 0) {
                $blockers[$label] = $count;
            }
        }

        return $blockers;
    }

    /**
     * Every `table.column` in the entity map that can hold a quantity of a product, sorted.
     *
     * Exposed so a conformance test can enumerate its subjects from the entity map rather than from
     * a list somebody has to remember to update.
     *
     * @return list<string>
     */
    public function quantityColumns(): array
    {
        $labels = array_map(static fn (array $ref): string => $ref[3], $this->quantityBearingReferences());
        sort($labels);

        return $labels;
    }

    /**
     * @return list<array{0: class-string, 1: string, 2: string, 3: string}> entity, product field,
     *                                                                      quantity field, `table.column`
     */
    private function quantityBearingReferences(): array
    {
        $found = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta instanceof ClassMetadata || $meta->isMappedSuperclass) {
                continue;
            }

            $productField = $this->productField($meta);
            if ($productField === null) {
                continue;
            }

            foreach ($meta->fieldMappings as $field => $mapping) {
                if (!self::namesAQuantity($field) || isset(self::NOT_A_QUANTITY[$field])) {
                    continue;
                }

                // A number, not a label. `<> 0` has no meaning against a VARCHAR, and a field named
                // for a quantity but typed as text is a note about one, not one.
                if (!\in_array($mapping->type, self::NUMERIC_TYPES, true) || $mapping->inherited !== null) {
                    continue;
                }

                $found[] = [$meta->getName(), $productField, $field, $meta->getTableName() . '.' . $mapping->columnName];
            }
        }

        return $found;
    }

    /** The field on $meta that points at a product, or null when it has none. */
    private function productField(ClassMetadata $meta): ?string
    {
        foreach ($meta->associationMappings as $field => $mapping) {
            if ($mapping->targetEntity !== ProductCore::class) {
                continue;
            }

            if (!$mapping->isToOne() || !$mapping->isOwningSide() || $mapping->inherited !== null) {
                continue;
            }

            return $field;
        }

        return null;
    }

    /**
     * Whether a field name says "this holds a number of the product".
     *
     * Three shapes, all present in the codebase today: `quantity`, `quantityRequested` /
     * `quantityDispatched` (a stage of one), and `receivedQuantity` / `syncedQuantity` (a bucket).
     * `manualAdjustment` is named for what it is rather than for what it holds, and is the one
     * figure on `product_inventory` that has to be named outright.
     */
    private static function namesAQuantity(string $field): bool
    {
        return $field === 'quantity'
            || $field === 'manualAdjustment'
            || str_starts_with($field, 'quantity')
            || str_ends_with($field, 'Quantity');
    }
}
