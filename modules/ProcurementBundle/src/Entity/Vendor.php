<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\VendorRepository;

/**
 * Who we buy from (#555).
 *
 * The buy-side counterpart of `Company`, and a separate table rather than a flag on that one: a
 * company is a customer with a price list, a fulfillment region and credit terms we extend, and a
 * vendor is a supplier with an account number *they* gave *us* and terms they extend to us. The two
 * share a name and an address and nothing else.
 *
 * `paymentTerm` is a plain string naming a row in the existing raw-SQL `payment_term` table rather
 * than a foreign key into it. That table is admin-managed outside the ORM (it is one of the tables
 * `RawSqlTablesSurviveTheChainTest` protects), and a term is copied onto every document raised
 * against this vendor as a snapshot anyway — so the live value here is a default for new documents,
 * not the source of truth for any existing one.
 *
 * `paymentTermId` (#605) sits beside it and holds `payment_term.id`, which is precisely the shape
 * `company.payment_term_id` has: a plain nullable INTEGER, not a Doctrine association, because the
 * target table has no entity to associate with and this bundle must not put one over a table core's
 * own screens own. Which is also why BOTH columns exist rather than one replacing the other — the
 * id is what a dropdown selects and what survives a term being renamed, and the name is what every
 * document snapshots and what a vendor imported before ids existed still holds. Saving from the
 * screen writes both; NOTHING backfills the id for a vendor whose term was only ever free text,
 * because "Net 30 EOM" typed by hand is not automatically the "Net 30" row and guessing which row a
 * human meant is a mapping a human does.
 *
 * ## Contacts, notes and addresses (#605)
 *
 * `contacts` is the buy-side mirror of `customer_user` MINUS EVERY AUTHENTICATION COLUMN — see
 * VendorContact. Nobody at a supplier signs in here, so a contact is a record of a person and never
 * an account.
 *
 * `notes` is the mirror of `company_note`: one row per note, with an author and a timestamp, and
 * since item 43 it is the ONLY place a vendor's notes live.
 *
 * There used to be a second one. A single `vendor.notes` CLOB sat beside the rows — the record of
 * what was written before there was anywhere better to put it — and the screen carried both, one
 * box labelled "Notes (legacy field)" above a threaded list. `Company` has never had the plain
 * column at all, so "notes, just like the customer side" means one threaded list and not two boxes,
 * and the owner ruled the second one off the record.
 *
 * It was NOT deleted. `Version20260913090000` copies whatever each vendor's CLOB held into a
 * `vendor_note` row of its own, authored "Migrated from the old notes field" and dated the day the
 * migration ran, and only then rebuilds the table without the column. Dropping the text instead
 * would have destroyed the one thing the column was being kept for. The migration guesses nothing:
 * a CLOB becomes exactly one note, because where one note ended and the next began was never
 * recorded and inventing the boundaries would be inventing notes.
 *
 * Every document snapshots `name` at the moment it is raised and never joins back here to display
 * it. Renaming a vendor must not rewrite a three-year-old bill; the sell side learned this already
 * and has docs/plans/2026-07-30-company-identity-snapshot.md to show for it.
 */
#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendor')]
#[ORM\Index(name: 'idx_vendor_name', fields: ['name'])]
#[ORM\Index(name: 'idx_vendor_status', fields: ['status'])]
class Vendor
{
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_INACTIVE];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $name = '';

    /** Our account number with them, which is what appears on their paperwork. */
    #[ORM\Column(name: 'account_number', length: 80, nullable: true)]
    private ?string $accountNumber = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $phone = null;

    /** Names a row in the raw-SQL `payment_term` table; see the class docblock for why it is not an FK. */
    #[ORM\Column(name: 'payment_term', length: 80, nullable: true)]
    private ?string $paymentTerm = null;

    /**
     * `payment_term.id`, exactly as `company.payment_term_id` holds it — a plain nullable INTEGER
     * and not an association, for the reason in the class docblock. NULL means "no term chosen from
     * the list", which is what every vendor that exists today means.
     */
    #[ORM\Column(name: 'payment_term_id', nullable: true)]
    private ?int $paymentTermId = null;

    /**
     * What this vendor bills us in. Stored per vendor and copied onto every document; nothing here
     * converts between currencies, which is a deliberate deferral rather than an oversight — see
     * the plan's "Currency" note. A document is read in the currency it was raised in.
     */
    #[ORM\Column(length: 3, options: ['default' => 'CAD'])]
    private string $currency = 'CAD';

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, VendorAddress> */
    #[ORM\OneToMany(targetEntity: VendorAddress::class, mappedBy: 'vendor', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['isDefault' => 'DESC', 'id' => 'ASC'])]
    private Collection $addresses;

    /** @var Collection<int, VendorContact> */
    #[ORM\OneToMany(targetEntity: VendorContact::class, mappedBy: 'vendor', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['isPrimary' => 'DESC', 'id' => 'ASC'])]
    private Collection $contacts;

    /**
     * @var Collection<int, VendorNote>
     *
     * Mapped as a cascading collection where `company_note` is not, because a vendor CAN be deleted
     * — #613 allows it for a row nothing was ever raised against — and a vendor's notes have to go
     * with it through the ORM rather than relying on SQLite enforcing the FK, which it only does
     * when `PRAGMA foreign_keys` is on. A company is never deleted, so CompanyNote never had to
     * answer this.
     */
    #[ORM\OneToMany(targetEntity: VendorNote::class, mappedBy: 'vendor', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $noteEntries;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->addresses = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->noteEntries = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getAccountNumber(): ?string { return $this->accountNumber; }
    public function setAccountNumber(?string $accountNumber): self { $this->accountNumber = $accountNumber; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getPaymentTerm(): ?string { return $this->paymentTerm; }
    public function setPaymentTerm(?string $paymentTerm): self { $this->paymentTerm = $paymentTerm; return $this; }
    public function getPaymentTermId(): ?int { return $this->paymentTermId; }

    /** 0 and negatives read as "none chosen", so a blank dropdown option cannot store a phantom id. */
    public function setPaymentTermId(?int $paymentTermId): self
    {
        $this->paymentTermId = ($paymentTermId !== null && $paymentTermId > 0) ? $paymentTermId : null;

        return $this;
    }

    public function getCurrency(): string { return $this->currency; }

    /** Coerced at the boundary, like every other constrained string in this codebase's entities. */
    public function setCurrency(string $currency): self
    {
        $currency = strtoupper(trim($currency));
        $this->currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'CAD';

        return $this;
    }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = \in_array($status, self::statuses(), true) ? $status : self::STATUS_ACTIVE;

        return $this;
    }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    /** @return Collection<int, VendorAddress> */
    public function getAddresses(): Collection { return $this->addresses; }

    public function addAddress(VendorAddress $address): self
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
            $address->setVendor($this);
        }

        return $this;
    }

    public function removeAddress(VendorAddress $address): self
    {
        $this->addresses->removeElement($address);

        return $this;
    }

    public function getDefaultAddress(): ?VendorAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->isDefault()) {
                return $address;
            }
        }

        return $this->addresses->first() ?: null;
    }

    /**
     * The address wearing one purpose (#606), FALLING BACK TO THE DEFAULT.
     *
     * The fallback is the whole reason nothing breaks the day purposes ship: a vendor whose single
     * address has no flag set answers every one of the four questions with that address, which is
     * exactly what `getDefaultAddress()` answered before there were four questions.
     */
    private function addressFor(callable $wearsPurpose): ?VendorAddress
    {
        foreach ($this->addresses as $address) {
            if ($wearsPurpose($address) === true) {
                return $address;
            }
        }

        return $this->getDefaultAddress();
    }

    /** Where the purchase order goes. Frozen onto the PO at issue; see PurchaseOrder::issue(). */
    public function getOrderToAddress(): ?VendorAddress
    {
        return $this->addressFor(static fn (VendorAddress $a): bool => $a->isOrderTo());
    }

    /** Where goods left from. Frozen onto the receipt when it is booked. */
    public function getShipFromAddress(): ?VendorAddress
    {
        return $this->addressFor(static fn (VendorAddress $a): bool => $a->isShipFrom());
    }

    /** Where the money goes. Frozen onto the bill, which is the document that leads to payment. */
    public function getRemitToAddress(): ?VendorAddress
    {
        return $this->addressFor(static fn (VendorAddress $a): bool => $a->isRemitTo());
    }

    /** Where a vendor return is sent. Nothing reads this yet; vendor returns are unbuilt (#586/#596's buy-side mirror). */
    public function getReturnToAddress(): ?VendorAddress
    {
        return $this->addressFor(static fn (VendorAddress $a): bool => $a->isReturnTo());
    }

    /** @return Collection<int, VendorContact> */
    public function getContacts(): Collection { return $this->contacts; }

    public function addContact(VendorContact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setVendor($this);
        }

        return $this;
    }

    public function removeContact(VendorContact $contact): self
    {
        $this->contacts->removeElement($contact);

        return $this;
    }

    /** The contact flagged primary, or the first Active one — never an Inactive one as a fallback. */
    public function getPrimaryContact(): ?VendorContact
    {
        foreach ($this->contacts as $contact) {
            if ($contact->isPrimary() && $contact->isActive()) {
                return $contact;
            }
        }

        foreach ($this->contacts as $contact) {
            if ($contact->isActive()) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Who a purchase order is emailed to: `vendor.email` first, then an Active contact's address.
     *
     * The reason #605 exists in the first place — on the old dev data 118 of 124 vendors had no
     * `vendor.email`, so "Send PO to vendor" was refused for 95% of the file even where somebody
     * had the orders desk's address written down. `vendor.email` still wins when it is set: it is
     * the address somebody deliberately put on the vendor record, and a contact list is not a
     * reason to start ignoring it.
     */
    public function getOrderEmail(): ?string
    {
        $own = trim((string) $this->email);
        if ($own !== '') {
            return $own;
        }

        $primary = $this->getPrimaryContact();
        if ($primary instanceof VendorContact && trim((string) $primary->getEmail()) !== '') {
            return trim((string) $primary->getEmail());
        }

        foreach ($this->contacts as $contact) {
            if ($contact->isActive() && trim((string) $contact->getEmail()) !== '') {
                return trim((string) $contact->getEmail());
            }
        }

        return null;
    }

    /** @return Collection<int, VendorNote> */
    public function getNoteEntries(): Collection { return $this->noteEntries; }

    public function addNoteEntry(VendorNote $note): self
    {
        if (!$this->noteEntries->contains($note)) {
            $this->noteEntries->add($note);
            $note->setVendor($this);
        }

        return $this;
    }

    public function removeNoteEntry(VendorNote $note): self
    {
        $this->noteEntries->removeElement($note);

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->name !== '' ? $this->name : '#' . (string) $this->id;
    }
}
