<?php

namespace App\Entity;

use App\Contract\Status\HasStatus;
use App\Repository\CompanyRepository;
use App\Status\HasStatusSeamTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'company')]
// Deliberately a plain index, NOT unique: code holds an externally-assigned identifier, so this
// system does not get to decide that two companies cannot share one. The index is here for lookups
// (search/filter, CompanyCodeGenerator's collision probe), not to enforce anything.
#[ORM\Index(name: 'idx_company_code', fields: ['code'])]
class Company implements HasStatus
{
    use HasStatusSeamTrait;

    public const STATUS_VOCABULARY = 'company';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    /**
     * Externally-assigned company identifier (ERP/accounting code). Optional — plenty of customers
     * have no such code — and not unique, because it is not ours to deduplicate. Nullable rather
     * than '' so "no external ID" has one representation, matching every other optional field here.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $code = null;

    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryEmail = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tradeName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $businessLicense = null;

    /**
     * Free text a prospect typed into the "Sales Rep (optional)" box at registration — who they said
     * referred them, not a real account. Kept, unrenamed at the column, purely as a hint for whoever
     * assigns `$salesRepUser` below; nothing reads it to decide anything (#718).
     */
    #[ORM\Column(name: 'sales_rep', length: 120, nullable: true)]
    private ?string $salesRepNote = null;

    /**
     * The real, internal assignment (#718) — a staff account, not a name typed into a box. Nullable:
     * unassigned is a normal, common state, not an error. `SET NULL` on delete rather than a hard FK
     * failure, because deleting a staff account must not be blocked by, or silently take down, every
     * company that account used to manage.
     */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'sales_rep_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $salesRepUser = null;

    #[ORM\Column(length: 40)]
    private string $accountType = 'Business';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(nullable: true)]
    private ?int $paymentTermId = null;

    /**
     * The ceiling on this company's AR exposure — outstanding invoice balances, not orders or
     * quotes — before order placement warns and asks for a reason (#724).
     *
     * Nullable, and NULL means unlimited, matching every other optional constraint in this app
     * (safety stock, reorder quantity, …): a company nobody has set a limit for is not silently
     * capped at zero.
     *
     * Decimal-as-string, same convention `Invoice::getTotal()`/`getBalance()` use, so the two figures
     * a credit check compares are typed the same way rather than one being a float and one a string.
     */
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $creditLimit = null;

    /**
     * Whether anyone at this company may hold an API key. Admin-controlled, and the outer of the
     * two gates — CustomerUser::$apiEnabled is the inner one. Off by default: API access is granted,
     * never assumed.
     *
     * Deliberately separate from the keys themselves (App\Entity\ApiCredential), so switching a
     * company off takes effect on the next request without iterating or mutating anybody's key, and
     * switching it back on restores every existing key with nothing to regenerate.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $apiEnabled = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, CompanyAddress> */
    #[ORM\OneToMany(
        targetEntity: CompanyAddress::class,
        mappedBy: 'company',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $addresses;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->addresses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * Blank input is stored as NULL so "no external ID" is a single representation — callers
     * (admin form, importers, tests) may pass '' or whitespace interchangeably.
     */
    public function setCode(?string $code): self
    {
        $code = trim((string) $code);
        $this->code = $code !== '' ? $code : null;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    protected function readStatus(): string
    {
        return $this->status;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getPrimaryEmail(): ?string
    {
        return $this->primaryEmail;
    }

    public function setPrimaryEmail(?string $primaryEmail): self
    {
        $this->primaryEmail = $primaryEmail;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getTradeName(): ?string
    {
        return $this->tradeName;
    }

    public function setTradeName(?string $tradeName): self
    {
        $this->tradeName = $tradeName;

        return $this;
    }

    public function getBusinessLicense(): ?string
    {
        return $this->businessLicense;
    }

    public function setBusinessLicense(?string $businessLicense): self
    {
        $this->businessLicense = $businessLicense;

        return $this;
    }

    public function getSalesRepNote(): ?string
    {
        return $this->salesRepNote;
    }

    public function setSalesRepNote(?string $salesRepNote): self
    {
        $this->salesRepNote = $salesRepNote;

        return $this;
    }

    public function getSalesRepUser(): ?AdminUser
    {
        return $this->salesRepUser;
    }

    public function setSalesRepUser(?AdminUser $salesRepUser): self
    {
        $this->salesRepUser = $salesRepUser;

        return $this;
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): self
    {
        $this->accountType = $accountType;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getPaymentTermId(): ?int
    {
        return $this->paymentTermId;
    }

    public function setPaymentTermId(?int $paymentTermId): self
    {
        $this->paymentTermId = $paymentTermId;

        return $this;
    }

    public function getCreditLimit(): ?string
    {
        return $this->creditLimit;
    }

    /** Blank/non-positive input clears the limit — a limit of $0 is not a real constraint to store. */
    public function setCreditLimit(?string $creditLimit): self
    {
        $this->creditLimit = ($creditLimit !== null && (float) $creditLimit > 0) ? $creditLimit : null;

        return $this;
    }

    public function isApiEnabled(): bool
    {
        return $this->apiEnabled;
    }

    public function setApiEnabled(bool $apiEnabled): self
    {
        $this->apiEnabled = $apiEnabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, CompanyAddress> */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function addAddress(CompanyAddress $address): self
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
            $address->setCompany($this);
        }

        return $this;
    }

    public function removeAddress(CompanyAddress $address): self
    {
        $this->addresses->removeElement($address);

        return $this;
    }

    public function getDefaultBillingAddress(): ?CompanyAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->isDefaultBilling()) {
                return $address;
            }
        }
        return $this->addresses->first() ?: null;
    }

    public function getDefaultShippingAddress(): ?CompanyAddress
    {
        foreach ($this->addresses as $address) {
            if ($address->isDefaultShipping()) {
                return $address;
            }
        }
        return $this->addresses->first() ?: null;
    }
}
