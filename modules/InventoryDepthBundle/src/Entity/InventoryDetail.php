<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * Where one product's stock physically is, one row per distinct
 * `(product, warehouse, location, lot, serial, status)` combination (#550).
 *
 * ## The invariant
 *
 *     product_inventory(product, warehouse).quantity
 *       == SUM(inventory_detail.quantity) WHERE status = 'available' AND warehouse = that warehouse
 *
 * Only `available` counts. Every other status is stock that is physically present or accounted for
 * but not sellable, and the core number has never included it. Maintained in the same transaction
 * as the movement that changes it by InventoryDepthBundle\Movement\StockMovementService — never
 * derived on read, which is what makes the bundle removable without touching a single number.
 *
 * ## Rules
 *
 * 1. **A row's identity never changes — only its `quantity` moves.** Rows are never deleted; a row
 *    at 0 is history, and the movement ledger that emptied it still points at it.
 * 2. **A movement moves quantity from one row to another.** `from = NULL` means entering the
 *    system, `to = NULL` means leaving it. Serialized and non-serialized stock use the identical
 *    code path; a serial simply never exceeds 1.
 * 3. **Sold, scrapped and lost rows keep their warehouse but drop their location.** That is what
 *    makes "which site shipped it" and "where was it lost" answerable, and why `location` is
 *    nullable. Those rows are also terminal and shared: shipping the same lot to two customers
 *    grows ONE row rather than making two. The row answers "how much"; only
 *    InventoryMovementGroup::$reference answers "to whom", which is why `reference` is required on
 *    ship groups and why a recall is a ledger query.
 *
 * ## What guards this at the database level, and what does not
 *
 * The migration adds three things Doctrine's mapping layer cannot express, and which therefore
 * exist only in a migrated database, not in the schema either test suite builds from metadata:
 *
 *   - `uniq_inventory_detail` over `COALESCE(location_id,0), COALESCE(lot_id,0), COALESCE(serial,'')`
 *     — a plain unique index would not collapse NULLs, which SQLite treats as distinct.
 *   - `uniq_live_serial` — partial, `WHERE serial IS NOT NULL AND quantity > 0`. This is what makes
 *     double-selling a serial impossible at the database level. Note what it does NOT do: it
 *     constrains how many ROWS a serial may have live, never the quantity sitting on one of them.
 *     "A serial row is quantity 1" is therefore enforced in setQuantity()/setSerial() below — see
 *     the note there for why that is an application guard and not a CHECK, and
 *     carriesPlaceholderSerial() for why a sentinel row is exempt from it.
 *   - `CHECK (quantity >= 0)`, dropped again by Version20260827100000 so a short count can go
 *     negative on the sentinel row.
 *
 * Because those are invisible to the tests, the *enforcing* layer is the service, not the schema:
 * InventoryDetailRepository::findOrCreate() is the only way a row is made (so the uniqueness is
 * upheld by lookup, in both schemas), and the decrement is a conditional
 * `UPDATE ... WHERE id = :id AND quantity >= :n` whose affected-row count is checked (so a
 * concurrent over-draw becomes "someone got there first" rather than a constraint exception in a
 * picker's face). The database guards are the backstop, deliberately, in that order.
 */
#[ORM\Entity(repositoryClass: InventoryDetailRepository::class)]
#[ORM\Table(name: 'inventory_detail')]
#[ORM\Index(name: 'idx_detail_lookup', fields: ['product', 'warehouse', 'status'])]
#[ORM\Index(name: 'idx_detail_location', fields: ['location'])]
#[ORM\Index(name: 'idx_detail_serial', fields: ['serial'])]
class InventoryDetail
{
    /** The one status the core number counts. */
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_QUARANTINE = 'quarantine';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SOLD = 'sold';
    public const STATUS_SCRAPPED = 'scrapped';
    public const STATUS_LOST = 'lost';

    /**
     * Sent back by a customer and not yet inspected.
     *
     * Summed into `quarantine_quantity` alongside `quarantine`, because that is what it is in
     * substance: goods physically on the shelf that nobody has yet ruled fit to sell again.
     *
     * It keeps its own status rather than simply being written as `quarantine` so that a returned
     * unit stays distinguishable from one that failed inspection. Same bucket, different
     * provenance — and the bucket is the only part availability cares about.
     *
     * DOCUMENT-BACKED since #596 — see documentBackedStatuses(). A `sales_return` receipt writes
     * these rows, or a standalone credit note's restock flag does (#586); an adjustment does not.
     */
    public const STATUS_RETURNED = 'returned';

    /**
     * Shipped back to a supplier (#638) — the mirror of STATUS_RETURNED, direction reversed. By the
     * time `VendorReturnShipService` writes this status the goods have already left the building in
     * that same act, unlike a customer return: there is no "sitting in a bin pending a ruling"
     * phase here the way `quarantine`/`returned` have. So it joins SOLD/SCRAPPED/LOST in
     * writeOffStatuses() AND terminalStatuses() below, not quarantineStatuses() — see both for why.
     *
     * Folded into the existing `write_off` bucket rather than given a bucket of its own: "gone,
     * unsellable BY US, for good" is true regardless of whether the reason is damage or a
     * commercial return, and a dedicated column would need its own `product_inventory` schema
     * change for a distinction that bucket-level availability accounting has no need to draw. The
     * money recovered for these units — none for scrap, a debit memo for a vendor return — is a
     * fact `DebitMemo`/`VendorBill` carry; it is not something the stock ledger has ever tracked for
     * any write-off reason, and this one is no exception.
     */
    public const STATUS_RETURNED_TO_VENDOR = 'returned_to_vendor';

    /**
     * Gone or unsellable for good. Summed into the `write_off` bucket (#581).
     *
     * @return list<string>
     */
    public static function writeOffStatuses(): array
    {
        return [
            self::STATUS_DAMAGED,
            self::STATUS_EXPIRED,
            self::STATUS_SCRAPPED,
            self::STATUS_LOST,
            self::STATUS_RETURNED_TO_VENDOR,
        ];
    }

    /**
     * Physically present and countable, unsellable until somebody rules on them, and they may come
     * back. Summed into the `quarantine` bucket (#581). A return is quarantine in substance: goods
     * on the shelf that nobody has yet said are fit to sell again.
     *
     * @return list<string>
     */
    public static function quarantineStatuses(): array
    {
        return [
            self::STATUS_QUARANTINE,
            self::STATUS_RETURNED,
        ];
    }

    /**
     * Billed to a customer, or picked to be. Deliberately in NO bucket (#581).
     *
     * The invoice that billed the units holds them in `pending`/`approved`, and that hold never
     * releases, because this app has no mechanism that decrements Starting Inventory on shipment.
     * A bucket here would subtract the same units twice — SellingADimensionalProductTest conducts
     * the sale and shows 25 sellable of 30, not 20.
     *
     * @return list<string>
     */
    public static function soldStatuses(): array
    {
        return [
            self::STATUS_SOLD,
        ];
    }

    /**
     * Statuses whose units are ALREADY subtracted from availability by something other than the
     * `received` bucket (#581).
     *
     * Availability is `quantity + received` less the buckets less the holds. A movement that takes
     * stock out of `available` therefore has to be recorded exactly once, and which side records it
     * depends on where the stock went:
     *
     *   in_transit                       → the transfer ORDER, through `transfer_out`/`transfer_in`
     *   damaged, expired, scrapped, lost → the `write_off` bucket, recomputed from these rows
     *   quarantine, returned             → the `quarantine` bucket, likewise
     *   sold                             → the invoice that billed the units
     *   anywhere else (a plain withdrawal, stock leaving the ledger entirely) → nobody, so
     *                                      `received` has to absorb it
     *
     * A movement crossing between `available` and one of the statuses below must NOT also move
     * `received`, or the same units come off twice. A movement crossing to anywhere else must.
     *
     * Composed from the groups above rather than restated, so a status added to one of them cannot
     * be left out of here — the silent failure this list exists to prevent.
     *
     * @return list<string>
     */
    public static function statusesAccountedForElsewhere(): array
    {
        return array_merge(
            self::statusesAccountedForAtBothEnds(),
            self::writeOffStatuses(),
            self::quarantineStatuses(),
            self::soldStatuses(),
        );
    }

    /**
     * The subset of the list above whose accounting is NOT per warehouse — it follows the DOCUMENT,
     * so BOTH ends of a crossing into or out of it are already recorded, wherever they are (#584).
     *
     * Every other entry above is a bucket summed per (product, warehouse) from the detail rows in
     * THIS warehouse. So `available → damaged` only stops moving `received` when the damaged row is
     * in the same warehouse; if it were somewhere else, this warehouse's `write_off` would not have
     * grown and nothing here would have recorded the departure. That same-warehouse test is
     * load-bearing and #581 added it on purpose.
     *
     * `in_transit` is the exception, and since #584 it is the only one. A transfer order records
     * itself at both ends — `transfer_out` at the warehouse named FROM, `transfer_in` at the one
     * named TO — from `transfer_order_line`, not from the detail rows. Its far side is therefore
     * accounted for whichever warehouse it sits in, which matters because an arriving transfer's
     * far side is `in_transit` at the SOURCE, a different warehouse by definition. Requiring the
     * same warehouse there would credit the destination's `received` as well as its `transfer_in`
     * and count the arrival twice.
     *
     * `sold` and `staged` are NOT here even though a document backs those too, because the document
     * holds them per warehouse: an invoice's units sit in `pending`/`approved` on one
     * `product_inventory` row. A crossing from `available` here into `sold` somewhere else would be
     * unrecorded at this end, and the same-warehouse test correctly makes `received` absorb it.
     *
     * @return list<string>
     */
    public static function statusesAccountedForAtBothEnds(): array
    {
        return [self::STATUS_IN_TRANSIT];
    }

    /**
     * Statuses an adjustment must never create, because each one is the footprint of a business
     * document (#581).
     *
     * An adjustment screen records what physically happened to stock that is still yours: it broke,
     * it expired, it went to quarantine, it was thrown away. It is not a way to author the outcome
     * of a transaction nobody performed. Every status below is reached by doing the actual thing:
     *
     *   sold       through the invoice that bills the units
     *   in_transit through the transfer order that dispatches them
     *
     *   returned   through the sales return (RMA) the customer shipped the goods back against
     *
     * Each of those leaves a document behind, and downstream figures — the sell side of the ledger,
     * the transfer's receiving screen, the invoice's own hold, the RMA's own receipt — read that
     * document, not this row. Writing the status here produces stock that is sold with nothing
     * billing it, or returned with nothing authorising the return.
     *
     * Composed from soldStatuses() rather than naming `sold` again, because the two lists answer
     * the same question about that status from opposite ends: soldStatuses() says a document
     * already accounts for those units, so no bucket may subtract them a second time, and this
     * list says that same document is the only thing allowed to put a row there in the first
     * place. A status that ever gains a bucket has to lose its exemption here in the same edit,
     * and composing is what makes that impossible to forget.
     *
     * `in_transit` and `returned` are added rather than derived, because each is a document-backed
     * status that DOES get a bucket — `transfer_out` for the first, recomputed from these very rows
     * (#574), and `quarantine` for the second (#581) — so both fail the soldStatuses() test while
     * still being a document's to write. The two lists overlap; neither contains the other, and
     * collapsing them into one would force a choice between double-subtracting in-transit stock and
     * letting an adjustment fake a dispatch.
     *
     * `returned` joined them in #596, and the join is the point of that issue rather than a detail
     * of it. Until #586 nothing produced a `returned` row at all, so leaving it adjustable cost
     * nothing and #586's own report said so while declining to move it — #585's screen was in
     * flight and it was another agent's file. Now `sales_return` produces them: the RMA is
     * authorised, the goods arrive, `SalesReturn::receive()` writes the rows, and the units sit in
     * `quarantine` until somebody rules on them. An adjustment writing `returned` after that is
     * stock returned against no RMA — the same forgery as a sale with no invoice, in a new place.
     *
     * **The honest cost, and it is a real one.** AdjustmentController checks this list on BOTH
     * sides, so `returned` is now unreachable as a SOURCE too, and no shipped reason has ever named
     * it as one. "Release from hold" moves `quarantine → available` — the status, not the bucket —
     * so it never could touch a `returned` row, before this issue or after it. That gap is #596's
     * to report and not to paper over: adding a `returned → available` reason here would put the
     * ruling back on a screen that cannot see which RMA the units came in on, which is the shape
     * this list exists to prevent. The RMA is where that ruling belongs and #596 does not build it.
     *
     * An earlier draft of this docblock paired `sold` with `staged` and called the pair "exactly
     * soldStatuses()". `staged` has since been deleted outright — nothing in the app ever wrote it,
     * so no row could hold it — leaving soldStatuses() a single status. The composition survived
     * that deletion without an edit, which is the argument for composing restated as evidence.
     *
     * @return list<string>
     */
    public static function documentBackedStatuses(): array
    {
        // STATUS_RETURNED_TO_VENDOR joins STATUS_RETURNED here for the same reason: an adjustment
        // records what physically happened to stock that is still yours, and goods that left for a
        // supplier did so through VendorReturn's own authorise/ship transitions (#638), not through
        // a screen that cannot see which vendor return authorised the departure.
        return array_merge(self::soldStatuses(), [self::STATUS_IN_TRANSIT, self::STATUS_RETURNED, self::STATUS_RETURNED_TO_VENDOR]);
    }

    /**
     * The destinations an adjustment MAY write: everything a document does not own.
     *
     * Derived by subtraction, so a status added to statuses() is offered here automatically unless
     * it is deliberately claimed by a document — the safe default being that a physical state is
     * something a warehouse can record.
     *
     * @return list<string>
     */
    public static function adjustableDestinations(): array
    {
        return array_values(array_diff(self::statuses(), self::documentBackedStatuses()));
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_AVAILABLE,
            self::STATUS_IN_TRANSIT,
            self::STATUS_DAMAGED,
            self::STATUS_QUARANTINE,
            self::STATUS_EXPIRED,
            self::STATUS_SOLD,
            self::STATUS_SCRAPPED,
            self::STATUS_LOST,
            self::STATUS_RETURNED,
            self::STATUS_RETURNED_TO_VENDOR,
        ];
    }

    /**
     * Statuses that describe stock which has left the building or will never leave it as sellable
     * goods. These drop their location on the way in — see rule 3.
     *
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [self::STATUS_SOLD, self::STATUS_SCRAPPED, self::STATUS_LOST, self::STATUS_RETURNED_TO_VENDOR];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    /** Null means "in this building, bin unspecified" — an opening balance, or a terminal row. */
    #[ORM\ManyToOne(targetEntity: WarehouseLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WarehouseLocation $location = null;

    #[ORM\ManyToOne(targetEntity: InventoryLot::class)]
    #[ORM\JoinColumn(name: 'lot_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InventoryLot $lot = null;

    /**
     * The last usable day, for a row with no lot to carry it (#795).
     *
     * A lot row keeps its expiry on `InventoryLot::$expiry` and this stays NULL for it — the two are
     * never both real for the same row, which is what lets every reader say `COALESCE(l.expiry,
     * d.expiry)` without having to judge which one wins. `setExpiry()` is the guard: it refuses to
     * put a date here while a lot is already set.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiry = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_AVAILABLE;

    /**
     * A decimal STRING since `App\Doctrine\Type\QuantityType` became a decimal type — `"0.4000"`,
     * not `0`. The column has been `NUMERIC(14, 4)` since #645; what changed is that PHP now reads
     * what it holds instead of an `(int)` of it.
     */
    #[ORM\Column(type: 'quantity', options: ['default' => 0])]
    private string $quantity = '0.0000';

    /**
     * Whether anybody is expected to come back and put a real identity on this row (#573).
     *
     * **On the row, not on the policy**, and that is the whole point. Whether a sentinel will ever
     * be resolved is not a property of the product class and is not inferable from direction: an
     * outbound sentinel is permanent if you never scan, and a to-do if you scan at delivery instead
     * of at pick. It varies case by case, so it is set by whoever wrote the row and the worklist
     * filters on it.
     *
     * Only meaningful on a row whose identity is a placeholder — a sentinel value, in either
     * dimension, or a NULL dimension the product's policy says should carry something. On any other
     * row it is noise, and nothing sets it there.
     */
    #[ORM\Column(name: 'expect_resolution', options: ['default' => false])]
    private bool $expectResolution = false;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getLocation(): ?WarehouseLocation { return $this->location; }
    public function setLocation(?WarehouseLocation $location): self { $this->location = $location; return $this; }
    public function getLot(): ?InventoryLot { return $this->lot; }
    public function setLot(?InventoryLot $lot): self { $this->lot = $lot; return $this; }
    public function getExpiry(): ?\DateTimeImmutable { return $this->expiry; }

    /**
     * @throws \InvalidArgumentException when the row already carries a lot — that lot's own expiry
     *         is the row's answer, and a second one here would give a reader two disagreeing dates
     *         to choose between instead of one COALESCE with nothing to resolve.
     */
    public function setExpiry(?\DateTimeImmutable $expiry): self
    {
        if ($expiry !== null && $this->lot !== null) {
            throw new \InvalidArgumentException(
                'This row already carries a lot, and a lot\'s expiry belongs to the lot. Set it there instead.',
            );
        }

        $this->expiry = $expiry;

        return $this;
    }

    public function getSerial(): ?string { return $this->serial; }
    /**
     * The same ceiling as setQuantity(), from the other side: a row already holding 40 cannot become
     * a serial — unless the value being put on it is the policy's sentinel, which is a label on the
     * unidentified row and not a serial number. See carriesPlaceholderSerial().
     */
    public function setSerial(?string $serial): self
    {
        $serial = ($serial === null || $serial === '') ? null : $serial;

        if ($serial !== null && $this->quantity > 1 && !$this->isPlaceholderSerial($serial)) {
            throw new \InvalidArgumentException(sprintf(
                'A row holding %d units cannot be given serial %s. A serial identifies one unit.',
                $this->quantity,
                $serial,
            ));
        }

        $this->serial = $serial;

        return $this;
    }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = \in_array($status, self::statuses(), true) ? $status : self::STATUS_AVAILABLE;

        return $this;
    }

    public function getQuantity(): string { return $this->quantity; }

    /**
     * Only StockMovementService and the import reconciler should reach this, and the movement path
     * only inside a movement.
     *
     * **No longer clamped at 0 (#565).** A short count has to be able to write a negative onto the
     * sentinel row: a file declaring 40 against bins holding 47 leaves A = 40, B = 7,
     * sentinel = −7, so the total is right immediately and the missing seven are a visible number
     * rather than a silent deletion. Clamping here would quietly delete exactly the discrepancy the
     * design exists to surface, which is why the database CHECK went with it.
     *
     * What actually stops an over-draw is unchanged and is the guard that matters: the conditional
     * `UPDATE ... WHERE quantity >= :n` in InventoryDetailRepository::decrement(), which refuses on
     * zero affected rows. A pick still cannot go below zero. The only door down is a declared count.
     *
     * **Upwards there is now a ceiling, and only for a serial (#573).** A serial names one physical
     * unit, so a row carrying one may never hold more than 1 — that is what makes a serial a serial
     * rather than a lot, and Dynamics BC, AX, NetSuite and Zoho all enforce it. Nothing did here:
     * `uniq_live_serial` constrains the number of ROWS a serial may have live, never the quantity on
     * them, so a single row reading `S/N ABC123 × 40` satisfied every guard in the schema.
     *
     * Enforced in this setter rather than as a database CHECK deliberately. Both test suites build
     * their schema from entity metadata and never run a migration, so a CHECK would be invisible to
     * every test in the codebase; SQLite 3.26 cannot add one without rebuilding `inventory_detail`,
     * which Version20260827100000 shows is the delicate operation in this table; and a constraint
     * violation surfaces as a driver exception in a picker's face rather than a sentence they can
     * act on. This is the same order of preference the class docblock above already states: the
     * enforcing layer is the application, the database guards are the backstop.
     *
     * **A placeholder is exempt.** The rule is about genuine serial numbers, each of which
     * identifies one physical unit. A sentinel identifies none — it is a label saying "these units
     * are not identified yet" — so a row wearing one holds however many unidentified units arrived.
     * See carriesPlaceholderSerial().
     */
    public function setQuantity(string|int|float $quantity): self
    {
        // `string|int|float` rather than `string` so that the several hundred call sites handing
        // this a plain `int` are unchanged, and spelt at the column's own scale so one figure has
        // one spelling on the row. How many places a store actually KEEPS is
        // QuantityScale::round(), applied where the figure enters the application.
        $quantity = QuantityScale::canonical($quantity);

        if ((float) $quantity > 1.0 && $this->serial !== null && !$this->isPlaceholderSerial($this->serial)) {
            throw new \InvalidArgumentException(sprintf(
                'Serial %s cannot hold %s units. A serial identifies one unit, so it is one row of 1 per unit.',
                $this->serial,
                $quantity,
            ));
        }

        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Whether this row's serial is a placeholder rather than an identity (#573).
     *
     * A sentinel is a **label on the unidentified row**. It is not a real serial number, so it names
     * no physical unit and the "a serial row is quantity 1" ceiling does not apply to it: receiving
     * N unidentified units produces one row of quantity N wearing the label, exactly as lot mode
     * produces one row of quantity N wearing the sentinel batch code.
     *
     * Two shapes, and they are the same row seen through a policy that names a value and one that
     * does not:
     *
     *  - the serial IS the policy's `sentinel_in`;
     *  - the serial is NULL, which is what a blank `sentinel_in` writes, on a serial-mode row that
     *    carries `expect_resolution`.
     *
     * `uniq_live_serial` is satisfied either way: one live row per serial value, and there is one
     * row.
     */
    public function carriesPlaceholderSerial(): bool
    {
        return $this->isPlaceholderSerial($this->serial);
    }

    public function isExpectResolution(): bool { return $this->expectResolution; }
    public function setExpectResolution(bool $expectResolution): self { $this->expectResolution = $expectResolution; return $this; }

    public function isAvailable(): bool { return $this->status === self::STATUS_AVAILABLE; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /**
     * carriesPlaceholderSerial(), asked about a value that is not on the row yet — setSerial() has
     * to judge the incoming value, setQuantity() the one already stored.
     *
     * `$product` is a typed property with no default, so it is read through isset(): a detached row
     * being assembled field by field has no product to ask, and no product means no policy, which
     * means no sentinel and therefore no placeholder.
     */
    private function isPlaceholderSerial(?string $serial): bool
    {
        $policy = isset($this->product) ? $this->product->getTrackingPolicy() : null;
        if (!$policy instanceof TrackingPolicy) {
            return false;
        }

        if ($policy->isSentinelIn($serial)) {
            return true;
        }

        return $serial === null
            && $policy->getMode() === TrackingPolicy::MODE_SERIAL
            && $this->expectResolution;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        $parts = [$this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')), $this->warehouse->getName()];

        if ($this->location !== null) {
            $parts[] = $this->location->getCode();
        }
        if ($this->lot !== null) {
            $parts[] = $this->lot->getLabel();
        }
        if ($this->serial !== null) {
            $parts[] = 'S/N ' . $this->serial;
        }

        return sprintf('%s [%s]', implode(' / ', $parts), $this->status);
    }
}
