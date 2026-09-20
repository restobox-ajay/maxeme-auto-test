<?php

namespace App\Entity;

use App\Repository\ErrorLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ErrorLogRepository::class)]
#[ORM\Table(name: 'error_log')]
class ErrorLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $level = 'error';

    #[ORM\Column(length: 80)]
    private string $area = 'app';

    #[ORM\Column(type: 'text')]
    private string $message = '';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * The client IP the request came from, as Symfony resolved it (so it honours trusted
     * proxies). Nullable because plenty of errors have no request behind them — console
     * commands, queued jobs.
     */
    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** One of 'staff', 'customer', 'guest' — null when there's no request to resolve one from. */
    #[ORM\Column(name: 'user_type', length: 16, nullable: true)]
    private ?string $userType = null;

    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(name: 'user_email', length: 255, nullable: true)]
    private ?string $userEmail = null;

    /** The HTTP Referer header on the request that errored, i.e. what page the user came from. */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $referrer = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLevel(): string { return $this->level; }
    public function setLevel(string $level): self { $this->level = substr(trim($level), 0, 32) ?: 'error'; return $this; }
    public function getArea(): string { return $this->area; }
    public function setArea(string $area): self { $this->area = substr(trim($area), 0, 80) ?: 'app'; return $this; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self { $this->message = $message; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }

    public function getUserType(): ?string { return $this->userType; }
    public function setUserType(?string $userType): self { $this->userType = $userType !== null ? (substr(trim($userType), 0, 16) ?: null) : null; return $this; }

    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $userId): self { $this->userId = $userId; return $this; }

    public function getUserEmail(): ?string { return $this->userEmail; }
    public function setUserEmail(?string $userEmail): self { $this->userEmail = $userEmail !== null ? (substr(trim($userEmail), 0, 255) ?: null) : null; return $this; }

    public function getReferrer(): ?string { return $this->referrer; }
    public function setReferrer(?string $referrer): self { $this->referrer = $referrer !== null ? (substr(trim($referrer), 0, 2048) ?: null) : null; return $this; }
}

