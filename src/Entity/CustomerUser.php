<?php

namespace App\Entity;

use App\Contract\Party\PartyContact;
use App\Contract\Status\HasStatus;
use App\Repository\CustomerUserRepository;
use App\Status\HasStatusSeamTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person at a customer — and, unlike a vendor contact, an identity the firewall authenticates.
 *
 * `PartyContact` (#635) is the shape this shares with the procurement bundle's vendor contact:
 * name, email, phone, job title, primary flag. It is an INTERFACE and not a mapped superclass
 * precisely because of the columns declared below — `password`, `roles`, `resetToken`,
 * `resetTokenExpiresAt`, `lastLoginAt`, `apiEnabled`. A shared parent would put every one of them
 * one inheritance edge away from `vendor_contact`, where nobody signs in and nothing may.
 */
#[ORM\Entity(repositoryClass: CustomerUserRepository::class)]
#[ORM\Table(name: 'customer_user')]
#[ORM\UniqueConstraint(name: 'uniq_customer_user_email', fields: ['email'])]
class CustomerUser implements UserInterface, PasswordAuthenticatedUserInterface, PartyContact, HasStatus
{
    use HasStatusSeamTrait;

    public const STATUS_VOCABULARY = 'customer_user';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    /**
     * Whether an admin permits THIS user to hold an API key, inside a company that is itself
     * permitted (Company::$apiEnabled). Off by default — API access is granted, never assumed.
     *
     * Deliberately not derived from role. An owner is not automatically an API user and a staff
     * member is not automatically barred; an admin decides per person. What the key can then do is
     * settled entirely by that person's own permissions, since a request carrying it is
     * authenticated as them — so a staff member's key is bound by the same limits #522 put on staff.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $apiEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_CUSTOMER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getCompany(): ?Company { return $this->company; }
    public function setCompany(?Company $company): self { $this->company = $company; return $this; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): self { $this->firstName = $firstName; return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; return $this; }
    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $phoneNumber): self { $this->phoneNumber = $phoneNumber; return $this; }

    // ------------------------------------------------------------------ PartyContact (#635)
    //
    // Reads of what is already stored, adding no column and no state. `getPhone()` is the shared
    // spelling of `phoneNumber`; the two below answer honestly that the sell side does not record
    // a job title or a primary contact — its people are portal logins, told apart by `roles`.

    /** The shared spelling of {@see getPhoneNumber()}. */
    public function getPhone(): ?string { return $this->phoneNumber; }

    /**
     * A display name that is never empty.
     *
     * NOT called `getName()`: `AuditLogSubscriber` resolves an entity's audit label by trying
     * `getName()` before `getEmail()`, so adding one here would relabel every existing customer-user
     * entry in the audit log. Sharing a vocabulary must not move data that already exists.
     */
    public function getContactName(): string
    {
        $name = trim(implode(' ', array_filter(
            [$this->firstName, $this->lastName],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        )));

        return $name !== '' ? $name : trim($this->email);
    }

    /** Not recorded on the sell side. */
    public function getJobTitle(): ?string { return null; }

    /** Not recorded on the sell side — a customer's users are told apart by `roles`, not by rank. */
    public function isPrimaryContact(): bool { return false; }

    public function getStatus(): string { return $this->status; }

    protected function readStatus(): string { return $this->status; }
    protected function writeStatus(string $status): void { $this->status = $status; }

    public function isApiEnabled(): bool { return $this->apiEnabled; }
    public function setApiEnabled(bool $apiEnabled): self { $this->apiEnabled = $apiEnabled; return $this; }

    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $resetToken): self { $this->resetToken = $resetToken; return $this; }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable { return $this->resetTokenExpiresAt; }
    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): self { $this->resetTokenExpiresAt = $resetTokenExpiresAt; return $this; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self { $this->lastLoginAt = $lastLoginAt; return $this; }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
