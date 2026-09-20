<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ImportRunStatus;
use App\Repository\ImportRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per import attempt, of any import type — the unified ledger every import (VendorSheet,
 * Product, a per-document line importer, ...) writes to, instead of each feature keeping its own
 * status file/log. See docs/plans/2026-09-18-unified-import-framework.md.
 *
 * $status is workflow-only (queued -> started -> completed) and never encodes success/failure —
 * that is $error/$errorCount's job. A killed or partially-failed run still ends at 'completed';
 * "did it work" is answered by reading $error and $errorCount, not $status.
 *
 * Core has no Doctrine relation to any bundle entity (Vendor, PurchaseOrder, ...), so this table
 * carries no vendor_id/document_id columns — a bundle that needs to attach its own per-run context
 * (which vendor, which PO) owns a side table FKing to $id instead; core may not depend on a bundle,
 * but a bundle may depend on core. $description exists so that context is still human-readable on
 * the shared admin list without the shared table knowing what it means.
 */
#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(columns: ['status'], name: 'idx_import_run_status')]
#[ORM\Index(columns: ['import_type'], name: 'idx_import_run_type')]
class ImportRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Machine name from ImportDefinitionInterface::name() — e.g. 'vendor_sheet', 'product'. */
    #[ORM\Column(name: 'import_type', length: 60)]
    private string $importType;

    /** From ImportDefinitionInterface::entityType() — e.g. 'VendorPrice'. */
    #[ORM\Column(name: 'entity_type', length: 80)]
    private string $entityType;

    /** Free-text, import-specific context — e.g. "Vendor: Acme Corp", "PO: PO-2847 Vendor: XYZ". */
    #[ORM\Column(length: 255)]
    private string $description = '';

    /** Comma-joined ImportAction values actually taken across this run's rows — e.g. "append,update". */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $action = null;

    /**
     * Run-level options an import's own executor needs beyond per-row mapped data — e.g.
     * missing_rows/clear_approved_balance for the product importer. Plain scalars/strings only:
     * an entity REFERENCE (a specific Vendor, a specific PurchaseOrder) belongs on a bundle-owned
     * side table FKing to $id instead, per this entity's own top-of-class docblock — this column
     * has no such constraint because it never points at another entity.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $context = null;

    /** True = dry-run; nothing was ever queued or persisted, and $executedCount stays 0. */
    #[ORM\Column(name: 'validation_only')]
    private bool $validationOnly = false;

    #[ORM\Column(length: 20)]
    private string $status = ImportRunStatus::Queued->value;

    #[ORM\Column(name: 'row_count')]
    private int $rowCount = 0;

    #[ORM\Column(name: 'validated_count')]
    private int $validatedCount = 0;

    /** Rows actually persisted. Always 0 for a validation-only run. */
    #[ORM\Column(name: 'executed_count')]
    private int $executedCount = 0;

    #[ORM\Column(name: 'error_count')]
    private int $errorCount = 0;

    /** Top-level failure (file parse, unhandled exception, admin kill) — null on a clean run. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    /** 'ui' | 'cli' — where the import was submitted/queued from. */
    #[ORM\Column(length: 16)]
    private string $source = 'ui';

    /** How many times "Retry Queue" re-drained after this run — 0 normally. */
    #[ORM\Column]
    private int $attempt = 0;

    /** Null for a validation-only run, which never queues. */
    #[ORM\Column(name: 'queued_at', nullable: true)]
    private ?\DateTimeImmutable $queuedAt = null;

    /** Set only inside import:process, under the flock. */
    #[ORM\Column(name: 'started_at', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, ImportRunRow> */
    #[ORM\OneToMany(mappedBy: 'importRun', targetEntity: ImportRunRow::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['rowNumber' => 'ASC'])]
    private Collection $rows;

    public function __construct(string $importType, string $entityType)
    {
        $this->importType = $importType;
        $this->entityType = $entityType;
        $this->createdAt = new \DateTimeImmutable();
        $this->rows = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getImportType(): string { return $this->importType; }
    public function getEntityType(): string { return $this->entityType; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = substr(trim($description), 0, 255); return $this; }
    public function getAction(): ?string { return $this->action; }
    public function setAction(?string $action): self { $this->action = $action !== null ? substr($action, 0, 120) : null; return $this; }

    /** @return array<string, mixed> */
    public function getContext(): array { return $this->context ?? []; }
    /** @param array<string, mixed> $context */
    public function setContext(array $context): self { $this->context = $context; return $this; }
    public function isValidationOnly(): bool { return $this->validationOnly; }
    public function setValidationOnly(bool $validationOnly): self { $this->validationOnly = $validationOnly; return $this; }
    public function getStatus(): ImportRunStatus { return ImportRunStatus::from($this->status); }
    public function setStatus(ImportRunStatus $status): self { $this->status = $status->value; $this->touch(); return $this; }
    public function getRowCount(): int { return $this->rowCount; }
    public function setRowCount(int $rowCount): self { $this->rowCount = max(0, $rowCount); return $this; }
    public function getValidatedCount(): int { return $this->validatedCount; }
    public function setValidatedCount(int $validatedCount): self { $this->validatedCount = max(0, $validatedCount); return $this; }
    public function getExecutedCount(): int { return $this->executedCount; }
    public function setExecutedCount(int $executedCount): self { $this->executedCount = max(0, $executedCount); return $this; }
    public function getErrorCount(): int { return $this->errorCount; }
    public function setErrorCount(int $errorCount): self { $this->errorCount = max(0, $errorCount); return $this; }
    public function getError(): ?string { return $this->error; }
    public function setError(?string $error): self { $this->error = $error; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = substr(trim($source), 0, 16) ?: 'ui'; return $this; }
    public function getAttempt(): int { return $this->attempt; }
    public function incrementAttempt(): self { ++$this->attempt; return $this; }
    public function getQueuedAt(): ?\DateTimeImmutable { return $this->queuedAt; }
    public function setQueuedAt(?\DateTimeImmutable $queuedAt): self { $this->queuedAt = $queuedAt; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self { $this->finishedAt = $finishedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    /** @return Collection<int, ImportRunRow> */
    public function getRows(): Collection { return $this->rows; }

    public function addRow(ImportRunRow $row): self
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setImportRun($this);
        }

        return $this;
    }
}
