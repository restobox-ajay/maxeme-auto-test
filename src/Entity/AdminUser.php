<?php

namespace App\Entity;

use App\Contract\Status\HasStatus;
use App\Repository\AdminUserRepository;
use App\Status\HasStatusSeamTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\Table(name: 'admin_user')]
#[ORM\UniqueConstraint(name: 'uniq_admin_user_email', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_admin_user_username', fields: ['username'])]
class AdminUser implements UserInterface, LegacyPasswordAuthenticatedUserInterface, HasStatus
{
    use HasStatusSeamTrait;

    public const STATUS_VOCABULARY = 'admin_user';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    /** Optional second login name beside the email; the legacy Maxeme app signed in by username. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $username = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    /**
     * Per-user salt of a password hash imported from the legacy Maxeme app (FOSUserBundle sha512).
     * Null for every password hashed here, and cleared once the imported hash is upgraded.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legacySalt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Whether this staff account may be picked as a company's `salesRepUser` (#718). A separate flag
     * rather than another entry in the coarse role list (Admin/Super Admin/Plant Staff/Tech Support):
     * those decide what the console lets someone DO, and "can be assigned as a rep" is orthogonal to
     * that — an Admin and a Plant Staff member can equally be the person a customer's account is
     * assigned to. Off by default, so the picker starts empty rather than offering every staff
     * account the day this ships.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $salesRepEligible = false;

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

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $username = $username !== null ? trim($username) : null;
        $this->username = $username !== '' ? $username : null;

        return $this;
    }

    public function getSalt(): ?string
    {
        return $this->legacySalt;
    }

    public function setLegacySalt(?string $legacySalt): self
    {
        $this->legacySalt = $legacySalt;

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
        $roles[] = 'ROLE_ADMIN';

        return array_values(array_unique($roles));
    }

    /** True for a plain `roles` check, without the forced ROLE_ADMIN getRoles() always appends. */
    public function hasSalesRepRole(): bool
    {
        return in_array('ROLE_SALES_REP', $this->roles, true);
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

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): self { $this->firstName = $firstName; return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; return $this; }
    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $phoneNumber): self { $this->phoneNumber = $phoneNumber; return $this; }
    public function getStatus(): string { return $this->status; }

    protected function readStatus(): string { return $this->status; }
    protected function writeStatus(string $status): void { $this->status = $status; }
    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $resetToken): self { $this->resetToken = $resetToken; return $this; }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable { return $this->resetTokenExpiresAt; }
    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): self { $this->resetTokenExpiresAt = $resetTokenExpiresAt; return $this; }
    // A Sales Rep account is eligible by definition — the role itself says so, without also
    // requiring the flag below to be ticked separately for the common case. The flag stays
    // read here too so an Admin/Plant Staff member can still be opted in individually, exactly
    // as before this role existed.
    public function isSalesRepEligible(): bool { return $this->salesRepEligible || $this->hasSalesRepRole(); }
    public function setSalesRepEligible(bool $salesRepEligible): self { $this->salesRepEligible = $salesRepEligible; return $this; }

    public function eraseCredentials(): void
    {
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
