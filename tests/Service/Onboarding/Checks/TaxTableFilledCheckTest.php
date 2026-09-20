<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Entity\SalesTax;
use App\Service\Onboarding\Checks\TaxTableFilledCheck;
use App\Tests\DoctrineIntegrationTestCase;

/** #426: "check tax table is filled with at least 1 row." */
final class TaxTableFilledCheckTest extends DoctrineIntegrationTestCase
{
    public function testFreshInstallWithNoTaxRowsFails(): void
    {
        $result = (new TaxTableFilledCheck($this->em))->run();

        self::assertFalse($result->passed);
    }

    public function testOneRowIsEnoughToPass(): void
    {
        $tax = (new SalesTax())
            ->setProvinceName('British Columbia')
            ->setAbbreviation('BC')
            ->setTaxType('PST')
            ->setRate(7.000);
        $this->em->persist($tax);
        $this->em->flush();

        $result = (new TaxTableFilledCheck($this->em))->run();

        self::assertTrue($result->passed);
    }

    public function testAnInactiveRowStillCountsAsFillingTheTable(): void
    {
        // The issue's wording is "filled with at least 1 row" — a row existing at all satisfies
        // it, regardless of Active/Inactive status (unlike other checks that filter to Active).
        $tax = (new SalesTax())
            ->setProvinceName('Alberta')
            ->setAbbreviation('AB')
            ->setTaxType('GST')
            ->setRate(5.000)
            ->setStatus('Inactive');
        $this->em->persist($tax);
        $this->em->flush();

        self::assertTrue((new TaxTableFilledCheck($this->em))->run()->passed);
    }
}
