<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\ShortDatedReceiptRepository;

/**
 * A receipt line whose expiry date was wrong at the dock, accepted anyway, and why (items 68, 69).
 *
 * ## Two findings, one row
 *
 * | the row says                                   | when                                        |
 * |------------------------------------------------|---------------------------------------------|
 * | **the goods arrived expired**                   | `remaining_days < 0`                        |
 * | **the goods were short of the minimum**         | `minimum_days > remaining_days`, minimum > 0|
 *
 * Both can be true of one row, and neither needed a column: each is recoverable EXACTLY from the
 * two figures already stored, so a `kind` column would be a third fact derived from two — the shape
 * behind #589, #590, #591 and item 67. {@see self::isAlreadyExpired()} and
 * {@see self::isShortOfMinimum()} read them.
 *
 * One row rather than two because it was one decision: one pallet, one reason box, one person. An
 * already-expired pallet that is ALSO inside the minimum is not two things somebody accepted.
 *
 * ## Why the goods go on the shelf and this row is written instead of a refusal
 *
 * By the time anybody reads an expiry date the pallet is physically on the dock. Refusing the
 * RECORD does not refuse the PALLET — it makes real stock invisible, which is worse than the
 * short date: the goods are picked anyway, from a system that says they are not there. So a
 * short-dated line WARNS, and it is booked in the moment somebody says why. The reason is not
 * optional and is not a checkbox: "who decided this and on what grounds" is the only question
 * anyone asks a fortnight later, and a tick answers neither half of it.
 *
 * ## Why this is a stored row and the three-way match's exceptions are not
 *
 * `ProcurementBundle\Match\MatchLine` exceptions — billed-not-received, price and quantity
 * variance, the accrual list — are **derived at read time and never stored**, because every one of
 * them is recomputable from the documents: the bill, the order and the receipts are all still
 * there, so a table of findings would only be a cache that could go stale.
 *
 * This cannot be recomputed. The minimum in force can change, the reason exists nowhere else, and
 * the person who accepted the delivery is not derivable from anything. So it is written once, at
 * the moment of the decision, and it snapshots the figures the decision was made against rather
 * than pointing at settings that will move underneath it.
 *
 * That difference is also why these rows are NOT on the Exceptions worklist. That screen is a money
 * worklist — every row carries `amountAtRiskCents()`, the whole screen is sorted by it, and it is
 * worked by the AP desk. A short date has no amount and no bill; what it is worth is measured in
 * days and it is worked by the warehouse. Putting it there would need either a fabricated dollar
 * figure or a column the sort ignores.
 *
 * ## One per line
 *
 * `receipt_line_id` is unique. A receipt line carries at most one expiry date, so it can be short by
 * at most one amount; two rows about one line would be two answers to one question.
 *
 * ## Nothing in core reads this
 *
 * Same rule as every other table in this bundle. The FK is to `goods_receipt_line`, which is this
 * bundle's own, and it cascades: the exception is a fact ABOUT that line and is meaningless without
 * it. (Receipts are never deleted — a wrong one is VOIDED, which leaves the lines and this row
 * exactly where they are, still saying what was accepted and why.)
 */
#[ORM\Entity(repositoryClass: ShortDatedReceiptRepository::class)]
#[ORM\Table(name: 'procurement_short_dated_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_short_dated_receipt_line', fields: ['receiptLine'])]
#[ORM\Index(name: 'idx_short_dated_overridden_at', fields: ['overriddenAt'])]
class ShortDatedReceipt
{
    /** The minimum came from the global setting — no product override was set. */
    public const SOURCE_GLOBAL = 'global';

    /** The minimum came from this product's own override on `procurement_product_rule`. */
    public const SOURCE_PRODUCT = 'product';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: GoodsReceiptLine::class, inversedBy: 'shortDated')]
    #[ORM\JoinColumn(name: 'receipt_line_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GoodsReceiptLine $receiptLine;

    /** The last usable day these units actually carried. */
    #[ORM\Column(name: 'expiry', type: 'date_immutable')]
    private \DateTimeImmutable $expiry;

    /**
     * The minimum that applied AT THE MOMENT OF THE DECISION, snapshotted because it can change.
     *
     * **Zero is meaningful and is written on purpose.** It says no minimum was in force when this
     * was accepted — so the row was raised by the expiry trigger alone, and the reader knows the
     * threshold was not merely met but absent. A row that never asked and a row that asked and got
     * "none" are different facts.
     */
    #[ORM\Column(name: 'minimum_days', type: 'integer')]
    private int $minimumDays = 0;

    /**
     * Whole days of shelf life the goods had when they arrived, expiry counted as a usable day.
     *
     * Negative when the delivery was already expired on the dock, which is a real case and reads
     * correctly here rather than clamping to zero and losing how bad it was.
     */
    #[ORM\Column(name: 'remaining_days', type: 'integer')]
    private int $remainingDays = 0;

    /** Which setting the minimum came from — see the SOURCE_ constants. */
    #[ORM\Column(name: 'minimum_source', length: 16)]
    private string $minimumSource = self::SOURCE_GLOBAL;

    /** Why these goods were accepted anyway. Required — see the class docblock. */
    #[ORM\Column(name: 'reason', length: 255)]
    private string $reason = '';

    /** Who decided. Nullable for the same reason `goods_receipt.received_by` is: an import has no user. */
    #[ORM\Column(name: 'overridden_by', length: 180, nullable: true)]
    private ?string $overriddenBy = null;

    #[ORM\Column(name: 'overridden_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $overriddenAt;

    public function __construct()
    {
        $this->expiry = new \DateTimeImmutable();
        $this->overriddenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReceiptLine(): GoodsReceiptLine { return $this->receiptLine; }
    public function setReceiptLine(GoodsReceiptLine $receiptLine): self { $this->receiptLine = $receiptLine; return $this; }
    public function getExpiry(): \DateTimeImmutable { return $this->expiry; }
    public function setExpiry(\DateTimeImmutable $expiry): self { $this->expiry = $expiry; return $this; }
    public function getMinimumDays(): int { return $this->minimumDays; }
    public function setMinimumDays(int $minimumDays): self { $this->minimumDays = $minimumDays; return $this; }
    public function getRemainingDays(): int { return $this->remainingDays; }
    public function setRemainingDays(int $remainingDays): self { $this->remainingDays = $remainingDays; return $this; }
    public function getMinimumSource(): string { return $this->minimumSource; }
    public function setMinimumSource(string $minimumSource): self { $this->minimumSource = $minimumSource; return $this; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = trim($reason); return $this; }
    public function getOverriddenBy(): ?string { return $this->overriddenBy; }
    public function setOverriddenBy(?string $overriddenBy): self { $this->overriddenBy = $overriddenBy; return $this; }
    public function getOverriddenAt(): \DateTimeImmutable { return $this->overriddenAt; }
    public function setOverriddenAt(\DateTimeImmutable $overriddenAt): self { $this->overriddenAt = $overriddenAt; return $this; }

    /**
     * How many days short of the minimum these goods were, or 0 when no minimum was in force.
     *
     * Derived rather than stored: it is `minimum - remaining` and both of those ARE stored, so a
     * third column would be one fact in two places — the shape behind #589, #590, #591 and item 67
     * itself.
     *
     * Guarded on {@see self::isShortOfMinimum()} rather than subtracting unconditionally. With no
     * minimum set, `0 - (-3)` still evaluates to 3, and rendering that as "3 days short" against a
     * threshold that does not exist is a number the screen invented. The days-past-expiry figure is
     * a different number for a different reason — {@see self::getDaysPastExpiry()}.
     */
    public function getShortfallDays(): int
    {
        return $this->isShortOfMinimum() ? $this->minimumDays - $this->remainingDays : 0;
    }

    /**
     * The goods were past their last usable day before they were unloaded (item 69).
     *
     * Independent of every minimum, INCLUDING a per-product override of zero. An exemption is a
     * statement about a threshold — how much life this buyer insists on — and cannot mean "accepts
     * goods that are already dead": such stock is not sellable on arrival by this application's own
     * convention, and booking it into `available` writes a number the next expiry sweep takes away.
     */
    public function isAlreadyExpired(): bool
    {
        return $this->remainingDays < 0;
    }

    /** A minimum was in force for this product and the date was inside it. */
    public function isShortOfMinimum(): bool
    {
        return $this->minimumDays > 0 && $this->minimumDays > $this->remainingDays;
    }

    /** How many days past its last usable day the delivery already was, or 0 if it was not. */
    public function getDaysPastExpiry(): int
    {
        return $this->remainingDays < 0 ? -$this->remainingDays : 0;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        $subject = $this->receiptLine->getSku() ?? $this->receiptLine->getName();

        if ($this->isAlreadyExpired()) {
            return sprintf(
                'Expired on arrival: %s, %d day(s) past %s',
                $subject,
                $this->getDaysPastExpiry(),
                $this->expiry->format('Y-m-d'),
            );
        }

        return sprintf(
            'Short-dated receipt: %s, %d day(s) short of %d',
            $subject,
            $this->getShortfallDays(),
            $this->minimumDays,
        );
    }
}
