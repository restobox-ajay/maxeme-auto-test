<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One entry in a party's address book (#635) — a customer's or a supplier's, the same shape either way.
 *
 * ## Why this exists
 *
 * `company_address` and `vendor_address` describe the same thing and, until this class, spelled it
 * differently: `address_line1` against `address_1`, `phone` against `contact_phone`. Thirteen shared
 * concepts, two vocabularies, on tables that sit six inches apart. Anybody reading one side and then
 * the other had to translate, and every new consumer — a document snapshot, a CSV export, an API
 * field list — had to be written twice.
 *
 * ## Not the same thing as AbstractDocumentAddress
 *
 * `AbstractDocumentAddress` is the other mapped superclass in this directory and it is emphatically
 * NOT this one. That one is for FROZEN COPIES: `InvoiceAddress`, `EstimateAddress`, `CartAddress`,
 * `SalesOrderAddress`, `CreditMemoAddress` are what a document said on the day it was raised, and
 * editing an address book entry must never change a word of them. This one is the LIVE book those
 * copies are taken from. Two superclasses because they are two ideas: a document address carries a
 * `type` discriminator (billing/shipping) and belongs to exactly one document; a party address
 * carries a `label` a human chose and outlives every document taken from it.
 *
 * ## The column names are the customer side's, deliberately
 *
 * Every column here already existed on `company_address` under exactly this name, so adopting them
 * costs core NOTHING: `CompanyAddress` drops the declarations it now inherits and `company_address`
 * needs no migration at all. All the renaming lands on `vendor_address`, a table introduced by #605
 * that holds six rows on dev and has never been deployed to production. Doing it the other way —
 * moving core's schema to meet the newer table — would have meant a rebuild of the busiest address
 * table in the application to tidy a name.
 *
 * ## What deliberately does NOT live here
 *
 * The owning FK, and anything that answers "what is this address FOR". Those are the two places the
 * sides genuinely differ, and flattening them would be inventing a shared concept rather than
 * finding one:
 *
 *   - `CompanyAddress` keeps `is_default_billing` / `is_default_shipping` — a sell-side document
 *     asks "where do we bill, where do we ship".
 *   - `VendorAddress` keeps `is_default` plus `is_order_to` / `is_ship_from` / `is_remit_to` /
 *     `is_return_to` — a purchase order asks four different questions and #606 explains why they are
 *     four flags rather than one enum.
 *   - `CompanyAddress` keeps `created_at`. `vendor_address` has never had it, and giving it one
 *     would mean writing a value into six existing rows that nobody recorded.
 *
 * ## Widths stay each side's own
 *
 * The declarations below are `company_address`'s. `VendorAddress` narrows five of them back with
 * `#[ORM\AttributeOverrides]` — `province VARCHAR(8)` and `country VARCHAR(2)` hold CODES on the buy
 * side, which #605 argued for at length and this refactor does not get to overturn as a side effect.
 * A shared VOCABULARY is the point; identical storage was never the point.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPartyAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    /** "Head office", "Mississauga warehouse" — what a person calls it, not what a system calls it. */
    #[ORM\Column(length: 80, nullable: true)]
    protected ?string $label = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $firstName = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $lastName = null;

    /**
     * The name to address the location as, when it is not the party's own.
     *
     * Carries its weight on a supplier's remit-to: a factoring company or a parent's accounts
     * department is frequently a different legal entity from the one named on the purchase order,
     * and paying the name on the document is how money goes to the wrong company.
     */
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

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $province = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $country = null;

    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $postalCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $deliveryInstructions = null;

    public function getId(): ?int { return $this->id; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): static { $this->label = $label; return $this; }

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
     * The person to ask for at this location, or null when nobody is recorded.
     *
     * Derived from the two shared name columns and nothing else, which is why it can live up here:
     * both sides answer it the same way, and neither side had a reason to answer it differently.
     */
    public function getContactName(): ?string
    {
        $name = trim(implode(' ', array_filter(
            [$this->firstName, $this->lastName],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        )));

        return $name === '' ? null : $name;
    }
}
