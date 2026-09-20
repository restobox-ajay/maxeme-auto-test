<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Party\PartyContact;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Repository\VendorContactRepository;

/**
 * A person at a supplier (#605) — the orders desk, accounts payable, the rep.
 *
 * The buy-side mirror of `App\Entity\CustomerUser`, MINUS EVERY AUTHENTICATION COLUMN. A vendor
 * contact is a record of somebody to phone or email, not an account: nobody from a supplier signs
 * in here, so there is nothing to hold a credential and nothing to authorise.
 *
 * Kept from `customer_user`: first name, last name, email, phone, status, created_at, and the FK to
 * the organisation. Added: `job_title` ("Orders desk", "Accounts payable") and `is_primary`, because
 * a supplier's three contacts are three different people doing three different jobs and a single
 * `vendor.email` cannot say which one a purchase order should go to.
 *
 * Dropped, deliberately and permanently: `password`, `roles`, `reset_token`,
 * `reset_token_expires_at`, `last_login_at`, `api_enabled`. This class MUST NOT implement
 * `UserInterface` or `PasswordAuthenticatedUserInterface`, must never appear as a provider in
 * `config/packages/security.yaml`, and must never gain a column that could be checked at a login
 * form. `VendorContactCarriesNoLoginIdentityTest` asserts each of those, so the boundary fails a
 * build rather than a review.
 *
 * That is also why this avoids the customer side's own awkwardness rather than copying it:
 * `customer_user` requires a password and a roles array even for a person who will never log in, so
 * recording a non-portal contact there means inventing a credential and setting the row Inactive.
 * Where no login exists at all, there is no reason to reproduce that.
 *
 * `status` still matters even with no sign-in to block: an Inactive contact is somebody who left,
 * and dropping out of the pickers is the whole of what it needs to do.
 *
 * ## What it DOES share with the sell side (#635)
 *
 * `App\Contract\Party\PartyContact` — an interface, and only ever an interface. Where the address
 * books and the note books collapsed into mapped superclasses, people could not: a shared parent
 * would put `password` and `roles` one inheritance edge from this table, so that a column added for
 * the sell side's benefit would silently arrive here. The interface carries five read-only
 * questions about a human being — name, email, phone, job title, primary flag — offers no setter,
 * and gives a security provider nothing it could use. That is the whole of the sharing.
 */
#[ORM\Entity(repositoryClass: VendorContactRepository::class)]
#[ORM\Table(name: 'vendor_contact')]
#[ORM\Index(name: 'idx_vendor_contact_vendor', fields: ['vendor'])]
class VendorContact implements PartyContact
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

    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\Column(name: 'first_name', length: 120, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', length: 120, nullable: true)]
    private ?string $lastName = null;

    /**
     * Not unique, and not a login identifier.
     *
     * `customer_user.email` carries a unique constraint because it IS the username the firewall
     * looks a person up by. Nothing looks anybody up by this one, and two people at a supplier
     * genuinely do share `orders@supplier.example` — a uniqueness rule here would refuse a true
     * fact about the world to protect an identity that does not exist.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $phone = null;

    /** "Orders desk", "Accounts payable", "Territory rep" — what they do, in their words. */
    #[ORM\Column(name: 'job_title', length: 120, nullable: true)]
    private ?string $jobTitle = null;

    /**
     * The one to reach first, and the fallback recipient when `vendor.email` is empty.
     *
     * At most one per vendor, enforced in VendorController rather than by a constraint for the
     * reason `vendor_address.is_default` already is: "who does a purchase order go to" has to have
     * one answer, and two rows claiming it would make the answer depend on row order.
     */
    #[ORM\Column(name: 'is_primary', options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): self { $this->firstName = $firstName; return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getJobTitle(): ?string { return $this->jobTitle; }
    public function setJobTitle(?string $jobTitle): self { $this->jobTitle = $jobTitle; return $this; }
    public function isPrimary(): bool { return $this->isPrimary; }
    public function setIsPrimary(bool $isPrimary): self { $this->isPrimary = $isPrimary; return $this; }
    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): self
    {
        $this->status = \in_array($status, self::statuses(), true) ? $status : self::STATUS_ACTIVE;

        return $this;
    }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    /**
     * A display name, falling back through what is actually filled in.
     *
     * A contact recorded as nothing but `orders@supplier.example` is a real and common case — the
     * orders desk often has no named person — so this never returns an empty string.
     */
    public function getName(): string
    {
        $name = trim(implode(' ', array_filter([$this->firstName, $this->lastName], static fn (?string $part): bool => $part !== null && trim($part) !== '')));

        if ($name !== '') {
            return $name;
        }

        if ($this->email !== null && trim($this->email) !== '') {
            return trim($this->email);
        }

        return $this->jobTitle !== null && trim($this->jobTitle) !== '' ? trim($this->jobTitle) : 'Unnamed contact';
    }

    /** Feeds AuditLogSubscriber's automatic label resolution, as Vendor::getLabel() does. */
    public function getLabel(): string
    {
        return $this->getName();
    }

    // ------------------------------------------------------------------ PartyContact (#635)

    /** The shared spelling of {@see getName()}. */
    public function getContactName(): string { return $this->getName(); }

    /** The shared spelling of {@see isPrimary()}. */
    public function isPrimaryContact(): bool { return $this->isPrimary; }
}
