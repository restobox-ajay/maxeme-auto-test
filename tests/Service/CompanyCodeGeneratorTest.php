<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Service\CompanyCodeGenerator;
use App\Tests\DoctrineIntegrationTestCase;

final class CompanyCodeGeneratorTest extends DoctrineIntegrationTestCase
{
    private CompanyCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new CompanyCodeGenerator();
    }

    private function persistCompany(string $code): void
    {
        $company = (new Company())->setName($code)->setCode($code);
        $this->em->persist($company);
        $this->em->flush();
    }

    public function testGenerateSanitizesAndUppercasesName(): void
    {
        $code = $this->generator->generate($this->em, "Acme's Hardware & Co.");

        self::assertSame('ACMESHARDWAR', $code);
    }

    public function testGenerateTruncatesToTwelveCharactersOnFirstAttempt(): void
    {
        $code = $this->generator->generate($this->em, 'SupercalifragilisticExpialidocious');

        self::assertSame(12, \strlen($code));
        self::assertSame('SUPERCALIFRA', $code);
    }

    public function testGenerateFallsBackToCompWhenNameHasNoAlphanumericChars(): void
    {
        $code = $this->generator->generate($this->em, '!!! ---');

        self::assertSame('COMP', $code);
    }

    public function testGenerateAppendsNumericSuffixOnCollision(): void
    {
        $this->persistCompany('ACME');

        $code = $this->generator->generate($this->em, 'Acme');

        self::assertNotSame('ACME', $code);
        self::assertMatchesRegularExpression('/^ACME\d{4}$/', $code);

        self::assertNull(
            $this->em->getRepository(Company::class)->findOneBy(['code' => $code])
        );
    }

    public function testGenerateReturnsBaseWhenNoCollision(): void
    {
        $this->persistCompany('OTHERCO');

        $code = $this->generator->generate($this->em, 'Acme');

        self::assertSame('ACME', $code);
    }
}
