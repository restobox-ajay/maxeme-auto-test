<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CustomMenuItem;
use App\Repository\CustomMenuItemRepository;
use App\Tests\DoctrineIntegrationTestCase;

final class CustomMenuItemRepositoryTest extends DoctrineIntegrationTestCase
{
    private function repo(): CustomMenuItemRepository
    {
        return $this->em->getRepository(CustomMenuItem::class);
    }

    private function makeItem(string $label, string $url, int $sortOrder, string $status = CustomMenuItem::STATUS_ACTIVE): CustomMenuItem
    {
        $item = (new CustomMenuItem())
            ->setLabel($label)
            ->setUrl($url)
            ->setSortOrder($sortOrder)
            ->setStatus($status);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    public function testFindAllOrderedReturnsEveryItemSortedBySortOrderThenId(): void
    {
        $this->makeItem('Second', '/second', 1);
        $this->makeItem('First', '/first', 0);
        $this->makeItem('Inactive Third', '/third', 2, CustomMenuItem::STATUS_INACTIVE);

        $labels = array_map(static fn (CustomMenuItem $i): string => $i->getLabel(), $this->repo()->findAllOrdered());

        self::assertSame(['First', 'Second', 'Inactive Third'], $labels);
    }

    public function testFindActiveOrderedExcludesInactiveItems(): void
    {
        $this->makeItem('Active', '/active', 0);
        $this->makeItem('Inactive', '/inactive', 1, CustomMenuItem::STATUS_INACTIVE);

        $labels = array_map(static fn (CustomMenuItem $i): string => $i->getLabel(), $this->repo()->findActiveOrdered());

        self::assertSame(['Active'], $labels);
    }
}
