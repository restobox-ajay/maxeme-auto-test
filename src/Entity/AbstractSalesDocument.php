<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\DocumentLog;
use App\Contract\Document\PendingActivityLogEntry;
use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Contract\Tax\TaxContext;
use App\Exception\StatusTransitionRefused;
use App\Model\CompanyIdentity;
use App\Service\DocumentActor;
use App\Service\RegionSeedData;
use App\Status\StatusVocab;
use App\Status\StatusVocabularyRegistry;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Shared header fields for Cart, Estimate, SalesOrder and Invoice (the "SalesOrder" of the
 * client's AbstractSalesDocument → Estimate / SalesOrder diagram — SalesOrder
 * keeps its existing class/table name rather than being renamed).
 *
 * Deliberately excluded here (kept per-concrete-class): id/PK, status (each
 * subtype has its own enum), invoiceDate/paymentStatus/paymentMethod/paymentTerm
 * (invoice-only since #539 — invoiceDate and paymentStatus left SalesOrder entirely, and
 * paymentMethod/paymentTerm are on both the order and the invoice), and the lines/logs/payments
 * collections (different target entities per subtype — a mapped superclass can't parametrize
 * targetEntity).
 *
 * The document-number column is also per-concrete-class (SalesOrder::$orderNumber,
 * Estimate::$documentNumber), not here — an earlier version of this class declared
 * a shared $documentNumber with a getOrderNumber()/setOrderNumber() alias pair on
 * SalesOrder, but that only aliased the PHP methods, not the underlying Doctrine
 * property name. Every DQL/array-criteria reference to `orderNumber` elsewhere in
 * the codebase (admin order search/sort, OrderNumberGenerator's uniqueness check,
 * etc.) broke with "Unrecognized field: SalesOrder::$orderNumber", since Doctrine
 * only recognizes the property it's actually mapped as. Keeping the field
 * per-concrete-class (like status) avoids that footgun entirely.
 *
 * Cart joined Estimate and SalesOrder here (issue #165 step 5). Two fields had to widen for it, and
 * both widened only on the base: the subclasses that really do have the constraint narrow it back
 * for themselves, in their own mapping and — for company — in a setter guard, rather than the base
 * carrying an invariant on their behalf that one of its three subclasses does not share.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSalesDocument
{
    /**
     * Null for a guest cart, which has no buyer until someone logs in. SalesOrder and Estimate keep
     * the column NOT NULL and reject a null in setCompany().
     */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true)]
    protected ?Company $company = null;

    #[ORM\Column(length: 80, nullable: true)]
    protected ?string $poNumber = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $userName = null;

    /**
     * The calendar date this document is dated, as a plain 'Y-m-d' string.
     *
     * A string, not a date column, because a calendar date has no instant: "this order is dated
     * the 6th" is true in every timezone at once. As a DateTimeImmutable it was given a midnight
     * it never had, and every layer that saw that midnight was then entitled to convert it —
     * storage pinned to UTC on the way in, Twig's |date filter applying the display timezone on the
     * way out — so an order raised by an admin in a zone behind UTC could be stored, and displayed,
     * a day off its own date. A string has nothing to convert.
     *
     * Format is the app's invariant, enforced on entry by TextInput::calendarDate() and on display
     * by the calendar_date Twig filter. Sorting and range filtering are unaffected: 'Y-m-d' sorts
     * and compares lexicographically in exactly calendar order, which is why this format and not
     * another.
     *
     * Null on a cart: a basket has not been raised as a document, so there is nothing for it to be
     * dated. On an order or a quote the column is NOT NULL (see the overrides on each) and
     * SalesDocumentDateStamp fills it at persist time from the display timezone's today, for any
     * path that did not set one itself.
     *
     * Named documentDate, not poDate, because it never had anything to do with a purchase order
     * (#498). Each subclass labels its own: "Order Date" on a SalesOrder, "Quote Date" on an
     * Estimate — which is exactly why the shared field cannot take either name. The old one was
     * inherited from the column this superclass was factored out of and misread as a partner to
     * poNumber, which IS the customer's purchase order reference and is a different thing entirely.
     */
    #[ORM\Column(length: 10, nullable: true)]
    protected ?string $documentDate = null;

    // PHP type is nullable so Estimate (which overrides these columns to nullable — see
    // Estimate::__construct(), which resets them to null for "TBD") can hydrate a null value
    // without a TypeError. SalesOrder's column stays NOT NULL DEFAULT '0.00', so in practice
    // it is never actually null — this is a type widening, not a behavior change, for it.
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    protected ?string $subtotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    protected ?string $tax = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    protected ?string $total = '0.00';

    #[ORM\Column(length: 32)]
    protected string $source = 'Admin';

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $specialInstructions = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $feeLines = null;

    /**
     * Coupon codes applied to this document, uppercased, as a JSON list. Null when none are.
     *
     * A list rather than a single code because coupons stack: each one becomes its own fee line,
     * which the fee infrastructure already supports without change. Stored as JSON text alongside
     * fee_lines and tax_lines rather than as a join table, matching how every other line snapshot
     * on this entity is held.
     *
     * Only the codes are stored, never the discount amounts: those are fee lines, rebuilt by
     * CouponFeeCalculator every time fee lines are recomputed, exactly like every other fee. Storing
     * amounts as well would give two figures that could disagree after an edit changes the subtotal
     * the percentages are taken from.
     */
    #[ORM\Column(name: 'coupon_codes', type: 'text', nullable: true)]
    protected ?string $couponCodes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $taxLines = null;

    /**
     * The customer's identity frozen at creation — see CompanyIdentity for what is in it and why.
     *
     * One JSON column rather than five flat ones because nothing queries it: the admin order list's
     * searching and sorting on c.name answers "show me all orders for Acme", which is a relationship
     * question and rightly keeps using the live $company foreign key. sales_order and estimate already
     * carry tax_lines and fee_lines as CLOB snapshots, so this is the established shape.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $companySnapshot = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $shippingMethod = null;

    // Label snapshot of the region the customer priced/purchased against — not a FK,
    // so renaming/deleting a region never rewrites document history.
    #[ORM\Column(length: 160, nullable: true)]
    protected ?string $fulfillmentRegion = null;

    #[ORM\Column]
    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        // documentDate is deliberately NOT stamped here. "Today" is a question about the display
        // timezone, and an entity constructor has no way to ask — it would have to use PHP's
        // ambient default, which Kernel pins to UTC, which is the bug. SalesDocumentDateStamp
        // fills it at persist time instead, where AppSettings is reachable.
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getCompany(): ?Company { return $this->company; }

    /**
     * Attaching a company also freezes its identity, the first time only.
     *
     * Done here rather than at each of the six call sites that create a document, so a new creation
     * path cannot forget: a document with a company but no snapshot is not a state worth allowing.
     * Re-assigning the company later deliberately leaves the snapshot alone — call snapshotCompany()
     * to re-freeze on purpose.
     *
     * Doctrine hydrates mapped properties by reflection, not through setters, so loading a document
     * never runs this.
     */
    public function setCompany(?Company $company): static
    {
        $this->company = $company;
        if ($company instanceof Company) {
            $this->companySnapshot ??= CompanyIdentity::fromCompany($company)->toArray();
        }

        return $this;
    }

    /** Re-freeze from the live company, discarding whatever was recorded before. */
    public function snapshotCompany(?Company $company = null): static
    {
        $company ??= $this->company;
        $this->companySnapshot = $company instanceof Company
            ? CompanyIdentity::fromCompany($company)->toArray()
            : null;

        return $this;
    }

    /**
     * Take another document's frozen identity as-is — used when an estimate becomes an order.
     *
     * Re-reading the live company here would reintroduce the drift this exists to prevent: a quote
     * priced for one legal entity would convert into an order naming another. Same rule the address
     * snapshot follows in AbstractDocumentAddress::copyFromSnapshot().
     */
    public function copyCompanySnapshotFrom(self $other): static
    {
        $this->companySnapshot = $other->companySnapshot;

        return $this;
    }

    /**
     * What the document should print. Falls back to the live company for rows written before the
     * snapshot existed, so nothing renders blank.
     */
    public function getCompanyIdentity(): CompanyIdentity
    {
        if ($this->companySnapshot !== null) {
            return CompanyIdentity::fromArray($this->companySnapshot);
        }

        // An unstamped guest cart has neither snapshot nor company; an empty identity renders blank,
        // which is the truth, rather than throwing on a page that only wanted to print a name.
        return $this->company instanceof Company
            ? CompanyIdentity::fromCompany($this->company)
            : CompanyIdentity::fromArray([]);
    }

    /**
     * The CommercialDocument view of the frozen customer identity (#636).
     *
     * The snapshot, never the live company — the same rule the buy side's `$vendorName` follows,
     * and the reason getCompanyIdentity() exists at all: a rebrand must not rewrite a three-year-old
     * invoice. Empty string only for a cart that has no buyer yet; every document that implements
     * CommercialDocument has a NOT NULL company_id and a snapshot taken in setCompany().
     */
    public function getCounterpartyName(): string
    {
        return $this->getCompanyIdentity()->getName();
    }

    /**
     * Always null: a sales document does not state a currency of its own.
     *
     * Not an omission and not a TODO. The sell side is single-currency and names that currency once
     * for the whole application, in the `base_currency` AppSetting, which an entity cannot read and
     * which no sales table copies onto a row. The alternatives were a hardcoded constant, which
     * would state a currency no document was raised in, and a new column, which #636 rules out. A
     * consumer resolves this null against `base_currency`; see CommercialDocument::getCurrency().
     */
    public function getCurrency(): ?string
    {
        return null;
    }

    /** @return array<string, mixed>|null the raw stored snapshot; prefer getCompanyIdentity() */
    public function getCompanySnapshot(): ?array { return $this->companySnapshot; }

    /** @param array<string, mixed>|null $companySnapshot */
    public function setCompanySnapshot(?array $companySnapshot): static { $this->companySnapshot = $companySnapshot; return $this; }

    public function getPoNumber(): ?string { return $this->poNumber; }
    public function setPoNumber(?string $poNumber): static { $this->poNumber = $poNumber; return $this; }

    public function getUserName(): ?string { return $this->userName; }
    public function setUserName(?string $userName): static { $this->userName = $userName; return $this; }

    /**
     * @return Collection<int, AbstractDocumentAddress> the document's own frozen addresses
     */
    abstract public function getAddresses(): Collection;

    /**
     * The document's product rows.
     *
     * Abstract for the same reason getAddresses() is: a mapped superclass cannot parametrize
     * targetEntity, so each subclass owns its own collection and only the shape is shared.
     *
     * @return Collection<int, DocumentLine>
     */
    abstract public function getLines(): Collection;

    /** Creates an empty snapshot of the right concrete type, already attached to this document. */
    abstract protected function newAddress(string $type): AbstractDocumentAddress;

    public function getBillingAddress(): ?AbstractDocumentAddress
    {
        return $this->addressOfType(AbstractDocumentAddress::TYPE_BILLING);
    }

    public function getShippingAddress(): ?AbstractDocumentAddress
    {
        return $this->addressOfType(AbstractDocumentAddress::TYPE_SHIPPING);
    }

    /**
     * The address to price and print against: frozen if this document froze one, otherwise resolved
     * live through the row's source_address_id link.
     *
     * This is not the old fallback coming back. That one reached for
     * $this->company->getDefaultBillingAddress() whenever the foreign key was null, so an invoice
     * could assert goods went somewhere they never went. The rule here is narrower: only a row that
     * carries a link and no copied values resolves live, and only such a row is not yet a record of
     * anything. That is exactly a cart, which points at an address-book entry and freezes it at
     * conversion. An order's row always has values, so it always answers with them.
     *
     * Having one call answer for both is what lets a consumer read an address off a document without
     * knowing whether it is looking at a cart.
     */
    public function getEffectiveBillingAddress(): ?AbstractDocumentAddress
    {
        return self::frozenOrLive($this->getBillingAddress());
    }

    public function getEffectiveShippingAddress(): ?AbstractDocumentAddress
    {
        return self::frozenOrLive($this->getShippingAddress());
    }

    /**
     * Live resolution hands back a detached copy rather than filling the row in place: writing the
     * live values onto the row would freeze the cart's address at render time, and the whole point
     * is that it freezes at conversion. Cloning the row is how a concrete address of the right
     * subtype is obtained without a factory; the clone is never persisted, since nothing puts it in
     * the document's collection.
     */
    private static function frozenOrLive(?AbstractDocumentAddress $address): ?AbstractDocumentAddress
    {
        $source = $address?->getSourceAddress();
        if ($address === null || $source === null || !$address->isLinkOnly()) {
            return $address;
        }

        return (clone $address)->copyFrom($source);
    }

    /**
     * The highest tax class across the document's lines — S beats G beats E.
     *
     * On the document rather than derived per calculator: every calculator that needs it needs the
     * same answer from the same rows, so deriving it in each of them would move the duplication
     * rather than remove it.
     */
    public function getHighestTaxClass(): string
    {
        $codes = [];
        foreach ($this->getLines() as $line) {
            $codes[] = $line->getTaxCode();
        }

        return TaxContext::resolveHighestTaxClass($codes);
    }

    /**
     * The document's product rows flattened into the {product, qty} pairs the fee and shipping
     * calculators count items with.
     *
     * Here beside getHighestTaxClass() for the same reason: sixteen callers each built this loop by
     * hand, and deriving it per context would move the duplication rather than remove it. Rows with
     * no product or no quantity are left out — a calculator counting items has nothing to count on
     * them, and every qty-based calculator already skips them itself.
     *
     * $pricedOnly additionally drops rows the document has put no figure against. Fees and shipping
     * ask different questions of the same rows: a fee is money charged on a row, and a row with no
     * price is not settled enough to charge against — which is why an estimate with a TBD line has
     * never been billed a fee for it. Shipping asks about goods rather than money, so it counts
     * every row a customer would receive, priced or not.
     *
     * Step 9 handed shipping calculators the document itself, and they call this on it rather than
     * being given the array — so this stayed, as the one place the "which rows count" rule is
     * written. FeeContext::fromDocument() still reads it too. What went away is every caller
     * assembling the loop by hand.
     *
     * @return list<array{product: ProductCore, qty: int}>
     */
    public function getCartItems(bool $pricedOnly = false): array
    {
        $items = [];
        foreach ($this->getLines() as $line) {
            $product = $line->getProduct();
            $qty = (int) $line->getQuantity();
            if (!$product instanceof ProductCore || $qty <= 0) {
                continue;
            }
            if ($pricedOnly && $line->getSubtotal() === null) {
                continue;
            }

            $items[] = ['product' => $product, 'qty' => $qty];
        }

        return $items;
    }

    /**
     * The province this document ships to, as a code.
     *
     * Always normalised, never the raw stored value: addresses hold codes since the short-code
     * migration, but a legacy display name that reaches a calculator unnormalised silently matches
     * no tax or shipping rule and produces a $0-tax order. This is the only normalisation path a
     * document has: TaxContext and FeeContext still run the same call on the value they are handed,
     * because they can be built from a bare province string, but every caller that has a document
     * reads it from here. Shipping calculators take the document itself and call this directly.
     */
    public function getProvince(): string
    {
        return RegionSeedData::resolveProvinceAnyCountry(
            (string) ($this->getEffectiveShippingAddress()?->getProvince() ?? '')
        );
    }

    /** Freeze a copy of an address-book row onto this document. Null removes the snapshot. */
    public function setBillingAddressFrom(?CompanyAddress $address): static
    {
        return $this->snapshotFrom(AbstractDocumentAddress::TYPE_BILLING, $address);
    }

    public function setShippingAddressFrom(?CompanyAddress $address): static
    {
        return $this->snapshotFrom(AbstractDocumentAddress::TYPE_SHIPPING, $address);
    }

    /**
     * Copy another document's snapshot — used when an estimate becomes an order, so the order
     * inherits the address the quote was priced against instead of re-reading the address book.
     */
    public function copyAddressFrom(AbstractDocumentAddress $other): static
    {
        $target = $this->addressOfType($other->getType()) ?? $this->newAddress($other->getType());
        $target->copyFromSnapshot($other);

        return $this;
    }

    /** The snapshot for $type, or a new empty one attached to this document. */
    public function addressForWriting(string $type): AbstractDocumentAddress
    {
        return $this->addressOfType($type) ?? $this->newAddress($type);
    }

    /*
     * The three accessors below used to be columns on this table. They now read and write the
     * relevant snapshot, so the many call sites that set a billing/shipping name keep working while
     * the data lives in one place. Names are split on the first space, which is how the combined
     * column was always populated.
     */

    public function getBillingName(): ?string
    {
        $name = $this->getBillingAddress()?->getFullName();

        return $name === '' ? null : $name;
    }

    public function setBillingName(?string $billingName): static
    {
        $this->writeName(AbstractDocumentAddress::TYPE_BILLING, $billingName);

        return $this;
    }

    public function getShippingName(): ?string
    {
        $name = $this->getShippingAddress()?->getFullName();

        return $name === '' ? null : $name;
    }

    public function setShippingName(?string $shippingName): static
    {
        $this->writeName(AbstractDocumentAddress::TYPE_SHIPPING, $shippingName);

        return $this;
    }

    public function getShippingCompanyName(): ?string
    {
        return $this->getShippingAddress()?->getCompanyName();
    }

    public function setShippingCompanyName(?string $shippingCompanyName): static
    {
        $this->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setCompanyName($shippingCompanyName);

        return $this;
    }

    private function addressOfType(string $type): ?AbstractDocumentAddress
    {
        foreach ($this->getAddresses() as $address) {
            if ($address->getType() === $type) {
                return $address;
            }
        }

        return null;
    }

    private function snapshotFrom(string $type, ?CompanyAddress $address): static
    {
        if ($address === null) {
            $existing = $this->addressOfType($type);
            if ($existing !== null) {
                $this->getAddresses()->removeElement($existing);
            }

            return $this;
        }

        $this->addressForWriting($type)->copyFrom($address);

        return $this;
    }

    private function writeName(string $type, ?string $name): void
    {
        $name = trim((string) $name);
        $snapshot = $this->addressForWriting($type);

        if ($name === '') {
            $snapshot->setFirstName(null)->setLastName(null);

            return;
        }

        $parts = explode(' ', $name, 2);
        $snapshot->setFirstName($parts[0]);
        $snapshot->setLastName($parts[1] ?? null);
    }

    public function getDocumentDate(): ?string { return $this->documentDate; }
    public function setDocumentDate(?string $documentDate): static { $this->documentDate = $documentDate; return $this; }

    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(?string $subtotal): static { $this->subtotal = $subtotal; return $this; }

    public function getTax(): ?string { return $this->tax; }
    public function setTax(?string $tax): static { $this->tax = $tax; return $this; }

    public function getTotal(): ?string { return $this->total; }
    public function setTotal(?string $total): static { $this->total = $total; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function getSpecialInstructions(): ?string { return $this->specialInstructions; }
    public function setSpecialInstructions(?string $specialInstructions): static { $this->specialInstructions = $specialInstructions; return $this; }

    public function getFeeLines(): ?string { return $this->feeLines; }

    public function setFeeLines(?string $feeLines): static
    {
        $this->feeLines = $feeLines;

        return $this;
    }

    /**
     * The document's charge rows — fees, discounts and shipping alike — as they were frozen.
     *
     * @return FeeLine[]
     */
    public function getFeeLineRows(): array
    {
        return FeeLineSnapshot::decode($this->feeLines);
    }

    /**
     * The shipping rows, in the order they were written.
     *
     * There can be any number: an invoice may carry a carrier charge, a fuel surcharge and a
     * residential-delivery fee, and each is a row an admin can label and price. Nothing here
     * enforces pick-one — that rule belongs to customer checkout, which offers a choice of one
     * ShippingOption, not to the document, which just holds what it was given.
     *
     * @return FeeLine[]
     */
    public function getShippingLines(): array
    {
        return array_values(array_filter(
            $this->getFeeLineRows(),
            static fn (FeeLine $line): bool => $line->type === FeeLine::TYPE_SHIPPING,
        ));
    }

    /**
     * The distinct charge slugs this document carries, in the order they were written.
     *
     * The slug is the identity a charge row has across documents. It is already declared to be
     * "a reporting key that survives the admin relabelling it" (SalesDocumentChargeLines), and it is
     * what attributes an invoice's charge row back to the order's — the job InvoiceLine::$salesOrderLine
     * does for product rows (#539 stage 5).
     *
     * Two rows on one document may share a slug: nothing keys on uniqueness, and two rows both
     * labelled "Adjustment" are two rows. For invoicing they are treated as one charge of the
     * combined quantity, which is why every method here sums rather than picking. That keeps the
     * "it has to add back up" rule exactly true in aggregate without inventing a second identity
     * for charge rows to carry.
     *
     * @return list<string>
     */
    public function getChargeSlugs(): array
    {
        $slugs = [];
        foreach ($this->getFeeLineRows() as $line) {
            if (!in_array($line->slug, $slugs, true)) {
                $slugs[] = $line->slug;
            }
        }

        return $slugs;
    }

    /**
     * This document's charge rows under one slug.
     *
     * @return FeeLine[]
     */
    public function chargeRowsFor(string $slug): array
    {
        return array_values(array_filter(
            $this->getFeeLineRows(),
            static fn (FeeLine $line): bool => $line->slug === $slug,
        ));
    }

    /**
     * How much of the charge under $slug this document bills, as a two-decimal string.
     *
     * Two decimals to match the quantity columns on every line entity, so a charge remainder and a
     * product remainder are the same kind of figure and compare the same way.
     */
    public function chargeQuantityFor(string $slug): string
    {
        $total = 0.0;
        foreach ($this->chargeRowsFor($slug) as $line) {
            $total += $line->quantity;
        }

        return number_format($total, 2, '.', '');
    }

    /** What this document charges under $slug, summed across its rows. */
    public function chargeAmountFor(string $slug): float
    {
        $total = 0.0;
        foreach ($this->chargeRowsFor($slug) as $line) {
            $total += $line->amount;
        }

        return round($total, 2);
    }

    /**
     * What this document charges for shipping: the sum of its shipping rows.
     *
     * The only shipping figure a document has. There is no cached column beside it any more (issue
     * #165 step 8): the one SQL reader — the admin quote list's sort on e.shipping — is gone, and a
     * second copy of a figure is a second answer waiting to disagree with the rows.
     *
     * Null when there are no shipping rows, which is not the same as zero. An estimate is allowed to
     * leave shipping unstated ("TBD") while its lines are priced, and a document that genuinely
     * ships for nothing says so with a $0 row rather than by having no row. That distinction used to
     * need a nullable column and an Estimate-only override to survive; asking the rows, it is simply
     * what the rows say.
     */
    public function getShippingTotal(): ?float
    {
        $lines = $this->getShippingLines();
        if ($lines === []) {
            return null;
        }

        return round((float) array_sum(array_map(static fn (FeeLine $line): float => $line->amount, $lines)), 2);
    }

    /**
     * Applied coupon codes, uppercased and de-duplicated, in the order they were applied.
     *
     * Order is preserved because it decides how the cap is shared when the codes together exceed
     * the subtotal — see CouponFeeCalculator.
     *
     * @return list<string>
     */
    public function getCouponCodes(): array
    {
        if ($this->couponCodes === null || trim($this->couponCodes) === '') {
            return [];
        }

        $decoded = json_decode($this->couponCodes, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Blanks and duplicates are dropped, and an empty list normalises to null, so "cleared" and
     * "never applied" are one state.
     *
     * @param list<string> $couponCodes
     */
    public function setCouponCodes(array $couponCodes): static
    {
        $clean = [];
        foreach ($couponCodes as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '' && !in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        $this->couponCodes = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function getTaxLines(): ?string { return $this->taxLines; }
    public function setTaxLines(?string $taxLines): static { $this->taxLines = $taxLines; return $this; }

    public function getShippingMethod(): ?string { return $this->shippingMethod; }
    public function setShippingMethod(?string $shippingMethod): static { $this->shippingMethod = $shippingMethod; return $this; }

    public function getFulfillmentRegion(): ?string { return $this->fulfillmentRegion; }
    public function setFulfillmentRegion(?string $fulfillmentRegion): static { $this->fulfillmentRegion = $fulfillmentRegion; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /*
     * ----------------------------------------------------------------------------------------------
     * The status seam (queue item 64). Shared bodies for HasStatus.
     * ----------------------------------------------------------------------------------------------
     *
     * This class does NOT declare `implements HasStatus`, and that is deliberate. `Cart` extends it
     * and has no status at all — no column, no property, not one reference in the class — so putting
     * the interface here would promise a status for a document that has none, and the only way to
     * keep that promise would be to invent one. The concrete documents declare the interface; these
     * bodies serve them.
     *
     * It is the same shape, for the same reason, that `CommercialDocument` already sits on the
     * concrete sales classes rather than on this one. See that interface's docblock, which records
     * the cart argument in full.
     *
     * Three things each concrete document supplies, because this class deliberately does not store
     * status or logs (see the class docblock — both are per-concrete-class):
     *
     *  - the `STATUS_VOCABULARY` constant, read through late static binding;
     *  - `readStatus()` and `writeStatus()`, the read and the write of its own column;
     *  - `newLogEntry()`, its own timeline row — or null where it keeps no timeline.
     *
     * `readStatus()` is a separate, protected read rather than the interface's own `getStatus()`,
     * for a reason worth keeping: `Invoice` and `CreditMemo` still return ENUMS from `getStatus()`
     * (`Estimate` and `SalesOrder` return strings). Declaring a `getStatus(): string` here would be
     * an incompatible override on both and a fatal error the moment this file loaded. The internal
     * pair lets the shared bodies serve that mixed hierarchy — every one of them reads the status
     * through here, so none of them cares which a document stores. `HasStatus::getStatus()` records
     * why the two enum columns have not been converted.
     */

    /**
     * Reads the column, for the shared bodies below. Both halves default to refusing, because most
     * of what extends this class does have a status and exactly one thing does not: `Cart` has no
     * status column, no status property and not one reference to one. Inheriting a throw rather
     * than a silent null is what makes that honest — a cart asked for its status says so.
     */
    protected function readStatus(): string
    {
        throw new \LogicException(sprintf('%s has no status column to read.', static::class));
    }

    /**
     * Writes the column, and nothing else. Protected: the ONE door is `setStatus()`, and this is
     * how a shared `setStatus()` reaches a property it does not own.
     *
     * Not public, so it is not a second door. Section 8's rule — everything writes through
     * `setStatus()` — is about what is reachable from outside the entity, and this is not.
     */
    protected function writeStatus(string $status): void
    {
        throw new \LogicException(sprintf('%s has no status column to write.', static::class));
    }

    /** Which vocabulary governs me. The constant is on the concrete class; this reads it. */
    public function statusVocabulary(): string
    {
        return static::STATUS_VOCABULARY;
    }

    /**
     * STATIC, which is the load-bearing decision of the whole seam — see `HasStatus`.
     *
     * `static::STATUS_VOCABULARY` is late static binding on a constant, so each concrete document
     * resolves its own key through one shared body. This is the single service-locator call the
     * design allows, confined to one well-named method.
     */
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
     * THE GATE. Every externally requested status change comes through here, and nothing else does.
     *
     * Owner ruling R1, `STATUS-SEAM-HANDOFF.md`:
     *
     * > *"The purpose of the exercise is there is only 1 gate where set status can happen, and any
     * > changes we do is only at one place. Not fixing 56 instances of handrolling."*
     *
     * So the shape is: a caller that KNOWS the target calls this; a caller that knows nothing and
     * wants a recalculation calls {@see self::applyDerivedStatus()}. Those are the two doors and
     * they are chosen by what the caller knows, not by which document it is holding.
     *
     * ## Where the bespoke logic lives — this method, and no map
     *
     * There used to be a `transitions` grid in the vocabulary and this method consulted it. The
     * owner deleted it (ruling R4): it had no consumers outside the status layer, it was wrong in
     * both directions at once, and a `from -> to` grid cannot express a rule that depends on the
     * document's own facts. {@see self::assertStatusChangeAllowed()} replaced it — an overridable
     * hook where each document states its own rules in its own code, which is what the named verbs
     * used to do before they were folded into this door.
     *
     * ## The order of the three checks, which is load-bearing
     *
     *  1. **The typo guard.** An unknown TARGET throws before anything is read or written. That is
     *     the loudness the enum used to provide: a raw comparison against `'Draftt'` would silently
     *     be false and take the wrong branch.
     *  2. **The document's own rules**, BEFORE the no-op short-circuit — because some of them are
     *     about the self-move. `void()` refused an already-void order and said so; folding it in
     *     here without running the guard first would have turned that refusal into a silent no-op.
     *  3. **The no-op.** A move to the status already held writes nothing and logs nothing, so a
     *     recalculation on every flush that happened to touch the document is neither a refusal nor
     *     a second identical row on the timeline.
     *
     * ## It is PROTECTED here, and each document opens it in its own file
     *
     * Five classes extend this one and four are on the seam. `Cart` is the one that is not: it has
     * no status column, no status property and not one reference to one, so a PUBLIC method here
     * would hand it a write door onto nothing, silently, with nothing in its file changing.
     * `EstimateStatusSeamTest::testTheSharedDoorIsOnlyPublicWhereADocumentOpensItDeliberately` is
     * what holds that shut. `SalesOrder`, `Estimate`, `Invoice` and `CreditMemo` each re-declare it
     * public and call `parent::`, which is one line each, and which is where a decision about a
     * document's write door belongs.
     *
     * Returns the resulting status, always — never void and never a bool.
     *
     * @throws StatusTransitionRefused on a move the document refuses
     * @throws \LogicException on a status this vocabulary does not know — the typo guard
     */
    protected function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        $vocabulary = static::loadStatusVocab();

        // 1. The typo guard, before anything is read or written.
        if (!$vocabulary->has($status)) {
            throw new \LogicException(sprintf(
                'There is no status "%s" in the "%s" vocabulary. It has: %s.',
                $status,
                $vocabulary->key,
                implode(', ', $vocabulary->slugs()),
            ));
        }

        $current = $this->readStatus();

        // 2. This document's own rules — the bodies the named verbs used to hold. Before the no-op
        //    below, because some of them ARE about the self-move.
        $this->assertStatusChangeAllowed($current, $status);

        // 3. The no-op.
        if ($current === $status) {
            return $current;
        }

        $this->writeStatusChange($status, $actor, $comment ?? $this->defaultStatusComment($current, $status));

        return $status;
    }

    /**
     * This document's own rules about a REQUESTED move. Throw to refuse; return to allow.
     *
     * The replacement for the transitions map, and the home of every guard that used to sit in a
     * status verb. A document with no rules of its own overrides nothing and refuses nothing, which
     * is exactly what `Estimate` does — its rules are the screens', and always were.
     *
     * **A stored value the vocabulary no longer knows is a place that may be LEFT.** That is the
     * owner's section 7 ruling and it survives the map's deletion: a document holding a legacy
     * string must still be moveable and still be saveable, or it is a row nobody can repair without
     * SQL. An overriding document honours it by refusing on what the target IS rather than on the
     * from-state being one of a listed few — which is what the verbs already did, since none of them
     * enumerated legal predecessors either.
     *
     * **Not consulted by {@see self::applyDerivedStatus()}**, which is the other door and is out of
     * scope of the consolidation (ruling R3). The rules here are about what a caller may ASK for. A
     * derived status is not asked for by anybody: the document worked it out from its own facts, and
     * running "only a human, and only from Draft" against it would refuse the deriver the very move
     * it exists to make — an order whose last counting invoice was cancelled returns to Approved.
     *
     * **Throw the right kind.** {@see StatusTransitionRefused} means the document does not go there
     * from here — a terminal state — and it is what removes a target from
     * {@see self::canTransitionTo()} and from the picker. Any other `\DomainException` means the
     * REQUEST is wrong, and leaves both alone. That is the split the transitions map used to make
     * between itself and the verbs, kept intact now that both live in one place.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        // Nothing by default. A document with no rules of its own refuses nothing, and there is no
        // longer a map behind this to refuse on its behalf. `unset()` rather than an empty body so
        // the parameters are visibly deliberate — the same idiom `StatusVocabularyWarmer` uses.
        unset($from, $to);
    }

    /**
     * What the timeline says when the caller supplied no comment.
     *
     * A hook rather than a fixed string because the verbs that were folded into the gate each had
     * their own wording, and a shape-only change does not reword a customer's history. `SalesOrder`
     * keeps "Order approved." and "Order voided (was Draft)." here, byte for byte, so a caller that
     * names only the target still writes the sentence the verb wrote.
     */
    protected function defaultStatusComment(string $from, string $to): string
    {
        return sprintf('Status changed from %s to %s.', $from, $to);
    }

    /**
     * The write itself: the column and the timeline row, and nothing else.
     *
     * PRIVATE, and it is the one place either is touched. Both doors end here —
     * {@see self::setStatus()} after its guards, {@see self::applyDerivedStatus()} after its own —
     * so there is exactly one statement of "a status change writes a row saying who made it",
     * whichever door it came through.
     */
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

    /**
     * Is this document able to go there at all from where it is now?
     *
     * **A true answer is not permission**, and that asymmetry is older than this change: it says the
     * document is not at a dead end and the target is one it knows. It does NOT promise the gate
     * will accept the move — a rule about the caller's request can still refuse it, exactly as
     * `approve()` refused an order that was not a Draft while the vocabulary was happy to allow the
     * move. The docblock on `HasStatus::canTransitionTo()` has always said so.
     *
     * That line is drawn by the TYPE of the refusal rather than by a second copy of the rules:
     *
     *  - {@see StatusTransitionRefused} — *"this document does not go there from here"*. Terminal
     *    states raise it, and it is what takes a target out of this answer and out of
     *    {@see self::allowedTransitions()}.
     *  - any other `\DomainException` — *"that request is wrong"*, which is about the ASK and not
     *    about where the document can get to. A picker still offers the move; pressing it is what
     *    produces the sentence explaining why not.
     *
     * Before the transitions map was deleted this question was answered by the map, and the verbs'
     * own refusals never touched it. The split survives intact; only the source moved from a grid
     * to the document's own code.
     *
     * An unknown TARGET is false rather than a throw: this is the boolean question, and nothing
     * transitions INTO a status the vocabulary does not have.
     */
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
            // A rule about the REQUEST, not about reachability. See above.
            return true;
        }

        return true;
    }

    /**
     * The moves on offer from the CURRENT state, each carrying its `derived` flag.
     *
     * Every status the vocabulary knows is a candidate — there is no grid of legal targets any more
     * — minus two kinds:
     *
     *  - **where the document already is**, because a picker does not offer a move to here;
     *  - **anything a terminal state puts out of reach**, asked through
     *    {@see self::canTransitionTo()} so that this list and that predicate cannot drift apart. A
     *    void order is at a dead end, so it correctly offers nothing at all.
     *
     * That reproduces what the deleted grid answered, status for status. The `sales_order` table was
     * "everything except where you are" for every live state and `[]` for Void; `estimate` was the
     * same with `[]` for Accepted. What it was NOT is a filter on the verbs' own rules — `approve()`
     * took only a Draft and the map never knew — and that is still true here, which is why a
     * stranded order is offered `Approved` even though pressing it is refused.
     *
     * **An unrecognised stored value is offered every known status**: none of them is where it
     * already is, and being on a value nobody recognises is not being at a dead end. The empty list
     * reads as "final" to everything that consumes it, which is what once left a legacy row with a
     * picker offering nothing and no way off its value.
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

    /** Report, never refuse (section 7). */
    public function statusIsRecognised(): bool
    {
        return static::loadStatusVocab()->has($this->readStatus());
    }

    /**
     * What to print — never the slug (section 9).
     *
     * An unrecognised stored value returns itself, marked, exactly as `ProductCore` already renders
     * `Waiting for Stock (unrecognised)`. The row loads, the screen says so, and nobody has to fix
     * it in SQL.
     */
    public function statusLabel(): string
    {
        $vocabulary = static::loadStatusVocab();
        $current = $this->readStatus();

        return $vocabulary->has($current) ? $vocabulary->labelFor($current) : $current . ' (unrecognised)';
    }

    /** Most documents compute nothing. The ones that do override this. */
    public function deriveStatus(): ?string
    {
        return null;
    }

    /**
     * Applies `deriveStatus()`. TRUE only when the status actually changed.
     *
     * Takes no status argument: the caller does not decide what the status should be, the document
     * does. It refuses to write a status the vocabulary does not mark `derived`, and a no-op is
     * legal and silent rather than a refusal — which is what keeps the timeline to one row per real
     * transition.
     *
     * Documents whose own rules put them out of the deriver's reach — a void order, a cancelled
     * purchase order — say so by returning null from `deriveStatus()`.
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
                $this->statusDocumentLabel(),
            ));
        }

        // Through the WRITE, not through the gate. The gate's job is to police what a caller ASKED
        // for; nobody asked for this one — the document worked it out from its own facts, which is
        // the whole distinction ruling R3 draws between the two doors. Running the gate's rules here
        // would refuse the deriver the move it exists to make: an order whose last counting invoice
        // was cancelled derives Approved from a live state, and "only from Draft" would stop it.
        //
        // Nothing is lost by the shorter route. The target is checked against the vocabulary two
        // lines above, and it is checked harder — it must also be flagged `derived`, which the gate
        // does not ask.
        $this->writeStatusChange($target, $actor, $this->derivedStatusComment($this->readStatus(), $target));

        return true;
    }

    /**
     * What a DERIVED move says on the timeline.
     *
     * A hook rather than a fixed string because the scope is shape only, and that covers the words
     * on the screen as much as the statuses themselves. `SalesOrder` has written
     * "Order status changed from Approved to Invoiced." on every derived move since #539; a generic
     * default here would quietly reword every such row from this deploy onward, and the only person
     * who would ever notice is somebody reading a timeline that changed voice halfway down.
     */
    protected function derivedStatusComment(string $from, string $to): string
    {
        return sprintf('Status changed from %s to %s.', $from, $to);
    }

    /** Documents with no timeline return null; `setStatus()` then writes no row. */
    public function newLogEntry(): ?DocumentLog
    {
        return null;
    }

    /**
     * @var Collection<int, PendingActivityLogEntry>
     *
     * Held in memory, never mapped — AuditLogSubscriber drains this in preFlush, after which the
     * real AuditLog rows exist in the database and this collection is empty again. See
     * PendingActivityLogEntry's own docblock for why this exists instead of a fourth
     * cascade-persisted OneToMany.
     *
     * An ArrayCollection, not a plain array, so `getLogs()` below can hand entity-level tests
     * (App\Tests\Entity\*, no EntityManager, never flushed) the same Collection surface
     * (`->last()`, `->count()`, `->map()`) the old cascade-persisted `$logs` property offered —
     * those tests assert on the document a named action returns, not on a database.
     */
    private Collection $pendingActivityLog;

    /**
     * The single door a concrete document's own `newLogEntry()` override calls through, and the
     * one place other code that used to construct a `new XLog()` directly (Invoice::recordSent(),
     * a status deriver, a conversion service) now calls instead — same three-setter chain either
     * way, since PendingActivityLogEntry answers to the same DocumentLog interface every existing
     * caller already writes against.
     */
    public function queueActivityLogEntry(): PendingActivityLogEntry
    {
        $this->pendingActivityLog ??= new ArrayCollection();
        $entry = new PendingActivityLogEntry();
        $this->pendingActivityLog->add($entry);

        return $entry;
    }

    /**
     * The entries queued and not yet flushed. A concrete document's `__construct()` never has to
     * initialise `$pendingActivityLog` itself — every entry point (`queueActivityLogEntry()`,
     * this getter, the pull below) lazily creates the collection on first touch, the same
     * guarantee the constructor gave the old cascade-persisted `$logs` property.
     *
     * @return Collection<int, PendingActivityLogEntry>
     */
    public function getLogs(): Collection
    {
        $this->pendingActivityLog ??= new ArrayCollection();

        return $this->pendingActivityLog;
    }

    /**
     * Drained by AuditLogSubscriber in preFlush, once per flush that touched this document — never
     * called from application code. Returns and clears in the same step, so a document flushed
     * twice in one request (rare, but the old cascade-persist made no promise against it either)
     * cannot have the same entry written twice.
     *
     * @return list<PendingActivityLogEntry>
     */
    public function pullPendingActivityLogEntries(): array
    {
        $entries = array_values($this->getLogs()->toArray());
        $this->pendingActivityLog->clear();

        return $entries;
    }

    /** How this document names itself in a refusal. Overridden where a better name exists. */
    protected function statusDocumentLabel(): string
    {
        return static::class;
    }
}
