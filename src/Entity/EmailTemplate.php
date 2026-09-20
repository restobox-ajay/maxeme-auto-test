<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An admin's deviation from a shipped email template, or a template they authored themselves.
 *
 * Not the template itself. The 22 templates this application ships live in
 * {@see \App\Service\Email\ShippedEmailTemplates} and its .twig files, and a row exists here only
 * where an admin has changed something — so on a fresh install this table is EMPTY and every email
 * still sends (#507).
 *
 * That is why every editable field below is nullable, and why null means "inherit" rather than
 * "blank". An admin who rewrote only the subject leaves the rest null and keeps receiving shipped
 * corrections to the body; under the previous design the row carried every field, so touching one
 * froze all of them against future fixes. {@see \App\Service\Email\EmailTemplateResolver} is the
 * only thing that should read these fields directly — it lays the row over the shipped definition
 * field by field and hands callers finished content.
 *
 * `code` is the exception and stays NOT NULL: it is the join key between a row and its shipped
 * counterpart, which is also why ConfigController derives it once on create and deliberately never
 * recomputes it on update.
 *
 * Rows whose code has no shipped counterpart are templates an admin created in the panel. They
 * carry every field because nothing exists to inherit from, which needs no special case — it falls
 * out of the same null-means-inherit rule.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_template')]
class EmailTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $code = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $module = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $sentTo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $status = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = $code; return $this; }
    public function getModule(): ?string { return $this->module; }
    public function setModule(?string $module): self { $this->module = $module; return $this; }
    public function getSentTo(): ?string { return $this->sentTo; }
    public function setSentTo(?string $sentTo): self { $this->sentTo = $sentTo; return $this; }
    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $subject): self { $this->subject = $subject; return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = $body; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(?string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
