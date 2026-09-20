<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Service\AppSettings;
use App\Service\DocumentNumberAllocator;
use App\Service\EstimateNumberGenerator;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises next() against a real EntityManager/connection since it drives raw SQL
 * (SUBSTR/CAST over document_number) that a mocked EntityManager couldn't meaningfully fake.
 */
final class EstimateNumberGeneratorTest extends DoctrineIntegrationTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    private function generator(?string $prefix): EstimateNumberGenerator
    {
        $row = (new AppSetting())->setSettingKey('quote_number_prefix')->setName('quote_number_prefix')->setSettingValue($prefix);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn([$row]);

        $settingsEm = $this->createStub(EntityManagerInterface::class);
        $settingsEm->method('getRepository')->willReturn($repo);

        return new EstimateNumberGenerator(new AppSettings($settingsEm, new ArrayAdapter()), new DocumentNumberAllocator());
    }

    private function persistEstimate(string $documentNumber): void
    {
        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber($documentNumber);

        $this->em->persist($estimate);
        $this->em->flush();
    }

    public function testFirstEstimateUsesConfiguredPrefixAndStartsAtOne(): void
    {
        self::assertSame('EST1', $this->generator('EST')->next($this->em));
    }

    public function testUsesDefaultPrefixWhenSettingBlank(): void
    {
        self::assertSame('QO-1', $this->generator('')->next($this->em));
    }

    public function testIncrementsFromExistingMaxDocumentNumber(): void
    {
        $this->persistEstimate('QT5');

        self::assertSame('QT6', $this->generator('QT')->next($this->em));
    }

    public function testIgnoresEstimatesUnderADifferentPrefix(): void
    {
        $this->persistEstimate('HD9');

        self::assertSame('QT1', $this->generator('QT')->next($this->em));
    }

    public function testDoesNotBackfillGapLeftByAManuallyRenumberedEstimate(): void
    {
        // QT2 was skipped (e.g. a manual renumber) — next() must continue from the true
        // max rather than filling the gap.
        $this->persistEstimate('QT1');
        $this->persistEstimate('QT3');

        self::assertSame('QT4', $this->generator('QT')->next($this->em));
    }
}
