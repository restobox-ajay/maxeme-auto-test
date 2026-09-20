<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
#[ORM\UniqueConstraint(name: 'uniq_app_setting_key', fields: ['settingKey'])]
class AppSetting
{
    /**
     * Unix timestamp the raw SQL console stays reachable until; 0 or absent means off.
     *
     * Read by both the admin controller and public/db-admin.php, which runs outside the kernel and
     * queries this row directly — so the key name is part of that contract, not an internal detail.
     */
    public const DB_CONSOLE_ENABLED_UNTIL = 'db_console_enabled_until';

    /**
     * Who a setting belongs to. 'store' is everything the store owner configures; 'tech_support' is
     * configuration for whoever runs the software, confidential to them — tech_support_email, whose
     * alert names the admin who took raw SQL access, their IP and the time.
     *
     * It is a column rather than a key list on purpose. ConfigController::PROTECTED_SETTING_KEYS is
     * the pattern being avoided: a boundary every listing screen has to remember to apply is not a
     * boundary. This travels with the row, so the query enforces it once and a screen that forgets
     * gets nothing rather than everything.
     */
    public const VISIBILITY_STORE = 'store';
    public const VISIBILITY_TECH_SUPPORT = 'tech_support';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', length: 120)]
    private string $settingKey = '';

    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $settingValue = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 20, options: ['default' => self::VISIBILITY_STORE])]
    private string $visibility = self::VISIBILITY_STORE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSettingKey(): string { return $this->settingKey; }
    public function setSettingKey(string $settingKey): self { $this->settingKey = $settingKey; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSettingValue(): ?string { return $this->settingValue; }
    public function setSettingValue(?string $settingValue): self { $this->settingValue = $settingValue; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category; return $this; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): self { $this->visibility = $visibility; return $this; }
    public function isTechSupportOnly(): bool { return $this->visibility === self::VISIBILITY_TECH_SUPPORT; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
