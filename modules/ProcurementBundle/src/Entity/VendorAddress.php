<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\AbstractPartyAddress;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\VendorAddressRepository;

/**
 * A vendor's address book entry (#555) — where they ship from, where their remittance goes.
 *
 * Live data, not a snapshot, which is why it may cascade with its vendor. What a purchase order
 * shows is `PurchaseOrder::$vendorAddress`, a frozen copy taken when the document was raised; this
 * row is only ever the source that copy was made from. Editing it changes what the *next* document
 * says and nothing about any document already raised.
 *
 * ## One vocabulary with the sell side (#635)
 *
 * Everything an address book entry has regardless of who it belongs to now lives on
 * `App\Entity\AbstractPartyAddress`, shared with `App\Entity\CompanyAddress`. #605 built this table
 * as a parallel vocabulary for concepts `company_address` already named, and #635 collapsed the two:
 *
 *   ```
 *   was                 is
 *   address_1           address_line1
 *   address_2           address_line2
 *   contact_phone       phone
 *   ```
 *
 * The superclass took the CUSTOMER side's names, so `company_address` — the busiest address table in
 * the application — needed no migration at all and every rename landed here, on a table introduced
 * five weeks ago that has never been deployed to production. `label`, `first_name`, `last_name`,
 * `company_name`, `email_primary`, `email_secondary`, `fax`, `city`, `province`, `postal_code`,
 * `country` and `delivery_instructions` already agreed and were not touched.
 *
 * ## The widths stay this side's, and that is what AttributeOverrides is for
 *
 * `province` holds a code ('BC', 'ON'), matching the province single-source-of-truth work, and
 * `country` an ISO 3166-1 alpha-2 code. Both are narrower than `CompanyAddress`'s free-text columns
 * on purpose: nothing here predates the codes, so there are no legacy spellings to hydrate.
 *
 * #605 asked whether to widen them to match `company_address` (VARCHAR(120)/VARCHAR(100), holding
 * "British Columbia"/"Canada"). They are NOT widened, and the answer is the one `App\Service\Region`
 * already states: "addresses store codes, so the Context value objects compare 'BC' === 'BC' with no
 * lookup; normalisation happens once, at the write boundary." Codes are the direction of travel and
 * `app:seed-regions` maintains the reference data that resolves them for display. Widening this
 * column would leave two formats in one column with nothing able to tell them apart — which is the
 * problem the sell side has, not one worth copying. VendorController normalises through `Region` on
 * save, and the screen shows the resolved name beside the code.
 *
 * Sharing a NAME was the point of #635; sharing STORAGE was never the point. The overrides below
 * keep this table's five column definitions exactly as they were — same widths, same NOT NULLs —
 * so that a refactor about vocabulary could not quietly overturn a decision about data.
 *
 * ## Contact fields and purposes (#605, #606)
 *
 * Every column below `country` is nullable or defaulted, and every vendor address that existed
 * before them reads exactly as it did: no contact, no purpose, `is_default` alone deciding what a
 * purchase order prints.
 *
 * `is_order_to` / `is_ship_from` / `is_remit_to` / `is_return_to` are four flags rather than one
 * `purpose` enum because a small supplier is one location wearing all four hats, and an enum would
 * force four duplicate rows for one address which then drift apart when somebody edits one. Several
 * rows can still split the hats, which is the case that matters — a factoring company's lockbox is
 * genuinely not where the goods leave from.
 *
 * They stay on this class rather than the superclass for the same reason `is_default_billing` and
 * `is_default_shipping` stay on `CompanyAddress`: "what is this address for" is the one question the
 * two sides genuinely answer differently, and flattening it would be inventing a shared concept
 * rather than finding one.
 *
 * `is_default` stays, and stays the fallback: every purpose resolver below falls back to it, so a
 * database where nobody has assigned a purpose behaves exactly as it did before purposes existed.
 */
#[ORM\Entity(repositoryClass: VendorAddressRepository::class)]
#[ORM\Table(name: 'vendor_address')]
#[ORM\Index(name: 'idx_vendor_address_vendor', fields: ['vendor'])]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'addressLine1', column: new ORM\Column(name: 'address_line1', type: 'string', length: 200, nullable: false)),
    new ORM\AttributeOverride(name: 'addressLine2', column: new ORM\Column(name: 'address_line2', type: 'string', length: 200, nullable: true)),
    new ORM\AttributeOverride(name: 'city', column: new ORM\Column(name: 'city', type: 'string', length: 120, nullable: false)),
    new ORM\AttributeOverride(name: 'province', column: new ORM\Column(name: 'province', type: 'string', length: 8, nullable: true)),
    new ORM\AttributeOverride(name: 'country', column: new ORM\Column(name: 'country', type: 'string', length: 2, nullable: false)),
])]
class VendorAddress extends AbstractPartyAddress
{
    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\Column(name: 'is_default', options: ['default' => false])]
    private bool $isDefault = false;

    // ---------------------------------------------------------------- what it is for (#606)

    /** Where the purchase order is sent — the orders desk. */
    #[ORM\Column(name: 'is_order_to', options: ['default' => false])]
    private bool $isOrderTo = false;

    /** Where goods leave from; matters for lead time and for knowing which depot served you. */
    #[ORM\Column(name: 'is_ship_from', options: ['default' => false])]
    private bool $isShipFrom = false;

    /** Where payment goes — often a different legal entity entirely. */
    #[ORM\Column(name: 'is_remit_to', options: ['default' => false])]
    private bool $isRemitTo = false;

    /** Where a vendor return is sent. */
    #[ORM\Column(name: 'is_return_to', options: ['default' => false])]
    private bool $isReturnTo = false;

    /**
     * The three NOT NULL columns start filled, as they did when they were declared on this class.
     *
     * Doctrine hydrates without calling a constructor, so this only affects addresses built in PHP —
     * which is the only place the difference could be observed.
     */
    public function __construct()
    {
        $this->addressLine1 = '';
        $this->city = '';
        $this->country = 'CA';
    }

    public function getVendor(): Vendor { return $this->vendor; }
    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }

    /**
     * Narrowed to non-null, which the superclass cannot be.
     *
     * `company_address` allows a street-less row and always has; `vendor_address` does not and never
     * did. A shared parent has to declare the looser of the two, so the guarantee this side actually
     * holds is restated here — in the return type, where a caller reads it — rather than lost.
     */
    public function getAddressLine1(): string { return $this->addressLine1 ?? ''; }

    public function getCity(): string { return $this->city ?? ''; }

    public function getCountry(): string { return $this->country ?? 'CA'; }

    /** ISO 3166-1 alpha-2, normalised on the way in — the write boundary `Region` describes. */
    public function setCountry(?string $country): static
    {
        $this->country = strtoupper(trim((string) $country)) ?: 'CA';

        return $this;
    }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): self { $this->isDefault = $isDefault; return $this; }

    public function isOrderTo(): bool { return $this->isOrderTo; }
    public function setIsOrderTo(bool $isOrderTo): self { $this->isOrderTo = $isOrderTo; return $this; }
    public function isShipFrom(): bool { return $this->isShipFrom; }
    public function setIsShipFrom(bool $isShipFrom): self { $this->isShipFrom = $isShipFrom; return $this; }
    public function isRemitTo(): bool { return $this->isRemitTo; }
    public function setIsRemitTo(bool $isRemitTo): self { $this->isRemitTo = $isRemitTo; return $this; }
    public function isReturnTo(): bool { return $this->isReturnTo; }
    public function setIsReturnTo(bool $isReturnTo): self { $this->isReturnTo = $isReturnTo; return $this; }

    /**
     * The purposes this row wears, for display.
     *
     * @return list<string>
     */
    public function purposeLabels(): array
    {
        $labels = [];
        if ($this->isOrderTo) { $labels[] = 'Order to'; }
        if ($this->isShipFrom) { $labels[] = 'Ship from'; }
        if ($this->isRemitTo) { $labels[] = 'Remit to'; }
        if ($this->isReturnTo) { $labels[] = 'Return to'; }

        return $labels;
    }

    /**
     * The address as one block of text, which is the shape a document snapshot stores it in.
     *
     * Rendered here rather than at each caller so that the copy frozen onto a purchase order and
     * the copy shown on the vendor screen cannot drift into two different layouts of the same
     * address.
     *
     * The contact block (#605) is prepended above the street, which is where a person reading a
     * printed document looks for it and where `company_address` puts it too. Every one of those
     * fields is empty on every address that predates them, so this renders byte-for-byte what it
     * rendered before for such a row — and no document already frozen is touched either way, since
     * a snapshot is a copy taken at issue time and never a live join.
     */
    public function toSnapshot(): string
    {
        $lines = array_filter([
            $this->companyName,
            $this->getContactName(),
            $this->getAddressLine1(),
            $this->addressLine2,
            trim(implode(' ', array_filter([
                trim($this->getCity() . ($this->province !== null && $this->province !== '' ? ', ' . $this->province : '')),
                (string) $this->postalCode,
            ]))),
            $this->getCountry(),
            $this->phone !== null && trim($this->phone) !== '' ? 'Tel ' . trim($this->phone) : null,
            $this->emailPrimary,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return implode("\n", $lines);
    }
}
