<?php

namespace App\Entity;

use App\Repository\WarehouseRepository;
use App\Service\RegionSeedData;
use Doctrine\ORM\Mapping as ORM;

/**
 * A building that holds stock (#546), and where that building is (queue item 32).
 *
 * This used to be FulfillmentRegion and was doing two unrelated jobs: it was both the thing
 * inventory was counted against and the thing that decided which companies may buy, what guests
 * see and at what price. Stock sits in a building, not in a sales territory, so the commercial
 * half now lives on FulfillmentRegion and the two are joined by WarehouseFulfillmentRegion.
 *
 * Deliberately internal vocabulary: a customer never sees the word "Warehouse". They pick a
 * fulfillment region, and the region resolves to the warehouse whose stock answers for it.
 *
 * There is exactly one region per warehouse today — WarehouseFulfillmentRegion carries a unique
 * index that forces it — so every resolver here takes the single result. The table shape is
 * already many-to-many; enabling it is dropping that index plus an allocation decision, never a
 * data migration.
 *
 * ## The address, and why it is columns here rather than a table (queue item 32)
 *
 * Until this change a warehouse knew its name, its status and when it was created, and **nothing
 * about where it is**. The cost of that was paid on every vendor bill: `vendor_bill.tax_province`
 * was typed in by hand on each one because there was nowhere to read it from, so the same fact —
 * which province a building is in — was answered independently every time, and an `AB` typed onto
 * a Surrey warehouse's bill computed GST-only where it should have been GST+PST with nothing in
 * the system able to contradict it. That is the one-fact-in-two-places shape behind #589, #590 and
 * #591, with the writable copy being the wrong one. `VendorBill::$taxProvince`, `PurchaseOrder`
 * and `Rfq` each recorded the gap in a docblock and deferred it as a core change; this is that
 * change.
 *
 * **A warehouse has exactly ONE address.** It is not an address book, so there is no
 * `warehouse_address` table, no `label`, no `is_default`, and none of the four purpose flags
 * `VendorAddress` carries — those exist because a supplier's orders desk, dock, lockbox and returns
 * counter are genuinely four places. A building is one place. Giving it a book would mean inventing
 * the question "which of this warehouse's addresses is the one it is AT", which has no answer.
 *
 * So the columns live on `warehouse` itself. They are still the address vocabulary this application
 * already settled in #635 — `address_line1`, `address_line2`, `city`, `province`, `postal_code`,
 * `country`, spelled and sized exactly as `AbstractPartyAddress` and its `VendorAddress` overrides
 * spell and size them — rather than a fifth spelling of "postal code". What is NOT here is the
 * contact block (`first_name`, `phone`, `email_primary`, …) and `delivery_instructions`: those
 * answer "who do I speak to at this party" and "what should the driver do", which are questions
 * about a counterparty and a delivery, not about a building this company owns.
 *
 * `province` and `country` hold CODES ('BC', 'CA') and are normalised at the write boundary by the
 * setters below, which is the rule `App\Service\Region` states and `VendorAddress` follows: the
 * Context value objects compare 'BC' === 'BC' with no lookup. That normalisation is what makes this
 * column safe to derive a tax figure from.
 *
 * ## A warehouse may not exist without a province (queue item 61)
 *
 * That was not true when item 32 landed. Every address field was optional and "a warehouse with no
 * address is a legitimate state" was written here as a decision. It was the wrong one, and the cost
 * is recorded on item 61: item 37 made a purchase order derive its tax province from its warehouse,
 * so a province-less warehouse produces a document with no province, no calculator claims it, and
 * the tax is $0.00 — indistinguishable on the document from a genuine zero. A building is
 * somewhere; the fact is always knowable, so it is always required.
 *
 * **`province` and `country` are required at every WRITE boundary, and the columns stay nullable.**
 * Those are two different statements and both are deliberate:
 *
 * - Required at the boundary: `ConfigController::handleWarehouseForm()` refuses a create or an edit
 *   without a province and a country that resolve, and
 *   `WarehouseFulfillmentRegionService::createWarehouseForRegion()` takes both as arguments and
 *   throws rather than manufacture a row without them. Those are the only two ways a warehouse is
 *   born in this application.
 * - Nullable columns: rows written before item 61 hold nulls, and `NOT NULL` would strand a real
 *   business's data behind a validation it never agreed to — the row could not even be LOADED to be
 *   corrected. So an existing null-province warehouse still reads everywhere it reads today, is
 *   marked on the Warehouses list, and is refused only at the moment somebody tries to save it
 *   without answering the gap. Nothing backfills it; `docs/QUEUE.md` forbids writing to existing
 *   data, and there is nowhere honest to read a province from anyway.
 *
 * What a missing province costs is still stated where it is felt — `VendorBill::approve()` and
 * `PurchaseOrder::issue()` both refuse to commit money against taxable goods whose province is
 * unknown, and say so. Those guards are now the second line rather than the only one.
 *
 * The rest of the address is still optional in full: a street and a postal code are a courtesy on a
 * document, and nothing computes from them.
 */
#[ORM\Entity(repositoryClass: WarehouseRepository::class)]
#[ORM\Table(name: 'warehouse')]
class Warehouse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // ------------------------------------------------------------------ where it is (queue item 32)

    #[ORM\Column(name: 'address_line1', length: 255, nullable: true)]
    private ?string $addressLine1 = null;

    #[ORM\Column(name: 'address_line2', length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    /**
     * A province CODE, normalised on write. VARCHAR(8) to match `vendor_address.province` and the
     * `vendor_bill.tax_province` column this feeds, so a value copied from one to the other can
     * never be truncated on the way.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $province = null;

    #[ORM\Column(name: 'postal_code', length: 20, nullable: true)]
    private ?string $postalCode = null;

    /** An ISO 3166-1 alpha-2 code, normalised on write — `vendor_address.country`'s width. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getAddressLine1(): ?string { return $this->addressLine1; }
    public function setAddressLine1(?string $addressLine1): self { $this->addressLine1 = self::trimmedOrNull($addressLine1); return $this; }

    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $addressLine2): self { $this->addressLine2 = self::trimmedOrNull($addressLine2); return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = self::trimmedOrNull($city); return $this; }

    public function getProvince(): ?string { return $this->province; }

    /**
     * Normalised at the write boundary, exactly as `VendorBill::deriveTaxProvinceFrom()` is and through the
     * same resolver: 'bc', 'BC' and 'British Columbia' all store 'BC', and a blank stores null so
     * that "nobody said" is one state rather than two.
     *
     * Country-agnostic because the country field may not have been filled in yet — an admin typing
     * a Canadian province into a form whose country box is still empty should not silently lose it.
     * Anything that resolves to nothing is stored as null rather than kept as typed: this column
     * decides a tax figure, and a province nothing recognises would decide it wrongly and silently.
     *
     * **That last sentence was false until queue item 61.** `resolveProvinceAnyCountry()` hands back
     * the typed string uppercased when it recognises nothing, so `'XX'` was stored as `'XX'` — a
     * value that is not null, therefore passes `PurchaseOrder::assertTaxProvinceKnownIfTaxable()`
     * and `VendorBill::approve()`'s equivalent, and is then claimed by no calculator. That is a
     * zero derived from nothing arriving through the gate built to stop it.
     * `knownProvinceAnyCountry()` is the strict resolver, and this setter uses it.
     */
    public function setProvince(?string $province): self
    {
        $this->province = RegionSeedData::knownProvinceAnyCountry(trim((string) $province));

        return $this;
    }

    public function getPostalCode(): ?string { return $this->postalCode; }
    public function setPostalCode(?string $postalCode): self { $this->postalCode = self::trimmedOrNull($postalCode); return $this; }

    public function getCountry(): ?string { return $this->country; }

    /** Normalised the same way and for the same reason as the province. */
    public function setCountry(?string $country): self
    {
        $resolved = RegionSeedData::resolveCountry(trim((string) $country));
        $this->country = $resolved !== null && $resolved !== '' ? $resolved : null;

        return $this;
    }

    /**
     * True when anything at all has been recorded about where this building is.
     *
     * Deliberately "anything", not "enough to print": screens use it to decide whether to show an
     * address block or an empty state, and a warehouse with only a city recorded has still had
     * something said about it. Whether the address is good enough to derive TAX from is a narrower
     * question with its own answer — {@see getProvince()}, which is the only field any calculation
     * reads.
     */
    public function hasAddress(): bool
    {
        foreach ([$this->addressLine1, $this->addressLine2, $this->city, $this->province, $this->postalCode, $this->country] as $part) {
            if ($part !== null && $part !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The address on one line, for a list cell or a select label. Empty string when there is none,
     * so a template can print it with a `|default('—')` rather than branching.
     */
    public function getAddressSummary(): string
    {
        return implode(', ', array_filter(
            [$this->addressLine1, $this->addressLine2, $this->city, $this->province, $this->postalCode],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
