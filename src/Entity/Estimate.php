<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\CommercialDocument;
use App\Contract\Document\DocumentLog;
use App\Contract\Status\HasStatus;
use App\Enum\EstimateStatus;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'estimate')]
#[ORM\AttributeOverrides([
    // Nullable with no default — null means "TBD", not "$0". A line-level price can
    // resolve while these order-level totals are still pending admin pricing.
    new ORM\AttributeOverride(name: 'subtotal', column: new ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)),
    new ORM\AttributeOverride(name: 'tax', column: new ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)),
    new ORM\AttributeOverride(name: 'total', column: new ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)),
    // Widened on the base for Cart; an estimate is quoted to someone on a date, so it keeps both.
    new ORM\AttributeOverride(name: 'documentDate', column: new ORM\Column(length: 10)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(
        name: 'company',
        joinColumns: [new ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false)],
    ),
])]
class Estimate extends AbstractSalesDocument implements CommercialDocument, HasStatus
{
    /**
     * Which vocabulary governs a quote. Read through late static binding by the shared bodies on
     * `AbstractSalesDocument`, so one `loadStatusVocab()` serves every sales document without any of
     * them passing a key around.
     */
    public const STATUS_VOCABULARY = 'estimate';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $documentNumber = '';

    /**
     * A plain string, governed by the 'estimate' vocabulary rather than by `enumType:`.
     *
     * The column is unchanged: `enumType:` is a PHP-side conversion, Doctrine has always stored the
     * enum's BACKING VALUE, and every `EstimateStatus` case's value is character-for-character one
     * of the vocabulary's slugs — which `ShippedVocabulariesMatchTheirEnumsTest` asserts in both
     * directions. So the DDL stays `status VARCHAR(20) NOT NULL` and every stored string stays the
     * string it already was. There is nothing here for a migration to do; the stage 3 report has
     * the before/after dump.
     *
     * `EstimateStatus` stays, stripped to its job: it is the frozen core contract that declares the
     * slugs, not a runtime type. Nothing in production reads it any more.
     */
    #[ORM\Column(length: 20)]
    private string $status = 'Draft';

    // Set only at CONVERSION (EstimateConversionService::convert()) — never edited afterward, and
    // never set by acceptance itself. On the customer's own accept the two are the same moment. On
    // the admin side they are not: a quote sits Accepted with this still null until somebody presses
    // Convert to Sales Order, so null here means "not converted", never "not accepted".
    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'converted_order_id', referencedColumnName: 'id', nullable: true)]
    private ?SalesOrder $convertedOrder = null;

    /**
     * Optimistic-lock counter, for the same reason SalesOrder (#417) and Invoice each carry one:
     * the quote edit screen is a full line-item editor, so two admins can each compute a save from
     * the same already-stale render and the second would silently discard the first.
     *
     * Estimate was the outlier of the three sell-side documents. `sales_order.version` and
     * `invoice.version` have both existed since their tables were built, and Invoice's own docblock
     * records why they were declared up front: adding a version column to a table that already has
     * rows is painful. `estimate` IS such a table, which is why the migration that adds this is
     * ADD-only with a literal default and rewrites nothing (Version20260917090000).
     *
     * A dedicated integer, not a reuse of a timestamp: Doctrine's #[ORM\Version] accepts only
     * int/bigint/smallint or a datetime column it fully owns, and this entity has no such timestamp
     * of its own. Doctrine increments it and re-checks it inside the UPDATE's WHERE clause on every
     * flush of this entity, throwing OptimisticLockException when zero rows match.
     *
     * That automatic check alone does NOT close the gap, exactly as SalesOrder's docblock explains:
     * it only catches a race inside one request's own load-then-flush span, and the real case —
     * admin B's page was rendered before admin A's save landed — has no such span, because B's own
     * load already sees A's committed row. EstimateController::edit() closes it by round-tripping
     * this value through the form as a hidden field and locking the freshly loaded estimate against
     * the SUBMITTED version before a single posted field is applied.
     *
     * No composite key, no inheritance hierarchy of its own (AbstractSalesDocument is a
     * MappedSuperclass, which is a compile-time field merge rather than a runtime STI/JTI root) and
     * no second version field — none of Doctrine's documented restrictions on a version field apply
     * here. AdminEstimateEditVersionLockCest is the empirical check: it drives the real screen
     * against a schema built from this mapping and asserts the column by reading it back, so a
     * restriction this declaration hit would fail there rather than be assumed away.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    /**
     * Ordered explicitly, because everything positional about a quote line hangs off this order:
     * the form renders the collection as-is, and both the per-line Tax $ figure and the negative-
     * quantity warning are recorded by the row's position. `id` breaks the tie so two lines saved
     * before sort_order existed still come back in a fixed order.
     *
     * @var Collection<int, EstimateLine>
     */
    #[ORM\OneToMany(targetEntity: EstimateLine::class, mappedBy: 'estimate', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /**
     * This document's own frozen addresses — see AbstractDocumentAddress for why they are copies
     * rather than a foreign key into the address book.
     *
     * @var Collection<int, EstimateAddress>
     */
    #[ORM\OneToMany(targetEntity: EstimateAddress::class, mappedBy: 'estimate', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $estimateAddresses;

    public function __construct()
    {
        parent::__construct();
        // Unlike SalesOrder, a new Estimate starts fully TBD, not "$0" — resolved amounts
        // are filled in per-field as checkout/admin pricing actually determines them. Shipping is
        // not among them any more: it is TBD by having no type=shipping rows, which is the state a
        // new estimate is already in.
        $this->subtotal = null;
        $this->tax = null;
        $this->total = null;
        $this->lines = new ArrayCollection();
        $this->estimateAddresses = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    /** A quote is always quoted to someone, so callers keep the non-null contract they had before Cart. */
    public function getCompany(): Company { return $this->company; }

    /** The parameter stays nullable — PHP forbids narrowing one — so the narrowing is the throw. */
    public function setCompany(?Company $company): static
    {
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException('An estimate must have a company; only a cart may be unattached.');
        }

        return parent::setCompany($company);
    }

    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function setDocumentNumber(string $documentNumber): self { $this->documentNumber = $documentNumber; return $this; }

    /**
     * Narrowed to non-null for CommercialDocument, which promises a date rather than a maybe-date.
     *
     * The nullable return on the base belongs to Cart, which is not a document and does not
     * implement the contract. A quote is quoted to someone on a date and narrows the column back to
     * NOT NULL in the AttributeOverride above — unlike its subtotal/tax/total, which stay nullable
     * because "TBD" is a real state for money and never is for the date the quote was raised.
     */
    public function getDocumentDate(): string { return (string) parent::getDocumentDate(); }

    /**
     * The raw stored value — FORGIVING, per `HasStatus`. A value the vocabulary no longer knows
     * still comes back, because a row you cannot load is a row you cannot repair.
     */
    public function getStatus(): string { return $this->status; }

    /** Typed accessor, for the core contract only. Null for anything the enum does not declare. */
    public function getStatusEnum(): ?EstimateStatus { return EstimateStatus::tryFrom($this->status); }

    /**
     * `HasStatus::canEditOnStatus()`. Replaces the bare `isStatus('Accepted') || isStatus('Rejected')`
     * conditional that used to sit inline in `EstimateController::edit()`; the rule itself lives on
     * `EstimateStatus::allowsEditing()`.
     */
    public function canEditOnStatus(): bool
    {
        return $this->getStatusEnum()?->allowsEditing() ?? true;
    }

    /**
     * THE GATE, made PUBLIC on this document, with the shared body on `AbstractSalesDocument`.
     *
     * A quote already had a public status setter, so nothing about its reachability changes here —
     * but everything about what it ACCEPTS does. The old one was
     * `setStatus(EstimateStatus $status): self`: no guard of any kind, no actor, no timeline row.
     * What was legal on a quote was therefore whatever each of its six call sites happened to
     * enforce, and they did not agree. That is the defect the seam exists to delete, and this is the
     * one place all six now arrive.
     *
     * A quote has no status verbs and never had any — nothing here was named after a status — so
     * this document's part in the consolidation was only to lose the vocabulary's transitions table
     * (ruling R4). The one rule that table really carried is now stated below, in code, on the
     * document it belongs to.
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        return parent::setStatus($status, $actor, $comment);
    }

    /**
     * A quote refuses nothing — every move its vocabulary knows is allowed.
     *
     * Accepted was terminal for a while and nobody decided it: a speculative `transitions` map held
     * `'Accepted' => []`. Owner, 2026-09-12: *"its definitely not terminal."*
     *
     * The hook stays, empty, because it is the seam `setStatus()` calls. A future rule must not be a
     * from/to grid — that shape cannot express anything about the document's own facts.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
    }

    /**
     * The quote's own status column, for the shared `setStatus()` on `AbstractSalesDocument`.
     *
     * Protected, so it is not a second door around `setStatus()` — the public door is the one
     * declared above and nothing else writes this property.
     */
    protected function readStatus(): string
    {
        return $this->status;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = $status;
    }

    /** This quote's pending timeline entry, queued for `setStatus()` to fill. */
    public function newLogEntry(): ?DocumentLog
    {
        return $this->queueActivityLogEntry();
    }

    protected function statusDocumentLabel(): string
    {
        return 'Estimate ' . ($this->documentNumber !== '' ? $this->documentNumber : '#' . (string) $this->id);
    }

    /** No setVersion(): Doctrine owns this column, the same contract SalesOrder and Invoice state. */
    public function getVersion(): int { return $this->version; }

    public function getConvertedOrder(): ?SalesOrder { return $this->convertedOrder; }
    public function setConvertedOrder(?SalesOrder $convertedOrder): self { $this->convertedOrder = $convertedOrder; return $this; }

    /** @return Collection<int, EstimateLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(EstimateLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setEstimate($this);
        }

        return $this;
    }

    /** Detaching a line deletes it — the association is orphanRemoval. */
    public function removeLine(EstimateLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /** True if every line has a resolved price and the header shipping/tax/total are all filled in. */
    public function isFullyPriced(): bool
    {
        if ($this->getShippingTotal() === null || $this->getTax() === null || $this->getTotal() === null || $this->getSubtotal() === null) {
            return false;
        }

        foreach ($this->lines as $line) {
            if ($line->getPrice() === null || $line->getSubtotal() === null) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, EstimateAddress> */
    public function getAddresses(): Collection
    {
        return $this->estimateAddresses;
    }

    protected function newAddress(string $type): AbstractDocumentAddress
    {
        $address = (new EstimateAddress())->setType($type);
        $address->setEstimate($this);
        $this->estimateAddresses->add($address);

        return $address;
    }
}
