<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\Tests\Service;

use App\Entity\Company;
use App\Entity\CustomFieldDefinition;
use App\Repository\CustomFieldDefinitionRepository;
use App\Tests\DoctrineIntegrationTestCase;
use TaxCanadaSimpleBundle\Service\CanadaTaxRegistrations;
use TaxCanadaSimpleBundle\Tax\CanadaSimpleTaxCalculator;

final class CanadaTaxRegistrationsTest extends DoctrineIntegrationTestCase
{
    private CanadaTaxRegistrations $registrations;
    private CustomFieldDefinitionRepository $definitionRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrations = self::getContainer()->get(CanadaTaxRegistrations::class);
        $this->definitionRepo = self::getContainer()->get(CustomFieldDefinitionRepository::class);
    }

    /** Custom field values are now FK'd to a real company row (#422), so 1/2 can no longer stand in for an id. */
    private function company(string $code): int
    {
        $company = (new Company())->setName('Tax Reg Co ' . $code)->setCode($code);
        $this->em->persist($company);
        $this->em->flush();

        return $company->getId();
    }

    public function testEnsureDefinitionsRegistersOneCompanyFieldPerProvince(): void
    {
        $this->registrations->ensureDefinitions();

        $expected = [
            'tax_reg_sk_pst' => 'Saskatchewan PST #',
            'tax_reg_mb_rst' => 'Manitoba RST #',
            'tax_reg_qc_qst' => 'Quebec QST #',
        ];

        foreach ($expected as $slug => $label) {
            $definition = $this->definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, $slug);

            $this->assertNotNull($definition, sprintf('Expected %s to be registered on company.', $slug));
            $this->assertSame($label, $definition->getLabel());
            $this->assertSame(CustomFieldDefinition::FIELD_TYPE_TEXT, $definition->getFieldType());
            // The admin company form's capture surface comes free from CustomFieldRenderer only
            // when these are true — this bundle ships no form code of its own.
            $this->assertTrue($definition->isVisibleOnAdd());
            $this->assertTrue($definition->isVisibleOnEdit());
            // Must match the descriptor's source or Bundle Management can't switch these off.
            $this->assertSame(CanadaSimpleTaxCalculator::SOURCE, $definition->getSource());
        }
    }

    public function testEnsureDefinitionsIsIdempotent(): void
    {
        $this->registrations->ensureDefinitions();
        $first = $this->definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, 'tax_reg_sk_pst');

        // Runs on every request, so a second call must not duplicate or replace the row.
        $this->registrations->ensureDefinitions();
        $second = $this->definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, 'tax_reg_sk_pst');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->getId(), $second->getId());
        $this->assertCount(3, $this->definitionRepo->findByObjectType(CustomFieldDefinition::OBJECT_TYPE_COMPANY));
    }

    public function testSetThenGetRoundTripsPerProvince(): void
    {
        $companyId = $this->company('C1');
        $this->registrations->set($companyId, 'SK', 'SK-11111');
        $this->registrations->set($companyId, 'MB', 'MB-22222');
        $this->em->flush();

        $this->assertSame('SK-11111', $this->registrations->get($companyId, 'SK'));
        $this->assertSame('MB-22222', $this->registrations->get($companyId, 'MB'));
        // Separate fields: a value stored for one province must not read back for another.
        $this->assertNull($this->registrations->get($companyId, 'QC'));
    }

    public function testValuesAreScopedToTheCompany(): void
    {
        $companyId = $this->company('C1');
        $otherCompanyId = $this->company('C2');
        $this->registrations->set($companyId, 'SK', 'SK-11111');
        $this->em->flush();

        $this->assertNull($this->registrations->get($otherCompanyId, 'SK'));
    }

    public function testBlankAndWhitespaceValuesNormaliseToNull(): void
    {
        $companyId = $this->company('C1');
        $this->registrations->set($companyId, 'SK', 'SK-11111');
        $this->em->flush();

        $this->registrations->set($companyId, 'SK', '   ');
        $this->em->flush();

        $this->assertNull($this->registrations->get($companyId, 'SK'));
    }

    public function testGetReturnsNullBeforeAnyFieldIsRegistered(): void
    {
        // No ensureDefinitions() call — a read must not care whether the subscriber has run yet.
        $this->assertNull($this->registrations->get(1, 'SK'));
    }

    public function testSetRegistersTheFieldWhenItIsMissing(): void
    {
        $companyId = $this->company('C1');
        $this->registrations->set($companyId, 'QC', 'QST-33333');
        $this->em->flush();

        $this->assertNotNull(
            $this->definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, 'tax_reg_qc_qst')
        );
        $this->assertSame('QST-33333', $this->registrations->get($companyId, 'QC'));
    }

    public function testAllReturnsEveryProvinceIncludingEmptyOnes(): void
    {
        $companyId = $this->company('C1');
        $this->registrations->set($companyId, 'MB', 'MB-22222');
        $this->em->flush();

        $this->assertSame(
            ['SK' => null, 'MB' => 'MB-22222', 'QC' => null],
            $this->registrations->all($companyId)
        );
    }

    public function testProvincesWithoutAConfiguredFieldAreUnsupported(): void
    {
        // Pins the configured set in PROVINCES. BC is excluded here because its PST # is
        // core's Company::$pstNumber, not a field this bundle owns.
        foreach (['ON', 'NB', 'NL', 'NS', 'PE', 'AB', 'BC'] as $province) {
            $this->assertFalse($this->registrations->supports($province), $province.' should be unsupported');
            $this->assertNull($this->registrations->get(1, $province));
            $this->assertNull($this->registrations->label($province));
        }

        foreach (['SK', 'MB', 'QC'] as $province) {
            $this->assertTrue($this->registrations->supports($province));
        }
    }

    public function testSetRejectsAProvinceWithNoRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Province "ON" has no registration field configured');

        $this->registrations->set(1, 'ON', 'whatever');
    }

    public function testLegacyProvinceDisplayNamesResolveToTheSameField(): void
    {
        $companyId = $this->company('C1');
        $this->registrations->set($companyId, 'Saskatchewan', 'SK-11111');
        $this->em->flush();

        $this->assertSame('SK-11111', $this->registrations->get($companyId, 'SK'));
        $this->assertTrue($this->registrations->supports('Saskatchewan'));
    }

    public function testLabelsMatchTheConfiguredSet(): void
    {
        // Pins the labels shown on the admin company form.
        $this->assertSame('Manitoba RST #', $this->registrations->label('MB'));
        $this->assertSame('Saskatchewan PST #', $this->registrations->label('SK'));
        $this->assertSame('Quebec QST #', $this->registrations->label('QC'));
    }
}
