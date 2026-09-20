<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SalesOrder;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Service\AppSettings;
use App\Service\DocumentNumberAllocator;
use App\Service\OrderNumberGenerator;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises next() against a real EntityManager/connection since it drives raw SQL
 * (SUBSTR/CAST over order_number) that a mocked EntityManager couldn't meaningfully fake.
 */
final class OrderNumberGeneratorTest extends DoctrineIntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    private function generator(?string $prefix): OrderNumberGenerator
    {
        $row = (new AppSetting())->setSettingKey('order_number_prefix')->setName('order_number_prefix')->setSettingValue($prefix);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn([$row]);

        $settingsEm = $this->createStub(EntityManagerInterface::class);
        $settingsEm->method('getRepository')->willReturn($repo);

        return new OrderNumberGenerator(new AppSettings($settingsEm, new ArrayAdapter()), new DocumentNumberAllocator());
    }

    private function persistOrder(string $orderNumber): void
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber($orderNumber);

        $this->em->persist($order);
        $this->em->flush();
    }

    public function testFirstOrderUsesConfiguredPrefixAndStartsAtOne(): void
    {
        self::assertSame('INV1', $this->generator('INV')->next($this->em));
    }

    public function testUsesDefaultPrefixWhenSettingBlank(): void
    {
        self::assertSame('SO-1', $this->generator('')->next($this->em));
    }

    public function testIncrementsFromExistingMaxOrderNumber(): void
    {
        $this->persistOrder('HD5');

        self::assertSame('HD6', $this->generator('HD')->next($this->em));
    }

    public function testIgnoresOrdersUnderADifferentPrefix(): void
    {
        $this->persistOrder('QT9');

        self::assertSame('HD1', $this->generator('HD')->next($this->em));
    }

    public function testDoesNotBackfillGapLeftByAManuallyRenumberedOrder(): void
    {
        // HD2 was skipped (e.g. a manual renumber) — next() must continue from the true
        // max rather than filling the gap.
        $this->persistOrder('HD1');
        $this->persistOrder('HD3');

        self::assertSame('HD4', $this->generator('HD')->next($this->em));
    }
}
