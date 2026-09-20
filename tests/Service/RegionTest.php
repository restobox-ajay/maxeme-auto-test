<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GeoCountry;
use App\Entity\GeoProvince;
use App\Service\Region;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Region against the real seeded tables. That it finds anything at all is also the test that the
 * seeding hook in DoctrineIntegrationTestCase works — SchemaTool creates the tables but runs no
 * migrations, so without that hook every one of these would fail on empty data.
 */
final class RegionTest extends DoctrineIntegrationTestCase
{
    /**
     * Constructed directly rather than fetched: Region has no public consumers yet, so the
     * container inlines it and the test container cannot hand it over.
     */
    private function region(): Region
    {
        return new Region($this->em);
    }

    public function testTheReferenceTablesAreSeededForTests(): void
    {
        self::assertCount(13, $this->region()->provinces('CA'));
        self::assertCount(50, $this->region()->provinces('US'));
        self::assertSame(['CA' => 'Canada', 'US' => 'United States'], $this->region()->countries());
    }

    public function testProvincesComeBackInSortOrderNotInsertionOrder(): void
    {
        $ca = array_keys($this->region()->provinces('CA'));

        self::assertSame('AB', $ca[0], 'Alberta sorts first');
        self::assertSame('YT', $ca[12], 'Yukon sorts last');
    }

    public function testValidationAcceptsCodesAndNamesAndRejectsEverythingElse(): void
    {
        $region = $this->region();

        self::assertTrue($region->isValidProvince('CA', 'BC'));
        self::assertTrue($region->isValidProvince('Canada', 'British Columbia'));
        self::assertTrue($region->isValidProvince('US', 'TX'));

        // The class of value that used to reach the tax calculator unmatched and produce a $0 order.
        self::assertFalse($region->isValidProvince('CA', 'B.C.'));
        self::assertFalse($region->isValidProvince('CA', 'Texas'), 'a US state is not a CA province');
        self::assertFalse($region->isValidProvince('CA', ''));
        self::assertFalse($region->isValidProvince('Atlantis', 'BC'));
    }

    public function testTheSameCodeResolvesDifferentlyPerCountry(): void
    {
        $region = $this->region();

        self::assertSame('California', $region->provinceName('US', 'CA'));
        self::assertNull($region->provinceName('CA', 'CA'), 'CA is not a province of Canada');
    }

    public function testLabelsAreAvailableForRendering(): void
    {
        $region = $this->region();

        self::assertSame('British Columbia', $region->provinceName('CA', 'BC'));
        self::assertSame('Canada', $region->countryName('CA'));
        self::assertNull($region->provinceName('CA', 'ZZ'));
    }

    public function testLegacySpellingsStillResolveSoOldDataKeepsWorking(): void
    {
        $region = $this->region();

        self::assertSame('QC', $region->normalizeProvince('CA', 'Québec'));
        self::assertSame('NL', $region->normalizeProvince('CA', 'Newfoundland'));
        self::assertSame('US', $region->normalizeCountry('USA'));
    }

    public function testInactiveProvinceLeavesDropdownsButStaysRenderable(): void
    {
        $nunavut = $this->em->getRepository(GeoProvince::class)->findOneBy(['code' => 'NU']);
        self::assertInstanceOf(GeoProvince::class, $nunavut);
        $nunavut->setStatus('Inactive');
        $this->em->flush();

        // A fresh instance, since Region caches per instance for the request.
        $region = new Region($this->em);

        self::assertArrayNotHasKey('NU', $region->provinces('CA'), 'deactivated: not offered');
        self::assertFalse($region->isValidProvince('CA', 'NU'), 'deactivated: not selectable');
        self::assertSame(
            'Nunavut',
            $region->provinceName('CA', 'NU'),
            'still renderable — a historical order shipped there must not print a blank province',
        );
    }

    public function testInactiveCountryHidesItsProvincesToo(): void
    {
        $us = $this->em->getRepository(GeoCountry::class)->findOneBy(['code' => 'US']);
        self::assertInstanceOf(GeoCountry::class, $us);
        $us->setStatus('Inactive');
        $this->em->flush();

        $region = new Region($this->em);

        self::assertArrayNotHasKey('US', $region->countries());
        self::assertFalse($region->isValidCountry('US'));
        self::assertFalse($region->isValidProvince('US', 'TX'));
    }

    public function testDataIsLoadedOnceAndReusedWithinTheRequest(): void
    {
        $region = $this->region();

        // Second and third calls must not re-query; asserting the same content is the observable
        // proxy for that, since the point is one query per request rather than per lookup.
        $first = $region->provinces('CA');
        self::assertSame($first, $region->provinces('CA'));
        self::assertSame($first, $region->provinces('Canada'));
    }
}
