<?php

namespace App\Entity;

use App\Repository\EmailLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Table(name: 'email_log')]
class EmailLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'template_code', length: 80)]
    private string $templateCode = '';

    #[ORM\Column(length: 180)]
    private string $recipient = '';

    #[ORM\Column(length: 32)]
    private string $status = 'Sent';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTemplateCode(): string { return $this->templateCode; }
    public function setTemplateCode(string $templateCode): self { $this->templateCode = substr(trim($templateCode), 0, 80); return $this; }
    public function getRecipient(): string { return $this->recipient; }
    public function setRecipient(string $recipient): self { $this->recipient = substr(trim($recipient), 0, 180); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = substr(trim($status), 0, 32); return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = $body !== null ? trim($body) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
