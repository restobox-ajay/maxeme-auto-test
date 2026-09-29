<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shop customer, the owner of vehicles (legacy CNSClientBundle Client).
 *
 * Never hard-deleted: removing a client clears `active`, so their appointments and invoices keep
 * pointing at them. Ids are the legacy ids (see ClientImporter).
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'maxeme_client')]
#[ORM\Index(name: 'idx_maxeme_client_active_name', columns: ['active', 'first_name', 'last_name'])]
class Client implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $preferredName = null;

    /** Free text, as in the legacy app: no format check, and legacy rows hold anything. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $homeNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cellNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Set on create and on every edit (the legacy column was ON UPDATE CURRENT_TIMESTAMP). */
    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    /** @var Collection<int, Vehicle> */
    #[ORM\OneToMany(targetEntity: Vehicle::class, mappedBy: 'client')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $vehicles;

    public function __construct()
    {
        $this->lastUpdated = new \DateTimeImmutable();
        $this->vehicles = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): self { $this->firstName = $firstName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = $lastName; return $this; }

    public function getPreferredName(): ?string { return $this->preferredName; }
    public function setPreferredName(?string $preferredName): self { $this->preferredName = $preferredName; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getHomeNumber(): ?string { return $this->homeNumber; }
    public function setHomeNumber(?string $homeNumber): self { $this->homeNumber = $homeNumber; return $this; }

    public function getWorkNumber(): ?string { return $this->workNumber; }
    public function setWorkNumber(?string $workNumber): self { $this->workNumber = $workNumber; return $this; }

    public function getCellNumber(): ?string { return $this->cellNumber; }
    public function setCellNumber(?string $cellNumber): self { $this->cellNumber = $cellNumber; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = $address; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }

    public function isActive(): bool { return $this->active; }

    /** Soft delete: the client leaves every list and search, their history stays. */
    public function deactivate(): void
    {
        $this->active = false;
        $this->touch();
    }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(?\DateTimeImmutable $at = null): void
    {
        $this->lastUpdated = $at ?? new \DateTimeImmutable();
    }

    /** "First Last (Preferred)", the legacy Client::getFullname(). */
    public function getFullName(): string
    {
        $fullName = trim(sprintf('%s %s', $this->firstName, $this->lastName));

        return $this->preferredName !== null && $this->preferredName !== '' ? sprintf('%s (%s)', $fullName, $this->preferredName) : $fullName;
    }

    /** @return Collection<int, Vehicle> the client's vehicles that are not deleted */
    public function getVehicles(): Collection
    {
        return $this->vehicles->matching(Criteria::create()->where(Criteria::expr()->eq('active', true)));
    }
}
