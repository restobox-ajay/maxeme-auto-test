<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One API key, belonging to one customer user.
 *
 * The key does not represent an application; it represents a person. A request carrying it is
 * authenticated *as* that CustomerUser, which is what lets the API be a thin wrapper: the customer
 * controllers see an ordinary logged-in customer, so company scoping, pricing, catalog visibility
 * and role gating all apply by themselves rather than being restated in an API layer.
 *
 * It follows that a key can never do more than its owner can do on the website, and that revoking
 * a user's access revokes their key with it. Both of those are properties of this association, not
 * rules anyone has to remember to enforce.
 *
 * This replaces the per-company credential the removed Number1ProductAPIManager bundle owned. That
 * one was shared by a whole company, so it could not represent anybody in particular — which is
 * why it had to authenticate as a synthetic non-customer principal, and why every customer
 * controller was unreachable from it.
 *
 * Whether a user may hold a key at all is not stored here: it is CustomerUser::$apiEnabled, gated
 * in turn by Company::$apiEnabled. Keeping "may they" separate from "here is their key" is what
 * lets an admin disable a company without touching, or having to restore, anyone's key.
 */
#[ORM\Entity(repositoryClass: ApiCredentialRepository::class)]
#[ORM\Table(name: 'api_credential')]
#[ORM\UniqueConstraint(name: 'uniq_api_credential_customer_user', columns: ['customer_user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_api_credential_api_key', columns: ['api_key'])]
class ApiCredential
{
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_REVOKED = 'Revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * A real association, unlike the bundle's deliberately loose int pointer — the API is core now,
     * so there is no bundle to detach and nothing to gain from a danging reference. Deleting the
     * user takes their key with them, which is the correct outcome.
     */
    #[ORM\OneToOne(targetEntity: CustomerUser::class)]
    #[ORM\JoinColumn(name: 'customer_user_id', nullable: false, onDelete: 'CASCADE')]
    private CustomerUser $customerUser;

    #[ORM\Column(length: 100)]
    private string $apiKey = '';

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomerUser(): CustomerUser
    {
        return $this->customerUser;
    }

    public function setCustomerUser(CustomerUser $customerUser): static
    {
        $this->customerUser = $customerUser;

        return $this;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Written by ApiKeyAuthenticator::recordUsage() with a direct statement rather than through the
     * unit of work, so there is deliberately no setter here: a markUsed() that mutated the entity
     * without a flush would look like it worked and silently do nothing, and one that flushed would
     * put a write in the middle of authenticating a read.
     */
    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
