<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Document\CommercialDocument;
use App\Contract\Document\DocumentLog;
use App\Contract\Document\PendingActivityLogEntry;
use App\Service\DocumentActor;
use App\Status\StatusVocab;
use App\Status\StatusVocabularyRegistry;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Shared header fields for the buy side: PurchaseOrder and VendorBill (#555).
 *
 * The sibling of `App\Entity\AbstractSalesDocument`, not a subclass of it and not sharing its
 * storage — see `App\Contract\Document\CommercialDocument` for why the two families are parallel
 * rather than one ancestor.
 *
 * Deliberately excluded here, and kept per-concrete-class for exactly the reasons
 * `AbstractSalesDocument` records:
 *
 *  - **the id/PK**;
 *  - **the document number**. A shared `$documentNumber` here with a `getPoNumber()` alias on the
 *    subclass would alias the PHP method and not the Doctrine property, and every DQL reference to
 *    `poNumber` would then fail with "Unrecognized field". That footgun cost someone a session on
 *    the sales side already and is documented on `AbstractSalesDocument`; it is not repeated here;
 *  - **status**, because each subtype has its own enum with its own cases;
 *  - **lines and logs**, because a mapped superclass cannot parametrize `targetEntity`.
 *
 * The counterparty is here as a real relationship because — unlike the sales base, which has to
 * serve four subtypes one of which has no buyer at all — every purchase document has exactly one
 * vendor and it is always required.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPurchaseDocument implements CommercialDocument
{
    /**
     * Who we are buying from, live. What the document *displays* is `$vendorName`, frozen below;
     * this link exists so a vendor's screens can list their documents, not so a document can look
     * up what to print.
     */
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false)]
    protected Vendor $vendor;

    /**
     * The vendor's name as it was at the moment this document was raised.
     *
     * Snapshotted, never joined for display. Renaming a vendor must not rewrite a three-year-old
     * bill — the same rule, and the same reasoning, as
     * docs/plans/2026-07-30-company-identity-snapshot.md on the sell side.
     */
    #[ORM\Column(name: 'vendor_name', length: 200)]
    protected string $vendorName = '';

    /**
     * The calendar date this document is dated, as a plain 'Y-m-d' string.
     *
     * Not a date column, for the reason `AbstractSalesDocument::$documentDate` is not: a calendar
     * date has no instant. Given a midnight it never had, storage pins it to UTC on the way in and
     * the display timezone shifts it on the way out, and a document raised in a zone behind UTC is
     * stored — and shown — a day off its own date. A string has nothing to convert, and 'Y-m-d'
     * sorts and range-compares lexicographically in exactly calendar order.
     */
    #[ORM\Column(name: 'document_date', length: 10)]
    protected string $documentDate = '';

    /**
     * What this document is denominated in, copied from the vendor.
     *
     * Per document and never converted: the plan defers currency conversion deliberately, and a
     * stored rate nobody maintains is worse than no rate at all. A document is read in the currency
     * it was raised in.
     */
    #[ORM\Column(length: 3, options: ['default' => 'CAD'])]
    protected string $currency = 'CAD';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    protected string $subtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    protected string $tax = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    protected string $total = '0.00';

    #[ORM\Column(name: 'created_at')]
    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->documentDate = (new \DateTimeImmutable())->format('Y-m-d');
    }

    public function getVendor(): Vendor { return $this->vendor; }

    /**
     * Attaching the vendor takes the name snapshot with it, in one call, so there is no window in
     * which a document names one vendor and displays another's name. The snapshot can still be
     * overwritten afterwards by an import replaying historical paperwork — see setVendorName().
     */
    public function setVendor(Vendor $vendor): static
    {
        $this->vendor = $vendor;
        if ($this->vendorName === '') {
            $this->vendorName = $vendor->getName();
        }

        return $this;
    }

    public function getVendorName(): string { return $this->vendorName; }
    public function setVendorName(string $vendorName): static { $this->vendorName = $vendorName; return $this; }

    /** The CommercialDocument view of the same snapshot. */
    public function getCounterpartyName(): string { return $this->vendorName; }

    public function getDocumentDate(): string { return $this->documentDate; }
    public function setDocumentDate(string $documentDate): static { $this->documentDate = $documentDate; return $this; }

    public function getCurrency(): string { return $this->currency; }

    public function setCurrency(string $currency): static
    {
        $currency = strtoupper(trim($currency));
        $this->currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'CAD';

        return $this;
    }

    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): static { $this->subtotal = $subtotal; return $this; }
    public function getTax(): string { return $this->tax; }
    public function setTax(string $tax): static { $this->tax = $tax; return $this; }
    public function getTotal(): string { return $this->total; }
    public function setTotal(string $total): static { $this->total = $total; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    /**
     * The document's own frozen vendor addresses — {@see PurchaseOrderAddress} /
     * {@see VendorBillAddress} — the buy-side mirror of `AbstractSalesDocument::getAddresses()`.
     * Abstract for the same reason: a mapped superclass cannot parametrize `targetEntity`.
     *
     * @return Collection<int, AbstractPurchaseDocumentAddress>
     */
    abstract public function getAddresses(): Collection;

    /** Creates an empty snapshot of the right concrete type, already attached to this document. */
    abstract protected function newAddress(string $type): AbstractPurchaseDocumentAddress;

    public function getAddress(string $type): ?AbstractPurchaseDocumentAddress
    {
        foreach ($this->getAddresses() as $address) {
            if ($address->getType() === $type) {
                return $address;
            }
        }

        return null;
    }

    /**
     * The address to price and print against: frozen if this document froze one, otherwise
     * resolved live through the row's source_address_id link — the buy-side mirror of
     * `AbstractSalesDocument::frozenOrLive()`. Only a row that carries a link and no copied
     * values resolves live, which is exactly a not-yet-saved draft's own row.
     */
    public function getEffectiveAddress(string $type): ?AbstractPurchaseDocumentAddress
    {
        $address = $this->getAddress($type);
        $source = $address?->getSourceAddress();
        if ($address === null || $source === null || !$address->isLinkOnly()) {
            return $address;
        }

        return (clone $address)->copyFrom($source);
    }

    /** Freeze a copy of a vendor's address-book row onto this document. Null removes the snapshot. */
    public function setAddressFrom(string $type, ?VendorAddress $address): static
    {
        if ($address === null) {
            $existing = $this->getAddress($type);
            if ($existing !== null) {
                $this->getAddresses()->removeElement($existing);
            }

            return $this;
        }

        $this->addressForWriting($type)->copyFrom($address);

        return $this;
    }

    /** The snapshot for $type, or a new empty one attached to this document. */
    public function addressForWriting(string $type): AbstractPurchaseDocumentAddress
    {
        return $this->getAddress($type) ?? $this->newAddress($type);
    }

    /**
     * @var Collection<int, PendingActivityLogEntry>
     *
     * The buy-side twin of AbstractSalesDocument's own property of the same name — see that
     * class's docblock for the full design rationale (App\Entity\AbstractSalesDocument). Held in
     * memory, never mapped: AuditLogSubscriber's preFlush drains it into a real AuditLog row per
     * entry, after which this collection is empty again. Shared here on the mapped superclass so
     * PurchaseOrder and VendorBill both get it from one definition — the same reason lines,
     * addresses and money helpers already live here rather than on each subclass.
     */
    private Collection $pendingActivityLog;

    /**
     * The lazy-init accessor `queueActivityLogEntry()`/`getLogs()`/`pullPendingActivityLogEntries()`
     * all go through, rather than any of them calling another — VendorBill still overrides
     * `getLogs()` with its own cascade-persisted `$logs` collection (not migrated to this mechanism
     * yet), and `pullPendingActivityLogEntries()` is inherited by every subclass regardless
     * (AuditLogSubscriber's preFlush calls it on anything with the method, via
     * `method_exists()`). Routing through `$this->getLogs()` there would resolve to VendorBill's
     * override via virtual dispatch and then try to `->clear()` a property that override never
     * touches — private, so a subclass override of `getLogs()` cannot shadow this one too.
     */
    private function pendingActivityLogCollection(): Collection
    {
        $this->pendingActivityLog ??= new ArrayCollection();

        return $this->pendingActivityLog;
    }

    /** The single door a named action calls through instead of constructing a *Log entity directly. */
    public function queueActivityLogEntry(): PendingActivityLogEntry
    {
        $entry = new PendingActivityLogEntry();
        $this->pendingActivityLogCollection()->add($entry);

        return $entry;
    }

    /**
     * The entries queued and not yet flushed — also what a pure entity-level test (no
     * EntityManager) reads to assert what a named action wrote, the same role AbstractSalesDocument
     * ::getLogs() plays for its own tests. VendorBill overrides this with its own `$logs` collection
     * (not migrated to this mechanism yet); PurchaseOrder does not, so this is the one it gets.
     *
     * @return Collection<int, PendingActivityLogEntry>
     */
    public function getLogs(): Collection
    {
        return $this->pendingActivityLogCollection();
    }

    /**
     * Drained by AuditLogSubscriber in preFlush, once per flush that touched this document — never
     * called from application code. Every AbstractPurchaseDocument gets this method whether or not
     * it has ever queued an entry (VendorBill included, via inheritance) — the lazy-init accessor is
     * what makes that safe rather than a crash on an untouched document's own first flush.
     *
     * @return list<PendingActivityLogEntry>
     */
    public function pullPendingActivityLogEntries(): array
    {
        $collection = $this->pendingActivityLogCollection();
        $entries = array_values($collection->toArray());
        $collection->clear();

        return $entries;
    }

    /**
     * Money is compared and summed in whole cents, never as floats: 0.10 + 0.20 is not 0.30 in
     * binary floating point, and a bill a hundredth of a cent short is a bill that never matches.
     * The same two helpers Invoice carries, for the same reason.
     */
    public static function cents(?string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /*
     * ----------------------------------------------------------------------------------------------
     * The status seam. Shared bodies for HasStatus — the buy-side twin of the block by the same name
     * on App\Entity\AbstractSalesDocument, which carries the full design rationale (owner rulings
     * R1-R6 in STATUS-SEAM-HANDOFF.md apply here unchanged; this is the same seam, not a new one).
     * ----------------------------------------------------------------------------------------------
     *
     * This class does NOT declare `implements HasStatus`, for the same reason the sell-side abstract
     * does not: PurchaseOrder and VendorBill each carry their own enum-typed `$status` column with
     * their own case set, so the read/write pair below (`readStatus()`/`writeStatus()`) is what lets
     * one shared body serve both without either owning a property it does not have.
     *
     * Four things each concrete document supplies:
     *
     *  - the `STATUS_VOCABULARY` constant, read through late static binding;
     *  - `readStatus()` and `writeStatus()`, converting its own enum-typed column to and from the
     *    plain string this seam is written against;
     *  - `newLogEntry()`, its own timeline row. PurchaseOrder returns `queueActivityLogEntry()` like
     *    the sell side; VendorBill has not migrated to that mechanism and attaches to its own
     *    cascade-persisted `$logs` collection instead — see that override;
     *  - `documentNoun()`, the word `assertMovableFrom()`'s refusal names itself with — "purchase
     *    order" or "vendor bill". Extracted here rather than left as two near-identical private
     *    copies (one per concrete class, differing only in this word), which is what the first cut
     *    of this seam actually shipped as.
     */

    /** Reads the column, for the shared bodies below. Every concrete document has one to read. */
    protected function readStatus(): string
    {
        throw new \LogicException(sprintf('%s has no status column to read.', static::class));
    }

    /** Writes the column, and nothing else. Protected: the ONE door is `setStatus()`. */
    protected function writeStatus(string $status): void
    {
        throw new \LogicException(sprintf('%s has no status column to write.', static::class));
    }

    /** What `assertMovableFrom()`'s refusal calls this document — "purchase order", "vendor bill". */
    protected function documentNoun(): string
    {
        throw new \LogicException(sprintf('%s has no document noun to name itself with.', static::class));
    }

    /**
     * in_array() with a message. Shared by every concrete document's `assertStatusChangeAllowed()`,
     * so the two from-state guards do not drift into two slightly different sentences for what is
     * the same kind of refusal — the same reason `App\Entity\Invoice::assertMovableFrom()` states
     * its own version once rather than at each of its call sites.
     */
    protected function assertMovableFrom(string $from, string $target, string ...$allowedFrom): void
    {
        if (in_array($from, $allowedFrom, true)) {
            return;
        }

        throw new \DomainException(sprintf(
            'A %s cannot go from %s to %s (allowed from: %s).',
            $this->documentNoun(),
            $from,
            $target,
            implode(', ', $allowedFrom),
        ));
    }

    /** Which vocabulary governs me. The constant is on the concrete class; this reads it. */
    public function statusVocabulary(): string
    {
        return static::STATUS_VOCABULARY;
    }

    /** STATIC — see `HasStatus::loadStatusVocab()` for why. */
    public static function loadStatusVocab(): StatusVocab
    {
        return StatusVocabularyRegistry::get(static::STATUS_VOCABULARY);
    }

    /** @return array<string, string> slug => label */
    public static function listStatuses(): array
    {
        return static::loadStatusVocab()->labels();
    }

    /**
     * THE GATE. See `App\Entity\AbstractSalesDocument::setStatus()` for the full reasoning — the
     * order of the three checks below is load-bearing there and is unchanged here.
     *
     * @throws StatusTransitionRefused on a move the document refuses
     * @throws \LogicException on a status this vocabulary does not know — the typo guard
     */
    protected function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        $vocabulary = static::loadStatusVocab();

        if (!$vocabulary->has($status)) {
            throw new \LogicException(sprintf(
                'There is no status "%s" in the "%s" vocabulary. It has: %s.',
                $status,
                $vocabulary->key,
                implode(', ', $vocabulary->slugs()),
            ));
        }

        $current = $this->readStatus();

        $this->assertStatusChangeAllowed($current, $status);

        if ($current === $status) {
            return $current;
        }

        $this->writeStatusChange($status, $actor, $comment ?? $this->defaultStatusComment($current, $status));

        return $status;
    }

    /**
     * This document's own rules about a REQUESTED move. Throw to refuse; return to allow. A
     * document with no rules of its own overrides nothing and refuses nothing.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        unset($from, $to);
    }

    /** What the timeline says when the caller supplied no comment. */
    protected function defaultStatusComment(string $from, string $to): string
    {
        return sprintf('Status changed from %s to %s.', $from, $to);
    }

    /** The write itself: the column and the timeline row, and nothing else. */
    private function writeStatusChange(string $status, DocumentActor $actor, string $comment): void
    {
        $this->writeStatus($status);

        $log = $this->newLogEntry();
        if ($log !== null) {
            $log->setUserName($actor->displayName)
                ->setComment($comment)
                ->setType('System');
        }
    }

    /** Is this document able to go there at all from where it is now? See `HasStatus::canTransitionTo()`. */
    public function canTransitionTo(string $status): bool
    {
        if (!static::loadStatusVocab()->has($status)) {
            return false;
        }

        try {
            $this->assertStatusChangeAllowed($this->readStatus(), $status);
        } catch (StatusTransitionRefused) {
            return false;
        } catch (\DomainException) {
            return true;
        }

        return true;
    }

    /**
     * The moves on offer from the CURRENT state, each carrying its `derived` flag. See
     * `HasStatus::allowedTransitions()`.
     *
     * @return array<string, array{label: string, derived: bool}>
     */
    public function allowedTransitions(): array
    {
        $vocabulary = static::loadStatusVocab();
        $current = $this->readStatus();
        $moves = [];

        foreach ($vocabulary->slugs() as $slug) {
            if ($slug === $current || !$this->canTransitionTo($slug)) {
                continue;
            }

            $moves[$slug] = ['label' => $vocabulary->labelFor($slug), 'derived' => $vocabulary->isDerived($slug)];
        }

        return $moves;
    }

    /** The COMPARISON. Throws on a status the vocabulary does not know — see `HasStatus::isStatus()`. */
    public function isStatus(string $status): bool
    {
        $vocabulary = static::loadStatusVocab();

        if (!$vocabulary->has($status)) {
            throw new \LogicException(sprintf(
                'There is no status "%s" in the "%s" vocabulary. It has: %s.',
                $status,
                $vocabulary->key,
                implode(', ', $vocabulary->slugs()),
            ));
        }

        return $this->readStatus() === $status;
    }

    /** Report, never refuse. */
    public function statusIsRecognised(): bool
    {
        return static::loadStatusVocab()->has($this->readStatus());
    }

    /** What to print — never the slug. */
    public function statusLabel(): string
    {
        $vocabulary = static::loadStatusVocab();
        $current = $this->readStatus();

        return $vocabulary->has($current) ? $vocabulary->labelFor($current) : $current . ' (unrecognised)';
    }

    /** Most documents compute nothing on this axis. The ones that do override this. */
    public function deriveStatus(): ?string
    {
        return null;
    }

    /**
     * Applies `deriveStatus()`. TRUE only when the status actually changed. See
     * `HasStatus::applyDerivedStatus()`.
     */
    public function applyDerivedStatus(DocumentActor $actor): bool
    {
        $target = $this->deriveStatus();

        if ($target === null || $target === $this->readStatus()) {
            return false;
        }

        $vocabulary = static::loadStatusVocab();

        if (!$vocabulary->has($target) || !$vocabulary->isDerived($target)) {
            throw new \DomainException(sprintf(
                '%s is not a derived status on %s; it is set by an action.',
                $target,
                static::class,
            ));
        }

        $this->writeStatusChange($target, $actor, $this->derivedStatusComment($this->readStatus(), $target));

        return true;
    }

    /** What a DERIVED move says on the timeline. */
    protected function derivedStatusComment(string $from, string $to): string
    {
        return sprintf('Status changed from %s to %s.', $from, $to);
    }

    /** Documents with no timeline return null; `setStatus()` then writes no row. */
    public function newLogEntry(): ?DocumentLog
    {
        return null;
    }
}
