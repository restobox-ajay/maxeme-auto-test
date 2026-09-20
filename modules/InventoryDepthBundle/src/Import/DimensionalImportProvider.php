<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Import;

use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use App\Contract\Inventory\DimensionalImportProviderInterface;
use App\Contract\Inventory\DimensionalImportRefusal;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\TrackingPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * Reconciles an imported count against the detail rows (#565).
 *
 * One sentence governs every case: **the count is authoritative for the total; the identities catch
 * up afterwards.**
 *
 * Since #572 the count reaches this class through the core row rather than as a loose figure: the
 * import writes `product_inventory.quantity` from the file first, and the total this reconciles
 * against is `quantity + received`. The table below is unchanged in substance — the "file says"
 * column is what the core row is holding by the time these rows are written.
 *
 * | file says | rows on hand | result |
 * |---|---|---|
 * | 47, no bins | A = 40, B = 7 | sentinel = 0, A and B untouched — already reconciles |
 * | 40, no bins | A = 40, B = 7 | **sentinel = −7**, A and B untouched, total = 40 |
 * | A = 39, delete unspecified | A = 40, B = 7 | A = 39, B = 0 |
 * | A = 39, untouch unspecified | A = 40, B = 7 | A = 39, B = 7 |
 * | a total AND bin rows | anything | refused, product untouched, rest of the file imports |
 *
 * The negative row is the part that will read as a bug to whoever sees it next, and the instinct
 * will be to clamp it at zero. Clamping deletes the discrepancy: the total would then be wrong AND
 * nobody would be told. A negative sentinel says "seven units short, identities not yet known",
 * which is precisely what a count that got the total right and the identities wrong actually knows.
 * It clears itself the moment someone finds them.
 *
 * ## Where lot and serial come from now (#573)
 *
 * They are **declared**, by App\Entity\TrackingPolicy, and no longer inferred. This class used to
 * carry two helpers, `carriesLots()` and `carriesSerials()`, which asked whether any lot row or any
 * serialised detail row had ever existed for the product. Both are gone. The policy answers the
 * same question from a declaration, so a lot-tracked product that has never been received is still
 * lot-tracked and one stray serialised row no longer makes a product serialised forever.
 *
 * ## A sentinel is a label on the unidentified row
 *
 * **A blank identity on a tracked product takes the policy's `sentinel_in`, in lot mode and serial
 * mode alike.** Receiving N unidentified units produces ONE row of quantity N carrying the label,
 * with `expect_resolution` set. Never N rows, and never a suffixed value like `PENDING-1`.
 *
 * NULL and a string are the same row seen twice:
 *
 * | `sentinel_in` | the row |
 * |---|---|
 * | NULL | dimension NULL, quantity N, `expect_resolution = 1` |
 * | `[PENDING]` | dimension `[PENDING]`, quantity N, `expect_resolution = 1` |
 *
 * Same row, same quantity, same flag — the only difference is what the column reads.
 *
 * `uniq_live_serial` is satisfied either way, because it allows a product one live row per serial
 * VALUE and there is one row. The quantity-1 ceiling on a serial row does not apply, because that
 * rule is about genuine serial numbers, each of which identifies one physical unit; a label
 * identifies none. See InventoryDetail::carriesPlaceholderSerial().
 */
final class DimensionalImportProvider implements DimensionalImportProviderInterface
{
    public const SOURCE = 'InventoryDepthBundle';

    /**
     * A far-future expiry, not null, so an unknown date is obviously not a real one and sorts LAST
     * under FEFO rather than first. A null would make unknown-expiry stock look like the oldest
     * thing in the warehouse and get picked before genuinely dated stock.
     */
    public const UNKNOWN_EXPIRY = '9999-12-31';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryDetailRepository $details,
        private readonly TrackingPolicyRepository $policies,
        private readonly string $sentinelBin = '[PENDING]',
        private readonly string $sentinelLot = '[PENDING]',
    ) {
    }

    public function getSource(): string
    {
        return self::SOURCE;
    }

    public function availableTotal(ProductCore $product, Warehouse $warehouse): int
    {
        return $this->details->availableTotal($product, $warehouse);
    }

    /** @return list<string> */
    public function advancedColumns(): array
    {
        return ['bin', 'lot', 'serial', 'expiry'];
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return list<string>
     */
    public function applyDeclaration(
        ProductCore $product,
        Warehouse $warehouse,
        int $declaredTotal,
        array $rows,
        bool $deleteUnspecified,
    ): array {
        $warnings = $rows === []
            ? $this->applyTotalOnly($product, $warehouse, $declaredTotal)
            : $this->applyRows($product, $warehouse, $rows, $deleteUnspecified);

        // Flushed here rather than left to the caller. The importer batches its flushes, and a
        // declaration that is only half-written when the next product is reconciled would have that
        // product read a stale availableTotal() — the balancing figure would then be computed
        // against rows that are about to change.
        $this->em->flush();

        return $warnings;
    }

    /**
     * No bins in the file. Existing rows are left exactly as they are and the whole difference
     * lands on the sentinel row, negative if the count came in short.
     *
     * The sentinel is a PLUG, and it is worth writing the formula out because it is the only thing
     * this method does (#572):
     *
     *     [PENDING] = (quantity + received) − SUM(all OTHER available detail rows)
     *
     * Both terms are read after the core row has already been written by the import — `quantity`
     * straight from the file, `received` either cleared or left alone by the checkbox. Neither is
     * derived from the detail rows, so plugging the gap makes
     * `SUM(available detail) == quantity + received` come out true without that identity ever having
     * been the thing computing either side of it.
     *
     * It used to be solved from `$declaredTotal` instead, subtracting the sentinel's own previous
     * quantity back out of the sum. That was right only while `received` was itself a residual: the
     * moment the bucket holds a real accumulated delta, the file's figure alone is a term short, and
     * the row that has to absorb the difference is this one. `$declaredTotal` is now the core row's
     * `quantity`, which is exactly where it is read from.
     *
     * @return list<string>
     */
    private function applyTotalOnly(ProductCore $product, Warehouse $warehouse, int $declaredTotal): array
    {
        $sentinel = $this->sentinelRow($product, $warehouse);

        // The sentinel's own quantity is excluded, because it is the balancing figure being solved
        // for. Including it would make this depend on its previous value instead of on the count.
        $identified = QuantityScale::sub($this->details->availableTotal($product, $warehouse), $sentinel->getQuantity());
        $difference = QuantityScale::sub($this->coreHeld($product, $warehouse), $identified);

        $sentinel->setQuantity($difference)->touch();
        $this->em->persist($sentinel);

        if (QuantityScale::compare($difference, 0) >= 0) {
            return [];
        }

        return [sprintf(
            'Count for %s at %s came in %s short of the bins on record. The shortfall is on the "%s" row until the units are found.',
            $product->getSku() ?: ('#' . $product->getId()),
            $warehouse->getName(),
            LineDenomination::trimZeros(QuantityScale::sub(0, $difference)),
            $this->sentinelBin,
        )];
    }

    /**
     * Bins in the file. Those rows are the declaration; what happens to rows the file did not
     * mention is the caller's option.
     *
     * @param list<array<string, string>> $rows
     *
     * @return list<string>
     */
    private function applyRows(ProductCore $product, Warehouse $warehouse, array $rows, bool $deleteUnspecified): array
    {
        $touched = [];
        $policy = $this->policies->policyFor($product);

        foreach ($rows as $row) {
            $lotCode = trim($row['lot'] ?? '');
            $serial = trim($row['serial'] ?? '');

            // Tracking never blocks (#573). A file that names no identity for a tracked product
            // still imports; the row takes the policy's sentinel — or NULL, when the policy leaves
            // the sentinel blank — and is flagged for the worklist instead of being refused. A
            // warehouse mid-transition has real stock and no codes for any of it.
            $unresolved = ($lotCode === '' && $policy->tracksLotsInbound())
                || ($serial === '' && $policy->tracksSerialsInbound());

            // The same substitution in both dimensions, because `sentinel_in` means the same thing
            // in both: a label on the unidentified row. `(string) null` is '', which is how a blank
            // sentinel keeps reading as "carry NULL for that dimension" below.
            if ($lotCode === '' && $policy->tracksLotsInbound()) {
                $lotCode = (string) $policy->getSentinelIn();
            }
            if ($serial === '' && $policy->tracksSerialsInbound()) {
                $serial = (string) $policy->getSentinelIn();
            }

            $lot = $this->lot($product, $lotCode, $row['expiry'] ?? '');

            $detail = $this->details->findOrCreate(
                $product,
                $warehouse,
                $this->location($warehouse, $row['bin'] ?? ''),
                $lot,
                $serial !== '' ? $serial : null,
                InventoryDetail::STATUS_AVAILABLE,
                // Only meaningful with no lot: a lot row's own expiry is set by lot() above, and
                // InventoryDetail::setExpiry() refuses a date here alongside one (#795). A file
                // naming no batch for this row can still name a date — the column is independent of
                // whether the product carries lots at all.
                $lot === null ? $this->rowExpiry($row['expiry'] ?? '') : null,
            );

            // Flagged BEFORE the quantity is written, not after. On a blank sentinel the placeholder
            // is a NULL serial, and InventoryDetail recognises that shape only by this flag — so a
            // row told it holds five units first and that it is a placeholder second would be judged
            // a genuine serial on the way past the quantity-1 ceiling.
            if ($unresolved) {
                $detail->setExpectResolution(true);
            }
            $detail->setQuantity((int) ($row['quantity'] ?? '0'))->touch();
            $this->em->persist($detail);
            $touched[spl_object_id($detail)] = true;
        }

        if (!$deleteUnspecified) {
            return [];
        }

        $zeroed = 0;
        foreach ($this->availableRows($product, $warehouse) as $existing) {
            if (isset($touched[spl_object_id($existing)]) || $existing->getQuantity() === 0) {
                continue;
            }

            $existing->setQuantity(0)->touch();
            $this->em->persist($existing);
            $zeroed++;
        }

        return $zeroed === 0 ? [] : [sprintf(
            '%d bin row(s) for %s at %s were not in the file and were set to zero.',
            $zeroed,
            $product->getSku() ?: ('#' . $product->getId()),
            $warehouse->getName(),
        )];
    }

    /**
     * @return list<InventoryDetail>
     */
    private function availableRows(ProductCore $product, Warehouse $warehouse): array
    {
        return $this->em->getRepository(InventoryDetail::class)->findBy([
            'product' => $product,
            'warehouse' => $warehouse,
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
    }

    private function location(Warehouse $warehouse, string $code): ?WarehouseLocation
    {
        $code = trim($code) !== '' ? trim($code) : $this->sentinelBin;

        $existing = $this->em->getRepository(WarehouseLocation::class)->findOneBy([
            'warehouse' => $warehouse,
            'code' => $code,
        ]);
        if ($existing instanceof WarehouseLocation) {
            return $existing;
        }

        $location = (new WarehouseLocation())
            ->setWarehouse($warehouse)
            ->setCode($code)
            ->setType(WarehouseLocation::TYPE_PICK);
        $this->em->persist($location);
        // Flushed immediately because the very next thing that happens is findExisting() binding
        // it as a query parameter, and Doctrine refuses to bind an entity with no identifier.
        $this->em->flush();

        return $location;
    }

    /**
     * A lot is matched on (product, code, expiry) rather than (product, code).
     *
     * Vendors reuse batch codes across production runs with different expiry dates, so the code
     * alone does not identify a lot — matching on it would force two genuinely different batches
     * into one row and merge their stock.
     */
    private function lot(ProductCore $product, string $code, string $expiry): ?InventoryLot
    {
        // No code and nothing above substituted one: the row carries no batch, which is what every
        // row of an untracked product carries and always has.
        //
        // Until #573 this branch asked whether the product had ever HAD a lot and stamped [PENDING]
        // if so. That is the inference the tracking policy exists to replace: a lot-tracked product
        // that has never been received read as untracked, and one stray row made a product look
        // lot-tracked forever. The caller now decides from the declaration and passes the sentinel
        // in, so this method only ever resolves a code it was given.
        if (trim($code) === '') {
            return null;
        }

        $code = trim($code);
        $expiryDate = $this->expiry($expiry);

        foreach ($this->em->getRepository(InventoryLot::class)->findBy(['product' => $product, 'code' => $code]) as $candidate) {
            if ($candidate->getExpiry()?->format('Y-m-d') === $expiryDate->format('Y-m-d')) {
                return $candidate;
            }
        }

        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry($expiryDate);
        $this->em->persist($lot);
        // Same reason as the location above: it is about to be a query parameter.
        $this->em->flush();

        return $lot;
    }

    private function expiry(string $given): \DateTimeImmutable
    {
        $given = trim($given);
        if ($given === '') {
            return new \DateTimeImmutable(self::UNKNOWN_EXPIRY);
        }

        try {
            return new \DateTimeImmutable($given);
        } catch (\Exception) {
            // An unparseable date is an unknown date, not a failed row. The sentinel makes it
            // greppable, which is the whole point — the admin's worklist is one filter.
            return new \DateTimeImmutable(self::UNKNOWN_EXPIRY);
        }
    }

    /**
     * The file's own expiry column, for a row with no lot to carry it (#795).
     *
     * Deliberately not expiry()'s UNKNOWN_EXPIRY fallback: that sentinel exists so an unidentified
     * BATCH still sorts last under FEFO rather than first, and it is stamped precisely because a
     * lot row always has *some* expiry answer once #573's sentinel machinery runs. A lot-less row
     * has no such machinery — most of them are genuinely never going to carry a date — so a blank
     * column here means null, not a manufactured one. An unparseable date is treated as blank for
     * the same reason expiry() treats it as unknown rather than failing the row.
     */
    private function rowExpiry(string $given): ?\DateTimeImmutable
    {
        $given = trim($given);
        if ($given === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($given);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * `quantity + received` for one (product, warehouse) — what the core row says is on the shelf.
     *
     * Read back out of the database rather than passed in, so this reflects whatever the import has
     * already committed for this row and nothing the caller believes about it. A product/warehouse
     * pair with no core row at all holds nothing, which is the honest answer for a combination
     * neither the client's file nor this app has ever put a number against.
     */
    private function coreHeld(ProductCore $product, Warehouse $warehouse): string
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        if (!$row instanceof ProductInventory) {
            return QuantityScale::canonical(0);
        }

        return QuantityScale::add($row->getQuantity(), $row->getReceivedQuantity());
    }

    private function sentinelRow(ProductCore $product, Warehouse $warehouse): InventoryDetail
    {
        return $this->details->findOrCreate(
            $product,
            $warehouse,
            $this->location($warehouse, $this->sentinelBin),
            $this->lot($product, $this->sentinelLot, ''),
            null,
            InventoryDetail::STATUS_AVAILABLE,
        );
    }
}
