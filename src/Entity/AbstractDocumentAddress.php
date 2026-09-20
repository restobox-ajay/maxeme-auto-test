<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\TextInput;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of an address as it stood when a document was created.
 *
 * An order is a record of what happened. Its line items already work that way — SalesOrderLine has
 * its own name, sku, price and subtotal, so renaming or repricing a product never rewrites history —
 * and so do its money fields, its tax/fee breakdowns and its fulfilment region label. Addresses did
 * not: they were a ManyToOne to CompanyAddress, so a customer editing their address book silently
 * changed what every historical invoice said they had shipped to.
 *
 * Worse, AbstractSalesDocument::getEffectiveBillingAddress() fell back to
 * $this->company->getDefaultBillingAddress() when the foreign key was null, so a document could
 * print today's default address rather than showing nothing — an invoice asserting goods went
 * somewhere they never went.
 *
 * That matters most for estimates: the team prices a quote against a specific address, and if it
 * moves across town before acceptance, the quote was priced for the wrong place.
 *
 * Concrete subclasses add their own NOT NULL parent foreign key rather than sharing one polymorphic
 * table, mirroring how SalesOrderLine and EstimateLine are already separate. That lets the database
 * enforce "belongs to exactly one document" instead of a nullable pair plus a CHECK constraint, and
 * gives ON DELETE CASCADE for free.
 *
 * sourceAddress is provenance only — "this was copied from book entry #12", useful for
 * reorder-to-same-address and reporting. It is never read for rendering. Reading it would reintroduce
 * the very bug this class removes.
 */
#[ORM\MappedSuperclass]
abstract class AbstractDocumentAddress
{
    public const TYPE_BILLING = 'billing';
    public const TYPE_SHIPPING = 'shipping';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    /** self::TYPE_BILLING or self::TYPE_SHIPPING. Unique per document, enforced by the subclass. */
    #[ORM\Column(length: 16)]
    protected string $type = self::TYPE_SHIPPING;

    /**
     * The address-book row this was copied from, if any. Nullable because an address typed straight
     * onto an order has no book entry, and because the entry may later be deleted.
     */
    #[ORM\ManyToOne(targetEntity: CompanyAddress::class)]
    #[ORM\JoinColumn(name: 'source_address_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?CompanyAddress $sourceAddress = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $firstName = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $lastName = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $companyName = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $emailPrimary = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $emailSecondary = null;

    #[ORM\Column(length: 40, nullable: true)]
    protected ?string $phone = null;

    #[ORM\Column(length: 40, nullable: true)]
    protected ?string $fax = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $addressLine1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $addressLine2 = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $city = null;

    /** A geo_province code, validated on write. Stored as a plain string, deliberately not a FK. */
    #[ORM\Column(length: 6, nullable: true)]
    protected ?string $province = null;

    /** A geo_country code. */
    #[ORM\Column(length: 2, nullable: true)]
    protected ?string $country = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $postalCode = null;

    /**
     * Frozen with the rest: instructions given at order time are what the warehouse should follow,
     * not whatever the customer has edited since.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $deliveryInstructions = null;

    public function getId(): ?int { return $this->id; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function isBilling(): bool { return $this->type === self::TYPE_BILLING; }
    public function isShipping(): bool { return $this->type === self::TYPE_SHIPPING; }

    public function getSourceAddress(): ?CompanyAddress { return $this->sourceAddress; }
    public function setSourceAddress(?CompanyAddress $sourceAddress): static { $this->sourceAddress = $sourceAddress; return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getCompanyName(): ?string { return $this->companyName; }
    public function setCompanyName(?string $companyName): static { $this->companyName = $companyName; return $this; }

    public function getEmailPrimary(): ?string { return $this->emailPrimary; }
    public function setEmailPrimary(?string $emailPrimary): static { $this->emailPrimary = $emailPrimary; return $this; }

    public function getEmailSecondary(): ?string { return $this->emailSecondary; }
    public function setEmailSecondary(?string $emailSecondary): static { $this->emailSecondary = $emailSecondary; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }

    public function getFax(): ?string { return $this->fax; }
    public function setFax(?string $fax): static { $this->fax = $fax; return $this; }

    public function getAddressLine1(): ?string { return $this->addressLine1; }
    public function setAddressLine1(?string $addressLine1): static { $this->addressLine1 = $addressLine1; return $this; }

    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $addressLine2): static { $this->addressLine2 = $addressLine2; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): static { $this->city = $city; return $this; }

    public function getProvince(): ?string { return $this->province; }
    public function setProvince(?string $province): static { $this->province = $province; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): static { $this->country = $country; return $this; }

    public function getPostalCode(): ?string { return $this->postalCode; }
    public function setPostalCode(?string $postalCode): static { $this->postalCode = $postalCode; return $this; }

    public function getDeliveryInstructions(): ?string { return $this->deliveryInstructions; }
    public function setDeliveryInstructions(?string $deliveryInstructions): static { $this->deliveryInstructions = $deliveryInstructions; return $this; }

    /**
     * True when this row records only which address-book entry it points at, with nothing copied.
     *
     * That is a cart: it names an address without freezing it, because a cart is not yet a record of
     * anything and its address should follow the customer's edits until conversion. Distinguishing
     * that from a real snapshot is what lets AbstractSalesDocument::getEffectiveShippingAddress()
     * resolve live for one and never for the other. sourceAddress and type are excluded because they
     * are the link itself, not copied content.
     */
    public function isLinkOnly(): bool
    {
        foreach ([
            $this->firstName, $this->lastName, $this->companyName, $this->emailPrimary,
            $this->emailSecondary, $this->phone, $this->fax, $this->addressLine1,
            $this->addressLine2, $this->city, $this->province, $this->country,
            $this->postalCode, $this->deliveryInstructions,
        ] as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Convenience for templates that print a contact line. */
    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    /**
     * Copy every field from an address-book row, recording it as the source.
     *
     * Delivery instructions are re-capped on the way in rather than copied straight across, and
     * they are the only field here that is: every write path now bounds them at
     * TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH, but nothing did before today and the column is a
     * CLOB with no migration behind it, so any address row created before this change can still be
     * holding a value of arbitrary size. Without this the cap would be a rule about new input only,
     * and a legacy row would keep minting fresh over-length snapshots onto every new order placed
     * against it — the one case where an uncapped value enters a document without anybody typing
     * it.
     */
    public function copyFrom(CompanyAddress $address): static
    {
        return $this
            ->setSourceAddress($address)
            ->setFirstName($address->getFirstName())
            ->setLastName($address->getLastName())
            ->setCompanyName($address->getCompanyName())
            ->setEmailPrimary($address->getEmailPrimary())
            ->setEmailSecondary($address->getEmailSecondary())
            ->setPhone($address->getPhone())
            ->setFax($address->getFax())
            ->setAddressLine1($address->getAddressLine1())
            ->setAddressLine2($address->getAddressLine2())
            ->setCity($address->getCity())
            ->setProvince($address->getProvince())
            ->setCountry($address->getCountry())
            ->setPostalCode($address->getPostalCode())
            ->setDeliveryInstructions(TextInput::nullableStringMax(
                $address->getDeliveryInstructions(),
                TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH
            ));
    }

    /**
     * Copy from another snapshot — used when an estimate is converted into an order.
     *
     * The order must inherit the estimate's frozen address, not re-resolve from the address book:
     * re-resolving would reintroduce exactly the drift this exists to prevent, quoting one address
     * and shipping to another.
     *
     * Delivery instructions are re-capped for the same reason as in copyFrom(): a quote written
     * before today, or one whose snapshot was itself copied from a legacy address row, can be
     * carrying an over-length value that conversion would otherwise propagate into the order.
     */
    public function copyFromSnapshot(self $other): static
    {
        return $this
            ->setSourceAddress($other->getSourceAddress())
            ->setFirstName($other->getFirstName())
            ->setLastName($other->getLastName())
            ->setCompanyName($other->getCompanyName())
            ->setEmailPrimary($other->getEmailPrimary())
            ->setEmailSecondary($other->getEmailSecondary())
            ->setPhone($other->getPhone())
            ->setFax($other->getFax())
            ->setAddressLine1($other->getAddressLine1())
            ->setAddressLine2($other->getAddressLine2())
            ->setCity($other->getCity())
            ->setProvince($other->getProvince())
            ->setCountry($other->getCountry())
            ->setPostalCode($other->getPostalCode())
            ->setDeliveryInstructions(TextInput::nullableStringMax(
                $other->getDeliveryInstructions(),
                TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH
            ));
    }
}
