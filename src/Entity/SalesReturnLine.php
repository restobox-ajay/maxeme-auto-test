<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * One kind of goods coming back on one RMA (#596).
 *
 * ## What is deliberately NOT here: bin, lot and serial
 *
 * This is the single most important thing about the class. A returning parcel's contents are not
 * known until somebody opens it, and even then "which bin did it go into" is a fact about the
 * receipt, not about the authorisation — the same three units can be authorised in March and land
 * in a different building in May. `inventory_detail` already records exactly that, keyed by
 * (product, warehouse, bin, lot, serial, status), and `inventory_movement` records the act that put
 * it there.
 *
 * Copying those dimensions onto this row would give two answers to "where are the returned units",
 * one of them frozen at authorisation time, and the ledger would be the one nobody looked at. It is
 * the same argument InventoryReservationSubject makes about a second copy of a reservation, and the
 * same argument `purchase_receipt` settled on the buying side: the DOCUMENT says what was agreed,
 * the MOVEMENT says what physically happened.
 *
 * ## $reason is per line, not per document
 *
 * A customer returns three things for three reasons — one arrived broken, one was the wrong size,
 * one they simply do not want. A single reason on the header would force whoever raises the RMA to
 * pick the most dramatic of the three or write a paragraph, and neither is reportable. The header
 * keeps its own $reason for the case where one sentence really does cover the parcel; the two are
 * not exclusive and neither is derived from the other.
 *
 * ## $disposition moves NO STOCK, on purpose
 *
 * It is what the person who opened the box SAW, recorded at receipt so the person who rules on the
 * units later is not guessing from a photograph. It is not an instruction and nothing acts on it.
 *
 * That restraint is deliberate and #596 is explicit about it. Receiving a return puts every unit in
 * `returned` — which sums into `quarantine_quantity` and is not sellable — whatever the receiver
 * wrote here, because a receiving clerk noting "looks fine" is not the same act as a business
 * deciding those units may be sold to somebody else. A disposition that silently routed units to
 * `available` would make the receiving dock the last word on saleability, which is exactly the
 * inspection step #581 and #585 built the `quarantine` bucket to preserve.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_return_line')]
#[ORM\Index(name: 'idx_sales_return_line_unit', fields: ['unitOfMeasure'])]
class SalesReturnLine implements DenominatedLine
{
    use DenominatedQuantity;

    /**
     * Received in the condition described and expected to be sellable again. Records an observation;
     * puts nothing back on the shelf. See the class docblock.
     */
    public const DISPOSITION_RESTOCK = 'restock';

    /** Arrived broken. */
    public const DISPOSITION_DAMAGED = 'damaged';

    /** Arrived, and there is nothing to do with it but throw it away. */
    public const DISPOSITION_SCRAP = 'scrap';

    /** Arrived and somebody needs to look at it properly before anyone says anything. */
    public const DISPOSITION_INSPECT = 'inspect';

    /**
     * The four an operator may record, and the ONE place they are written down.
     *
     * A list rather than a PHP enum, unlike SalesReturnStatus. The difference is that a status
     * drives transitions and is compared in a dozen places, while this is a label on a row that
     * nothing branches on — and an enum column would refuse a value an instance later wants to add
     * for its own trade, forcing a migration for a vocabulary change.
     *
     * @return list<string>
     */
    public static function dispositions(): array
    {
        return [
            self::DISPOSITION_RESTOCK,
            self::DISPOSITION_DAMAGED,
            self::DISPOSITION_SCRAP,
            self::DISPOSITION_INSPECT,
        ];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesReturn::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'sales_return_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesReturn $salesReturn;

    /**
     * NOT NULL, unlike CreditMemoLine::$product.
     *
     * A credit note line may be freight, a restocking fee or a pricing correction — money with no
     * SKU behind it — so its product is optional. An RMA line is goods travelling in a box. A row
     * here with no product would be a line the receipt could not receive and the ledger could not
     * record, sitting on a document whose entire subject is physical goods.
     */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /**
     * The billed row these units came off, when the RMA was raised from an invoice.
     *
     * Nullable, and the nullability is a case rather than a convenience: goods come back against an
     * invoice nobody can find, against an order invoiced in three parts, or with no paperwork at
     * all. SET NULL rather than CASCADE for InvoiceLine::$salesOrderLine's reason — losing the
     * attribution must never delete the record that the goods came back.
     *
     * Nothing about the money is derived from it. This line does not credit anything; a credit note
     * raised against the same return does that, through its own lines, and may credit a different
     * amount for reasons this document has no opinion about (a restocking fee, a partial credit).
     */
    #[ORM\ManyToOne(targetEntity: InvoiceLine::class)]
    #[ORM\JoinColumn(name: 'invoice_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InvoiceLine $invoiceLine = null;

    /**
     * Decimal, like every other document line in this app, and converted to whole units at exactly
     * one place (getUnits()).
     *
     * An integer column was considered and rejected: an RMA line is very often raised from an
     * invoice line, whose quantity is `NUMERIC(14, 4)`, and copying a decimal into an integer
     * column at that point would round silently on the way in with nothing recording that it had.
     * Rounding once, visibly, in the method the movement layer calls is the same trade
     * CreditMemoLine makes.
     */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    /** Snapshot, so a renamed or deleted product does not erase what came back. */
    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    /** Why THIS row came back. See the class docblock for why it is not on the header alone. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /**
     * What the receiver saw. NULL until receipt, and NULL after it too when nobody said.
     *
     * Moves no stock — see the class docblock, which is where the argument lives.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $disposition = null;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getSalesReturn(): SalesReturn { return $this->salesReturn; }
    public function setSalesReturn(SalesReturn $salesReturn): self { $this->salesReturn = $salesReturn; return $this; }

    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }

    public function getInvoiceLine(): ?InvoiceLine { return $this->invoiceLine; }
    public function setInvoiceLine(?InvoiceLine $invoiceLine): self { $this->invoiceLine = $invoiceLine; return $this; }

    public function getQuantity(): string { return $this->quantity; }
    /**
     * Restating the base figure forgets how it was entered (#601, #646).
     *
     * Writing this column directly says "the row is this many BASE units", and the only
     * truthful entered figure for that is the same number in base units — which is what
     * `quantity_entered` NULL and `unit_id` NULL mean. See DenominatedQuantity.
     */
    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        $this->forgetEnteredExpression();

        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }

    public function getDisposition(): ?string { return $this->disposition; }

    /**
     * Recorded at receipt, and refused if it is not one of the four.
     *
     * A silent coercion — the shape DetailKey uses for an unrecognised status — would be wrong here
     * for the opposite reason it is right there: DetailKey's fallback keeps a movement applying
     * against a slightly wrong row, whereas a mistyped disposition that quietly became `restock`
     * would tell the person ruling on the goods that somebody had inspected them and been happy.
     * That is a lie about a physical inspection, so it throws.
     */
    public function setDisposition(?string $disposition): self
    {
        $disposition = $disposition === null ? null : trim($disposition);
        if ($disposition === '') {
            $disposition = null;
        }

        if ($disposition !== null && !\in_array($disposition, self::dispositions(), true)) {
            throw new \DomainException(sprintf(
                '"%s" is not a disposition. A receiver records one of: %s.',
                $disposition,
                implode(', ', self::dispositions()),
            ));
        }

        $this->disposition = $disposition;

        return $this;
    }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /** The quantity, canonicalised and clamped at zero — never negative. */
    public function getUnits(): string
    {
        $quantity = QuantityScale::canonical($this->quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
