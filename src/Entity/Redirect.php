<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RedirectRepository;
use App\Validation\Constraint\ValidRedirect;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RedirectRepository::class)]
#[ORM\Table(name: 'url_redirect')]
#[ORM\UniqueConstraint(name: 'UNIQ_URL_REDIRECT_SOURCE_PATH', columns: ['source_path'])]
#[ValidRedirect]
class Redirect
{
    public const DESTINATION_TYPE_URL = 'url';
    public const DESTINATION_TYPE_CATEGORY = 'category';

    public const REDIRECT_TYPE_PERMANENT = 301;
    public const REDIRECT_TYPE_TEMPORARY = 302;

    public const QUERY_HANDLING_PASS = 'pass';
    public const QUERY_HANDLING_MATCH = 'match';
    public const QUERY_HANDLING_STRIP = 'strip';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $sourcePath = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::DESTINATION_TYPE_URL, self::DESTINATION_TYPE_CATEGORY], message: 'A valid destination type is required.')]
    private string $destinationType = self::DESTINATION_TYPE_URL;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $destinationUrl = null;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class)]
    #[ORM\JoinColumn(name: 'destination_category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductCategory $destinationCategory = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Choice(choices: [self::REDIRECT_TYPE_PERMANENT, self::REDIRECT_TYPE_TEMPORARY], message: 'Redirect type must be 301 (Permanent) or 302 (Temporary).')]
    private int $redirectType = self::REDIRECT_TYPE_PERMANENT;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::QUERY_HANDLING_PASS, self::QUERY_HANDLING_MATCH, self::QUERY_HANDLING_STRIP], message: 'A valid query parameter handling mode is required.')]
    private string $queryHandling = self::QUERY_HANDLING_PASS;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function setSourcePath(string $sourcePath): static
    {
        $this->sourcePath = $sourcePath;

        return $this;
    }

    public function getDestinationType(): string
    {
        return $this->destinationType;
    }

    public function setDestinationType(string $destinationType): static
    {
        $this->destinationType = $destinationType;

        return $this;
    }

    public function getDestinationUrl(): ?string
    {
        return $this->destinationUrl;
    }

    public function setDestinationUrl(?string $destinationUrl): static
    {
        $this->destinationUrl = $destinationUrl;

        return $this;
    }

    public function getDestinationCategory(): ?ProductCategory
    {
        return $this->destinationCategory;
    }

    public function setDestinationCategory(?ProductCategory $destinationCategory): static
    {
        $this->destinationCategory = $destinationCategory;

        return $this;
    }

    public function getRedirectType(): int
    {
        return $this->redirectType;
    }

    public function setRedirectType(int $redirectType): static
    {
        $this->redirectType = $redirectType;

        return $this;
    }

    public function getQueryHandling(): string
    {
        return $this->queryHandling;
    }

    public function setQueryHandling(string $queryHandling): static
    {
        $this->queryHandling = $queryHandling;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Used only by AuditLogSubscriber (LABEL_GETTERS) to build a human-readable audit label. */
    public function getLabel(): string
    {
        return $this->sourcePath;
    }
}
