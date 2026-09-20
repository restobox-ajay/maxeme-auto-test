<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Service\TextInput;
use Doctrine\ORM\Mapping as ORM;

/**
 * A frozen copy of a vendor address as it stood when a purchase document recorded it — the
 * buy-side twin of `App\Entity\AbstractDocumentAddress`, field for field, adapted only where the
 * source book genuinely differs: this one copies from `VendorAddress`, not `CompanyAddress`.
 *
 * Concrete subclasses add their own NOT NULL parent foreign key rather than sharing one
 * polymorphic table, exactly as the sell side's does — {@see PurchaseOrderAddress} and
 * {@see VendorBillAddress} — which lets the database enforce "belongs to exactly one document"
 * and gives ON DELETE CASCADE for free.
 *
 * `sourceAddress` is provenance only, same rule as the sell side's: "this was copied from book
 * entry #12", never read for rendering. Reading it for display would reintroduce the exact
 * live-join drift this class exists to remove — a vendor editing their address book must not
 * silently change what an already-raised purchase order or bill says.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPurchaseDocumentAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    /** One of the concrete class's own TYPE_* constants. Unique per document, enforced there. */
    #[ORM\Column(length: 16)]
    protected string $type = '';

    /**
     * The address-book row this was copied from, if any. Nullable because an address typed
     * straight onto a document has no book entry, and because the entry may later be deleted.
     */
    #[ORM\ManyToOne(targetEntity: VendorAddress::class)]
    #[ORM\JoinColumn(name: 'source_address_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?VendorAddress $sourceAddress = null;

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

    /** Frozen with the rest: instructions given at document time, not whatever is on file now. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $deliveryInstructions = null;

    public function getId(): ?int { return $this->id; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getSourceAddress(): ?VendorAddress { return $this->sourceAddress; }
    public function setSourceAddress(?VendorAddress $sourceAddress): static { $this->sourceAddress = $sourceAddress; return $this; }

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
     * True when this row records only which address-book entry it points at, with nothing copied
     * — see `AbstractDocumentAddress::isLinkOnly()` for the reasoning this mirrors exactly.
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

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    /** Copy every field from a vendor's address-book row, recording it as the source. */
    public function copyFrom(VendorAddress $address): static
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

    /** The address as one block of text — the shape a document's own printed snapshot needs. */
    public function toSnapshot(): string
    {
        $lines = array_filter([
            $this->companyName,
            $this->getFullName(),
            $this->addressLine1,
            $this->addressLine2,
            trim(implode(' ', array_filter([
                trim((string) $this->city . ($this->province !== null && $this->province !== '' ? ', ' . $this->province : '')),
                (string) $this->postalCode,
            ]))),
            $this->country,
            $this->phone !== null && trim($this->phone) !== '' ? 'Tel ' . trim($this->phone) : null,
            $this->emailPrimary,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return implode("\n", $lines);
    }
}
