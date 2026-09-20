<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\FrontendMenuItemStatus;
use App\Repository\FrontendMenuItemStatusRepository;
use App\Tests\DoctrineIntegrationTestCase;

final class FrontendMenuItemStatusRepositoryTest extends DoctrineIntegrationTestCase
{
    private function repo(): FrontendMenuItemStatusRepository
    {
        return $this->em->getRepository(FrontendMenuItemStatus::class);
    }

    public function testIsActiveIsTrueForAKeyWithNoStatusRowYet(): void
    {
        self::assertTrue($this->repo()->isActive('news.index'));
    }

    public function testEnsureByKeyCreatesAnActiveRowOnFirstCall(): void
    {
        $status = $this->repo()->ensureByKey('news.index');

        self::assertSame('news.index', $status->getMenuKey());
        self::assertSame(FrontendMenuItemStatus::STATUS_ACTIVE, $status->getStatus());
    }

    public function testEnsureByKeyReturnsTheExistingRowRatherThanCreatingADuplicate(): void
    {
        $repo = $this->repo();

        $first = $repo->ensureByKey('news.index');
        $first->setStatus(FrontendMenuItemStatus::STATUS_INACTIVE);
        $this->em->flush();

        $second = $repo->ensureByKey('news.index');

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(FrontendMenuItemStatus::STATUS_INACTIVE, $second->getStatus());
    }

    public function testIsActiveReflectsAStoredInactiveStatus(): void
    {
        $repo = $this->repo();
        $repo->ensureByKey('news.index')->setStatus(FrontendMenuItemStatus::STATUS_INACTIVE);
        $this->em->flush();

        self::assertFalse($repo->isActive('news.index'));
    }

    public function testSortOrderForIsZeroForAKeyWithNoStatusRowYet(): void
    {
        self::assertSame(0, $this->repo()->sortOrderFor('news.index'));
    }

    public function testSortOrderForReflectsAStoredValue(): void
    {
        $repo = $this->repo();
        $repo->ensureByKey('news.index')->setSortOrder(3);
        $this->em->flush();

        self::assertSame(3, $repo->sortOrderFor('news.index'));
    }
}
