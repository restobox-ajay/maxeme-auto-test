<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises app:backfill-company-fulfillment-regions through the real CommandTester path
 * against a real EntityManager — the command's whole point is collapsing companies stuck
 * with zero/multiple Active CompanyFulfillmentRegion rows down to a single Active "Main"
 * row, and a mocked EntityManager would prove nothing about that collapsing behavior.
 */
final class BackfillCompanyFulfillmentRegionsCommandTest extends DoctrineIntegrationTestCase
{
    private CommandTester $tester;
    private FulfillmentRegion $mainRegion;
    private PriceList $priceList;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $command = $application->find('app:backfill-company-fulfillment-regions');
        $this->tester = new CommandTester($command);

        $this->mainRegion = (new FulfillmentRegion())->setName('Main');
        $this->em->persist($this->mainRegion);

        $this->priceList = (new PriceList())->setName('Standard')->setStatus('Active');
        $this->em->persist($this->priceList);
    }

    private function newCompany(string $code): Company
    {
        $company = (new Company())->setName('Company ' . $code)->setCode($code);
        $this->em->persist($company);

        return $company;
    }

    public function testFailsWhenNoMainRegionExists(): void
    {
        // Remove the "Main" region the setUp() created so this test starts from a clean slate.
        $this->em->remove($this->mainRegion);
        $this->em->flush();

        $this->newCompany('ACME');
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No FulfillmentRegion named "Main" exists', $this->tester->getDisplay());
    }

    public function testFailsWhenNoPriceListConfiguredOrAvailable(): void
    {
        $this->em->remove($this->priceList);
        $this->em->flush();

        $this->newCompany('ACME');
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No default price list is configured', $this->tester->getDisplay());
    }

    public function testCollapsesMultipleActiveRegionsToMainOnly(): void
    {
        $west = (new FulfillmentRegion())->setName('West');
        $this->em->persist($west);
        $company = $this->newCompany('ACME');
        $this->em->flush();

        // Both rows Active is the broken "stuck with many Active rows" state this command exists to fix.
        $mainRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($this->mainRegion)->setStatus('Inactive');
        $westRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($west)->setStatus('Active');
        $this->em->persist($mainRow);
        $this->em->persist($westRow);
        $this->em->flush();

        // Flip Main to Active too, bypassing app logic, to simulate the pre-existing broken state.
        $mainRow->setStatus('Active');
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 companies processed, 1 fixed', $this->tester->getDisplay());

        $this->em->refresh($mainRow);
        $this->em->refresh($westRow);
        self::assertTrue($mainRow->isActive());
        self::assertSame($this->priceList->getId(), $mainRow->getPriceList()?->getId());
        self::assertFalse($westRow->isActive());
    }

    public function testLeavesCompanyWithExactlyOneActiveNonMainRegionUntouched(): void
    {
        $west = (new FulfillmentRegion())->setName('West');
        $this->em->persist($west);
        $company = $this->newCompany('ACME');
        $this->em->flush();

        $mainRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($this->mainRegion)->setStatus('Inactive');
        $westRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($west)->setStatus('Active')->setPriceList($this->priceList);
        $this->em->persist($mainRow);
        $this->em->persist($westRow);
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 companies processed, 0 fixed', $this->tester->getDisplay());

        $this->em->refresh($mainRow);
        $this->em->refresh($westRow);
        self::assertFalse($mainRow->isActive());
        self::assertTrue($westRow->isActive());
    }

    public function testCreatesMissingRegionRowsForCompanyWithNoRows(): void
    {
        $west = (new FulfillmentRegion())->setName('West');
        $this->em->persist($west);
        $company = $this->newCompany('ACME');
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('2 region row(s) created', $this->tester->getDisplay());

        $rows = $this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['company' => $company]);
        self::assertCount(2, $rows);

        $mainRow = null;
        foreach ($rows as $row) {
            if ($row->getFulfillmentRegion()->getId() === $this->mainRegion->getId()) {
                $mainRow = $row;
            }
        }
        self::assertNotNull($mainRow);
        self::assertTrue($mainRow->isActive());
        self::assertSame($this->priceList->getId(), $mainRow->getPriceList()?->getId());
    }

    public function testDryRunReportsWithoutWritingAnyChanges(): void
    {
        $west = (new FulfillmentRegion())->setName('West');
        $this->em->persist($west);
        $company = $this->newCompany('ACME');
        $this->em->flush();

        $mainRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($this->mainRegion)->setStatus('Active');
        $westRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($west)->setStatus('Active');
        $this->em->persist($mainRow);
        $this->em->persist($westRow);
        $this->em->flush();

        $exitCode = $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[DRY RUN] 1 companies processed, 1 fixed', $this->tester->getDisplay());

        $this->em->refresh($mainRow);
        $this->em->refresh($westRow);
        self::assertTrue($mainRow->isActive());
        self::assertNull($mainRow->getPriceList());
        self::assertTrue($westRow->isActive());
    }
}
