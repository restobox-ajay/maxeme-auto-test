<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ImportAction;
use App\Enum\ImportRowStatus;
use App\Repository\ImportRunRowRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per CSV row per import attempt — the per-row audit trail under an ImportRun. Never
 * updated after creation (see D5 in the framework plan: the ledger is immutable) except by the row
 * itself moving through validated -> succeeded/failed exactly once during executeQueued().
 */
#[ORM\Entity(repositoryClass: ImportRunRowRepository::class)]
#[ORM\Table(name: 'import_run_row')]
#[ORM\Index(columns: ['import_run_id', 'status'], name: 'idx_import_run_row_status')]
class ImportRunRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportRun::class, inversedBy: 'rows')]
    #[ORM\JoinColumn(name: 'import_run_id', nullable: false, onDelete: 'CASCADE')]
    private ImportRun $importRun;

    /** 1-indexed position in the uploaded file. */
    #[ORM\Column(name: 'row_number')]
    private int $rowNumber;

    /** The CSV row exactly as read: header => raw cell value. */
    #[ORM\Column(name: 'input_data', type: 'json')]
    private array $inputData;

    /** After ColumnMapper's mapping is applied: target field key => value. */
    #[ORM\Column(name: 'mapped_data', type: 'json')]
    private array $mappedData;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $action = null;

    #[ORM\Column(length: 20)]
    private string $status;

    /** Validation or execution error. Null on a row that succeeded (or is only validated, so far). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $inputData
     * @param array<string, mixed> $mappedData
     */
    public function __construct(ImportRun $importRun, int $rowNumber, array $inputData, array $mappedData)
    {
        $this->importRun = $importRun;
        $this->rowNumber = $rowNumber;
        $this->inputData = $inputData;
        $this->mappedData = $mappedData;
        $this->status = ImportRowStatus::Validated->value;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getImportRun(): ImportRun { return $this->importRun; }
    public function setImportRun(ImportRun $importRun): self { $this->importRun = $importRun; return $this; }
    public function getRowNumber(): int { return $this->rowNumber; }

    /** @return array<string, mixed> */
    public function getInputData(): array { return $this->inputData; }

    /** @return array<string, mixed> */
    public function getMappedData(): array { return $this->mappedData; }

    public function getAction(): ?ImportAction { return $this->action !== null ? ImportAction::from($this->action) : null; }
    public function setAction(?ImportAction $action): self { $this->action = $action?->value; return $this; }
    public function getStatus(): ImportRowStatus { return ImportRowStatus::from($this->status); }
    public function setStatus(ImportRowStatus $status): self { $this->status = $status->value; return $this; }
    public function getError(): ?string { return $this->error; }
    public function setError(?string $error): self { $this->error = $error; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
