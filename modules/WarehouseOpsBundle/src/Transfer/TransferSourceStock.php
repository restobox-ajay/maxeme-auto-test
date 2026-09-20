<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Transfer;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;

/**
 * What the SOURCE warehouse can actually send, phrased the way a person picking a line needs it
 * (#590 P2, #610, #611).
 *
 * A transfer line used to name its lot by primary key, typed into `<input type="number">`. Nobody
 * knows what lot 3 is, `addLine()` never checked that the id was a batch of the line's product, and
 * the first time anyone found out the line was wrong was at dispatch, where
 * `assertSourcesCanCover()` throws. This class is the read side that makes the choice nameable:
 *
 *  - **lots and serials that exist at the source**, with the quantity each one holds, so an option
 *    reads `LOT-SEA-2609 · exp 2026-09-10 · 40 available` rather than `3`;
 *  - **wildcard matching over those codes**, so `SEA-26*` finds the batch without knowing the id.
 *
 * Everything here is a read. Nothing in this class writes stock, a bucket or a document — the
 * dropdown is a convenience, and `TransferOrderController` still refuses a mismatched pair on the
 * server whether the choice came from a picker, a typed pattern or a hand-built POST.
 *
 * ## Why `available` and not `quantity`
 *
 * The rows counted are `inventory_detail` rows at the source warehouse with
 * `status = 'available'` and `quantity > 0` — the same set `InventoryDetailRepository::pickableRows()`
 * walks, and the same set a dispatch consumes. Stock that is damaged, quarantined, already in
 * transit or sold is not offerable, so it is not offered.
 *
 * ## Wildcards
 *
 * `*` and `%` both mean "any run of characters" and `?` and `_` both mean "one character", because
 * an operator will type whichever one their last system used. A pattern carrying none of them is a
 * plain substring match, which is what "search" means to everybody who is not writing SQL. Matching
 * happens in PHP over the already-scoped candidate set rather than as a LIKE against the whole
 * table: the set is small, and it keeps a typed pattern from ever reaching the database as syntax.
 */
final class TransferSourceStock
{
    /**
     * How many options a picker is allowed to render before it stops being a picker.
     *
     * Past this the list is truncated and the template says so — the typed pattern beside the
     * dropdown is the way through, which is exactly the case wildcard matching exists for.
     */
    public const OPTION_CAP = 300;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Lots holding sellable stock at this warehouse, newest expiry last, with what each one holds.
     *
     * `$product` null means every product at the warehouse — the entry row's list, where no product
     * has been chosen yet. The product's SKU rides along on each choice so the option can say whose
     * batch it is and the caller can group by it.
     *
     * @return list<array{id: int, sku: string, product: string, code: string, expiry: ?string, available: string, label: string}>
     */
    public function lotChoices(Warehouse $from, ?ProductCore $product = null): array
    {
        $byLot = [];

        foreach ($this->rows($from, $product) as $row) {
            $lot = $row->getLot();
            if (!$lot instanceof InventoryLot) {
                continue;
            }

            $id = (int) $lot->getId();
            if (!isset($byLot[$id])) {
                $byLot[$id] = [
                    'id' => $id,
                    'sku' => $row->getProduct()->getSku(),
                    'product' => $row->getProduct()->getName(),
                    'code' => $lot->getCode(),
                    'expiry' => $lot->getExpiry()?->format('Y-m-d'),
                    'available' => QuantityScale::canonical(0),
                    'label' => '',
                ];
            }

            $byLot[$id]['available'] = QuantityScale::add($byLot[$id]['available'], $row->getQuantity());
        }

        $choices = [];
        foreach ($byLot as $choice) {
            $choice['label'] = $this->lotLabel($choice['code'], $choice['expiry'], $choice['available']);
            $choices[] = $choice;
        }

        return $choices;
    }

    /**
     * Serials sitting at this warehouse, each with the units behind it.
     *
     * A serial row is capped at one unit by the movement layer, so `available` is all but always 1 —
     * it is carried anyway because a serial that somehow holds none must not read the same as one
     * that holds a unit.
     *
     * @return list<array{serial: string, sku: string, product: string, lot: ?string, available: string, label: string}>
     */
    public function serialChoices(Warehouse $from, ?ProductCore $product = null): array
    {
        $bySerial = [];

        foreach ($this->rows($from, $product) as $row) {
            $serial = $row->getSerial();
            if ($serial === null || $serial === '') {
                continue;
            }

            $key = $row->getProduct()->getId() . '|' . $serial;
            if (!isset($bySerial[$key])) {
                $bySerial[$key] = [
                    'serial' => $serial,
                    'sku' => $row->getProduct()->getSku(),
                    'product' => $row->getProduct()->getName(),
                    'lot' => $row->getLot()?->getCode(),
                    'available' => QuantityScale::canonical(0),
                    'label' => '',
                ];
            }

            $bySerial[$key]['available'] = QuantityScale::add($bySerial[$key]['available'], $row->getQuantity());
        }

        $choices = [];
        foreach ($bySerial as $choice) {
            $choice['label'] = sprintf(
                'S/N %s%s · %s available',
                $choice['serial'],
                $choice['lot'] === null ? '' : ' · ' . $choice['lot'],
                (new DisplayNumber())->qty($choice['available']),
            );
            $choices[] = $choice;
        }

        return $choices;
    }

    /**
     * How many units the source can send on exactly this (product, lot, serial) combination.
     *
     * A null lot means "any lot, including none", which is what a line with no lot named will
     * ultimately draw on — so the figure shown beside such a line is the product's whole available
     * quantity at the source, not zero.
     */
    public function available(Warehouse $from, ProductCore $product, ?InventoryLot $lot, ?string $serial): string
    {
        $total = QuantityScale::canonical(0);

        foreach ($this->rows($from, $product) as $row) {
            if ($lot instanceof InventoryLot && $row->getLot()?->getId() !== $lot->getId()) {
                continue;
            }
            if ($serial !== null && $serial !== '' && $row->getSerial() !== $serial) {
                continue;
            }

            $total = QuantityScale::add($total, $row->getQuantity());
        }

        return $total;
    }

    /**
     * Every lot of this product at the source whose CODE matches the typed pattern.
     *
     * The caller decides what to do with a list of two or more: this returns the matches rather than
     * guessing, because picking one on the operator's behalf is how a line ends up naming a batch
     * nobody meant.
     *
     * @return list<array{id: int, sku: string, product: string, code: string, expiry: ?string, available: string, label: string}>
     */
    public function matchLots(Warehouse $from, ProductCore $product, string $pattern): array
    {
        $matches = [];
        foreach ($this->lotChoices($from, $product) as $choice) {
            if (self::matches($pattern, $choice['code'])) {
                $matches[] = $choice;
            }
        }

        return $matches;
    }

    /**
     * Every serial of this product at the source matching the typed pattern.
     *
     * @return list<array{serial: string, sku: string, product: string, lot: ?string, available: string, label: string}>
     */
    public function matchSerials(Warehouse $from, ProductCore $product, string $pattern): array
    {
        $matches = [];
        foreach ($this->serialChoices($from, $product) as $choice) {
            if (self::matches($pattern, $choice['serial'])) {
                $matches[] = $choice;
            }
        }

        return $matches;
    }

    /** `LOT-SEA-2609 · exp 2026-09-10 · 40 available` — a lot named the way a human recognises it. */
    public function lotLabel(string $code, ?string $expiry, string|int|float $available): string
    {
        return sprintf(
            '%s%s · %s available',
            $code,
            $expiry === null ? ' · no expiry' : ' · exp ' . $expiry,
            (new DisplayNumber())->qty($available),
        );
    }

    /**
     * Whether a typed pattern names this string.
     *
     * Case-insensitive throughout: nobody types a batch code in the case it was stored in. A pattern
     * with no wildcard in it is a substring match — `SEA` finds `LOT-SEA-2609`, which is what typing
     * three characters into a search box has meant everywhere else for thirty years.
     */
    public static function matches(string $pattern, string $subject): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        $wild = strpbrk($pattern, '*%?_') !== false;
        if (!$wild) {
            return stripos($subject, $pattern) !== false;
        }

        // Everything is quoted first and the wildcards are put back afterwards, so a pattern
        // containing regex syntax — a batch code with a `.` or a `+` in it is ordinary — is matched
        // literally rather than compiled as an expression.
        $regex = preg_quote($pattern, '/');
        $regex = str_replace(['\*', '%', '\?', '_'], ['.*', '.*', '.', '.'], $regex);

        return preg_match('/^' . $regex . '$/i', $subject) === 1;
    }

    /**
     * The sellable `inventory_detail` rows at one warehouse, optionally for one product.
     *
     * Ordered so the lists read in a stable order: by SKU, then by expiry with the undated batches
     * last, then by row id. The undated-last rule is `pickableRows()`'s, kept in step deliberately —
     * two screens that disagree about which batch comes first is how an operator learns not to trust
     * either.
     *
     * @return list<InventoryDetail>
     */
    private function rows(Warehouse $from, ?ProductCore $product): array
    {
        $qb = $this->em->getRepository(InventoryDetail::class)->createQueryBuilder('d')
            ->innerJoin('d.product', 'p')->addSelect('p')
            ->leftJoin('d.lot', 'l')->addSelect('l')
            // DQL cannot ORDER BY a function call directly ("Expected known function" out of the DQL
            // parser for anything past a bare state field path), so the coalesced date is selected
            // HIDDEN and ordered by alias, same as InventoryDetailRepository::pickableRows().
            ->addSelect('COALESCE(l.expiry, d.expiry) AS HIDDEN effectiveExpiry')
            ->addSelect('CASE WHEN COALESCE(l.expiry, d.expiry) IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $from)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.quantity > 0')
            ->orderBy('p.sku', 'ASC')
            ->addOrderBy('undated', 'ASC')
            ->addOrderBy('effectiveExpiry', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if ($product instanceof ProductCore) {
            $qb->andWhere('d.product = :product')->setParameter('product', $product);
        }

        /** @var list<InventoryDetail> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
