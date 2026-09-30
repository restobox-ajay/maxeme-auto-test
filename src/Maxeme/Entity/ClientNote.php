<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyNote;
use Doctrine\ORM\Mapping as ORM;

/**
 * One note on a client: author, message, timestamp (core AbstractPartyNote, the note every
 * wholesale company, vendor and product note is too). Written through App\Maxeme\Service\NoteBook.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_client_note')]
#[ORM\Index(name: 'idx_maxeme_client_note_client', fields: ['client'])]
class ClientNote extends AbstractPartyNote
{
    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    public function __construct(Client $client)
    {
        parent::__construct();
        $this->client = $client;
    }

    public function getClient(): Client { return $this->client; }
}
