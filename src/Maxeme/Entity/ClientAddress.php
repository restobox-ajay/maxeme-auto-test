<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyAddress;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of a client's pickup addresses: the client's address book, the same shape as a wholesale
 * company's (core AbstractPartyAddress). The client sets the order (`position`, 0 first); the
 * profile shows the first two and the repair order's address picker lists them in that order.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_client_address')]
#[ORM\Index(name: 'idx_maxeme_client_address_client', fields: ['client', 'position'])]
class ClientAddress extends AbstractPartyAddress
{
    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Client $client, int $position)
    {
        $this->client = $client;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getClient(): Client { return $this->client; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** "12 Main St, Unit 4, Vancouver BC V5K 0A1": the address on one line, blanks left out. */
    public function getOneLine(): string
    {
        $place = trim(implode(' ', array_filter([$this->city, $this->province, $this->postalCode])));

        return implode(', ', array_filter([$this->addressLine1, $this->addressLine2, $place, $this->country]));
    }
}
