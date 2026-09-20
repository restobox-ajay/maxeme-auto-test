<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DbConsoleSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One live database-console session: a short-lived, randomly generated credential.
 *
 * Everything that authorises a console request lives here in one row — the opaque token (stored as a
 * hash, never in the clear), the admin it belongs to, when it expires, and the IP it was opened from.
 * The gateway (public/db-admin.php) runs outside the kernel and authorises purely by looking a request's
 * token up against this table; nothing is reconstructed from a secret, so a session can be expired or
 * revoked simply by deleting the row.
 *
 * The token is random (not derived from the user id), so it is unguessable and non-replayable once it
 * lapses, and it is attributed to a named admin via a foreign key for accountability (ISO 27001 A.9).
 */
#[ORM\Entity(repositoryClass: DbConsoleSessionRepository::class)]
#[ORM\Table(name: 'db_console_session')]
#[ORM\UniqueConstraint(name: 'uniq_db_console_session_token', fields: ['tokenHash'])]
#[ORM\Index(name: 'idx_db_console_session_admin', fields: ['admin'])]
class DbConsoleSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** SHA-256 of the opaque token handed to the client. The raw token exists only in the cookie. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** The admin this session belongs to. Deleting the admin deletes their sessions (ON DELETE CASCADE). */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'admin_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AdminUser $admin;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** The client IP the session was opened from; the gateway refuses requests from anywhere else. */
    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $tokenHash): self { $this->tokenHash = $tokenHash; return $this; }

    public function getAdmin(): AdminUser { return $this->admin; }
    public function setAdmin(AdminUser $admin): self { $this->admin = $admin; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }

    public function getIpAddress(): string { return $this->ipAddress; }
    public function setIpAddress(string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
