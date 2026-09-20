<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Backs DocumentNumberAllocator (#304) — one row per (kind, prefix) pair. Not read or written
 * through the ORM's UnitOfWork anywhere; App\Service\DocumentNumberAllocator talks to this table
 * directly over the raw connection so its increment can be a single atomic UPDATE statement, which
 * is the whole point (a fetch-then-persist-then-flush round trip would reopen the exact race this
 * exists to close). This class exists only so DoctrineIntegrationTestCase's SchemaTool-from-metadata
 * setup creates the table for tests — see migrations/Version20260802110000.php for the real DDL.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_number_counter')]
#[ORM\UniqueConstraint(name: 'UNIQ_DOC_NUMBER_COUNTER_KIND_PREFIX', columns: ['kind', 'prefix'])]
class DocumentNumberCounter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $kind = '';

    #[ORM\Column(length: 20)]
    private string $prefix = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $lastValue = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getLastValue(): int
    {
        return $this->lastValue;
    }
}
