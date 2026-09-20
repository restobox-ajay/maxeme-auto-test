<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\TemplateOverride;

use App\Contract\Bundle\TemplateOverrideProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

/**
 * getPoint() is resolved dynamically (not a fixed string) because the category id it targets is
 * an admin-configured, database-backed value — not knowable at compile/service-registration time.
 * TemplateOverrideResolver just calls getPoint() fresh on every resolve(), so this is cheap and
 * always current (CategoryPageConfig caches the actual category lookup per request).
 */
abstract class AbstractRoleTemplateOverrideProvider implements TemplateOverrideProviderInterface
{
    public function __construct(
        protected readonly CategoryPageConfig $config,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    abstract protected function role(): string;

    public function getPoint(): string
    {
        $category = $this->config->resolveCategories($this->entityManager)[$this->role()];

        return 'customer_catalog_index:' . $category->getId();
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'Number1CategoryProductPageBundle';
    }

    public function getTemplateSource(): ?string
    {
        return null;
    }
}
