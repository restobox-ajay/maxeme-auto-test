<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\SalesOrder;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;

/**
 * Empirical check for #417's #[ORM\Version] column on SalesOrder — the issue's own instruction
 * was not to assume it is a drop-in addition, since Doctrine documents several restrictions on a
 * version field (must be int/bigint/smallint/datetime, one per entity, not usable on a composite
 * key or inside certain inheritance shapes). SalesOrder has none of those: a single int PK, no
 * ORM\InheritanceType hierarchy of its own (AbstractSalesDocument is a MappedSuperclass, which is
 * a compile-time field merge, not a runtime STI/JTI root), and no other version field to collide
 * with. This proves it against a real schema (DoctrineIntegrationTestCase builds one from live
 * metadata via SchemaTool — a mapping restriction here would fail setUp(), not just this test)
 * and a real flush()/find(), rather than reading the attribute and hoping.
 */
final class SalesOrderVersionMappingTest extends DoctrineIntegrationTestCase
{
    public function testANewOrderStartsAtVersionOne(): void
    {
        $order = $this->newOrder('SO-VERSION-1');
        $this->em->flush();

        self::assertSame(1, $order->getVersion());
    }

    public function testASuccessfulSaveIncrementsTheVersion(): void
    {
        $order = $this->newOrder('SO-VERSION-2');
        $this->em->flush();
        self::assertSame(1, $order->getVersion());

        $order->setPoNumber('PO-1');
        $this->em->flush();

        self::assertSame(2, $order->getVersion(), 'Doctrine increments the version column on every successful UPDATE');
    }

    public function testLockingWithTheCurrentVersionSucceeds(): void
    {
        $order = $this->newOrder('SO-VERSION-3');
        $this->em->flush();
        $currentVersion = $order->getVersion();
        $orderId = $order->getId();

        // Cleared so the find() below is a genuine fresh load (not an identity-map hit), matching
        // how a new request's first find() of this order behaves in OrderController::edit().
        $this->em->clear();
        $reloaded = $this->em->find(SalesOrder::class, $orderId, LockMode::OPTIMISTIC, $currentVersion);

        self::assertNotNull($reloaded);
        self::assertSame($currentVersion, $reloaded->getVersion());
    }

    public function testLockingWithAStaleVersionThrowsOptimisticLockException(): void
    {
        $order = $this->newOrder('SO-VERSION-4');
        $this->em->flush();
        $orderId = $order->getId();
        $staleVersion = $order->getVersion();

        // Stands in for "another admin's already-committed save": the row's version moves in the
        // database without the code under test ever seeing it happen.
        $order->setPoNumber('Changed by someone else');
        $this->em->flush();

        $this->em->clear();

        $this->expectException(OptimisticLockException::class);
        $this->em->find(SalesOrder::class, $orderId, LockMode::OPTIMISTIC, $staleVersion);
    }

    private function newOrder(string $orderNumber): SalesOrder
    {
        $company = (new Company())->setName('Version Co')->setCode('VC-' . $orderNumber);
        $this->em->persist($company);

        $order = (new SalesOrder())->setOrderNumber($orderNumber);
        $order->setCompany($company);
        $this->em->persist($order);

        return $order;
    }
}
