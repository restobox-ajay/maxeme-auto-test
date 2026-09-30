<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyNote;
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
 *
 * Up to four phone numbers (the legacy home / work / cell became 1 / 2 / 3), an address book of
 * pickup addresses in the client's order, and notes.
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'maxeme_client')]
#[ORM\Index(name: 'idx_maxeme_client_active_name', columns: ['active', 'first_name', 'last_name'])]
class Client implements SoftDeletable, HasNotes
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

    #[ORM\Column(name: 'phone_1', length: 255, nullable: true)]
    private ?string $phone1 = null;

    #[ORM\Column(name: 'phone_2', length: 255, nullable: true)]
    private ?string $phone2 = null;

    #[ORM\Column(name: 'phone_3', length: 255, nullable: true)]
    private ?string $phone3 = null;

    #[ORM\Column(name: 'phone_4', length: 255, nullable: true)]
    private ?string $phone4 = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Set on create and on every edit (the legacy column was ON UPDATE CURRENT_TIMESTAMP). */
    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    /** @var Collection<int, Vehicle> */
    #[ORM\OneToMany(targetEntity: Vehicle::class, mappedBy: 'client')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $vehicles;

    /** @var Collection<int, ClientAddress> */
    #[ORM\OneToMany(targetEntity: ClientAddress::class, mappedBy: 'client')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $addresses;

    /** @var Collection<int, ClientNote> */
    #[ORM\OneToMany(targetEntity: ClientNote::class, mappedBy: 'client')]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $notes;

    public function __construct()
    {
        $this->lastUpdated = new \DateTimeImmutable();
        $this->vehicles = new ArrayCollection();
        $this->addresses = new ArrayCollection();
        $this->notes = new ArrayCollection();
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

    public function getPhone1(): ?string { return $this->phone1; }
    public function setPhone1(?string $phone1): self { $this->phone1 = $phone1; return $this; }

    public function getPhone2(): ?string { return $this->phone2; }
    public function setPhone2(?string $phone2): self { $this->phone2 = $phone2; return $this; }

    public function getPhone3(): ?string { return $this->phone3; }
    public function setPhone3(?string $phone3): self { $this->phone3 = $phone3; return $this; }

    public function getPhone4(): ?string { return $this->phone4; }
    public function setPhone4(?string $phone4): self { $this->phone4 = $phone4; return $this; }

    /** @return array<int, string> phone number => its number (1-4), blanks left out */
    public function getPhones(): array
    {
        return array_filter([1 => $this->phone1, 2 => $this->phone2, 3 => $this->phone3, 4 => $this->phone4], static fn (?string $phone): bool => $phone !== null && $phone !== '');
    }

    /** @return Collection<int, ClientAddress> the address book, in the client's order */
    public function getAddresses(): Collection { return $this->addresses; }

    /** The first address in the book, or null when it is empty. */
    public function getPrimaryAddress(): ?ClientAddress
    {
        return $this->addresses->first() ?: null;
    }

    /** @return Collection<int, ClientNote> newest first */
    public function getNotes(): Collection { return $this->notes; }

    public function newNote(): AbstractPartyNote
    {
        return new ClientNote($this);
    }

    public function getLatestNote(): ?ClientNote
    {
        return $this->notes->first() ?: null;
    }

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
