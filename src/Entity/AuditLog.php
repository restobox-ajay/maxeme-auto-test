<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 16)]
    private string $actorType = 'system';

    #[ORM\Column(nullable: true)]
    private ?int $actorId = null;

    #[ORM\Column(length: 255)]
    private string $actorName = 'System';

    #[ORM\Column(length: 80)]
    private string $area = 'app';

    #[ORM\Column(length: 80)]
    private string $entityType = '';

    #[ORM\Column(nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(length: 64)]
    private string $action = '';

    #[ORM\Column(type: 'text')]
    private string $summary = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dataBefore = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dataAfter = null;

    /**
     * The client IP the action came from, as Symfony resolved it (so it honours trusted proxies).
     *
     * Nullable because plenty of audited work has no request behind it — console commands, queued
     * jobs, migrations — and inventing an IP for those would be worse than recording none.
     */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /**
     * Who was really driving, when an admin acts as somebody else.
     *
     * Mapping only — schema groundwork for a future impersonation feature (Version20260806010000).
     * Nothing writes these yet, so they are NULL on every row, which is the correct value: no
     * existing action was impersonated.
     *
     * Deliberately a bare int and not an admin_user association. actor_id is polymorphic on
     * actorType and carries no foreign key either, and an audit trail has to outlive the actor it
     * names — a relation would either cascade these rows away with the admin or block the admin
     * from ever being deleted.
     */
    #[ORM\Column(nullable: true)]
    private ?int $impersonatorAdminId = null;

    /**
     * The impersonating admin's name, snapshotted at write time exactly as actorName is, so the
     * trail still reads correctly once that admin record is gone.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $impersonatorName = null;

    /**
     * Whoever this row's subject is owed to (a customer, a vendor) was told about it — the one
     * column the five document-timeline entities this table absorbed did not all agree on: the
     * sell side called it customer_notified, PurchaseOrder called the same idea vendor_notified,
     * and VendorBillLog carried no such column at all. False by default rather than nullable,
     * matching what an absent column always meant in practice: nobody was notified.
     */
    #[ORM\Column(name: 'recipient_notified', options: ['default' => false])]
    private bool $recipientNotified = false;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }

    public function getActorType(): string { return $this->actorType; }
    public function setActorType(string $actorType): self { $this->actorType = substr(trim($actorType), 0, 16) ?: 'system'; return $this; }

    public function getActorId(): ?int { return $this->actorId; }
    public function setActorId(?int $actorId): self { $this->actorId = $actorId; return $this; }

    public function getActorName(): string { return $this->actorName; }
    public function setActorName(string $actorName): self { $this->actorName = substr(trim($actorName), 0, 255) ?: 'System'; return $this; }

    public function getArea(): string { return $this->area; }
    public function setArea(string $area): self { $this->area = substr(trim($area), 0, 80) ?: 'app'; return $this; }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $entityType): self { $this->entityType = substr(trim($entityType), 0, 80); return $this; }

    public function getEntityId(): ?int { return $this->entityId; }
    public function setEntityId(?int $entityId): self { $this->entityId = $entityId; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = substr(trim($action), 0, 64); return $this; }

    public function getSummary(): string { return $this->summary; }
    public function setSummary(string $summary): self { $this->summary = $summary; return $this; }

    public function getDataBefore(): ?string { return $this->dataBefore; }
    public function setDataBefore(?string $dataBefore): self { $this->dataBefore = $dataBefore; return $this; }

    public function getDataAfter(): ?string { return $this->dataAfter; }
    public function setDataAfter(?string $dataAfter): self { $this->dataAfter = $dataAfter; return $this; }

    public function getImpersonatorAdminId(): ?int { return $this->impersonatorAdminId; }
    public function setImpersonatorAdminId(?int $impersonatorAdminId): self { $this->impersonatorAdminId = $impersonatorAdminId; return $this; }

    public function isRecipientNotified(): bool { return $this->recipientNotified; }
    public function setRecipientNotified(bool $recipientNotified): self { $this->recipientNotified = $recipientNotified; return $this; }

    public function getImpersonatorName(): ?string { return $this->impersonatorName; }
    // Truncated like setActorName(), so an over-long name cannot fail the insert of the row it is describing.
    public function setImpersonatorName(?string $impersonatorName): self { $this->impersonatorName = $impersonatorName !== null ? (substr(trim($impersonatorName), 0, 255) ?: null) : null; return $this; }
}
