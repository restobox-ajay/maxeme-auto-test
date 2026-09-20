<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\CatalogListColumn;

use App\Contract\Bundle\CatalogListColumnProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

/**
 * Rim-spec columns the Wheel category's List View shows beyond the shared catalog list — see
 * scripts/task-loop/TASKS.md's "Wheel category page ... List View: add the rim-spec columns" item.
 * Slugs are the rim_* custom fields Number1RimImportBundle\EventSubscriber\RimSpecFieldSubscriber
 * registers, except 'weight', which AbstractCustomerController::customerProductRow() resolves
 * from ProductCore::getWeight() directly since it's a plain column, not a custom field.
 */
final class WheelCatalogListColumnProvider implements CatalogListColumnProviderInterface
{
    public function __construct(
        private readonly CategoryPageConfig $config,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getPoint(): string
    {
        $category = $this->config->resolveCategories($this->entityManager)[CategoryPageConfig::ROLE_WHEEL];

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

    public function getColumns(): array
    {
        return [
            ['slug' => 'rim_model', 'label' => 'Model'],
            ['slug' => 'rim_dimension', 'label' => 'Dimension'],
            ['slug' => 'rim_width', 'label' => 'Width'],
            ['slug' => 'rim_offset', 'label' => 'Offset'],
            ['slug' => 'rim_pcd', 'label' => 'Pcd'],
            ['slug' => 'rim_cb', 'label' => 'CB'],
            ['slug' => 'rim_finish', 'label' => 'Finish'],
            ['slug' => 'weight', 'label' => 'Weight'],
            ['slug' => 'rim_backspace', 'label' => 'Backspace'],
            ['slug' => 'rim_seat', 'label' => 'Seat'],
            ['slug' => 'rim_made', 'label' => 'Made'],
            ['slug' => 'rim_load_rating', 'label' => 'Load'],
        ];
    }
}
