<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportColumnMappingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Remembers the last column mapping an admin chose for one (import type, scope) pair, so a repeat
 * import of the same shape pre-fills instead of re-asking every time. $scope is whatever the import
 * needs to distinguish mappings by — a vendor ID string for vendor_sheet (their sheet shape doesn't
 * change vendor to vendor), a document type for a line importer, or '' for an import with only one
 * shape (e.g. Product).
 *
 * Entirely a convenience: ColumnMapper works fine with no row here (mapping screen just starts
 * blank), and a shifted header simply shows unmapped for the admin to fix by hand — nothing reads
 * this as authoritative.
 */
#[ORM\Entity(repositoryClass: ImportColumnMappingRepository::class)]
#[ORM\Table(name: 'import_column_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_import_column_mapping', columns: ['import_type', 'scope'])]
class ImportColumnMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'import_type', length: 60)]
    private string $importType;

    #[ORM\Column(length: 120)]
    private string $scope = '';

    /** target field key => chosen header, as ColumnMapper::fromRequest() produces. */
    #[ORM\Column(type: 'json')]
    private array $mapping;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, string> $mapping */
    public function __construct(string $importType, string $scope, array $mapping)
    {
        $this->importType = $importType;
        $this->scope = $scope;
        $this->mapping = $mapping;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getImportType(): string { return $this->importType; }
    public function getScope(): string { return $this->scope; }

    /** @return array<string, string> */
    public function getMapping(): array { return $this->mapping; }

    /** @param array<string, string> $mapping */
    public function setMapping(array $mapping): self
    {
        $this->mapping = $mapping;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
