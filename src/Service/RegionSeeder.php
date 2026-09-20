<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeoCountry;
use App\Entity\GeoProvince;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotently populates geo_country / geo_province from {@see RegionSeedData}.
 *
 * Exists because a migration is not the only way this schema comes into being. Both test suites
 * build their schema with SchemaTool — PHPUnit via DoctrineIntegrationTestCase, Codeception via
 * `doctrine:schema:create` in tests/_bootstrap.php — and neither runs migrations. Without a
 * programmatic seed the geo tables would be empty in every test, so every province validation would
 * fail and every dropdown would render blank.
 *
 * The seed migration carries the same rows as SQL rather than calling this, because a migration
 * should not depend on the current shape of an entity. Both read RegionSeedData, so their contents
 * cannot drift.
 *
 * Only inserts what is missing. Existing rows are left exactly as they are — an operator who has
 * deactivated a province, or corrected a name, does not get it silently reset on the next deploy.
 */
final class RegionSeeder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array{countries: int, provinces: int} how many rows were created */
    public function seed(): array
    {
        $countryRepo = $this->entityManager->getRepository(GeoCountry::class);
        $provinceRepo = $this->entityManager->getRepository(GeoProvince::class);

        $createdCountries = 0;
        $createdProvinces = 0;
        $sortCountry = 0;

        foreach (RegionSeedData::COUNTRIES as $code => $name) {
            $sortCountry += 10;

            $country = $countryRepo->findOneBy(['code' => $code]);
            if (!$country instanceof GeoCountry) {
                $country = (new GeoCountry())
                    ->setCode($code)
                    ->setName($name)
                    ->setStatus('Active')
                    ->setSortOrder($sortCountry);
                $this->entityManager->persist($country);
                ++$createdCountries;
            }

            $sortProvince = 0;
            foreach (RegionSeedData::PROVINCES[$code] ?? [] as $provinceCode => $provinceName) {
                $sortProvince += 10;

                $existing = $provinceRepo->findOneBy(['country' => $country, 'code' => $provinceCode]);
                if ($existing instanceof GeoProvince) {
                    continue;
                }

                $province = (new GeoProvince())
                    ->setCode($provinceCode)
                    ->setName($provinceName)
                    ->setStatus('Active')
                    ->setSortOrder($sortProvince);

                // addProvince() sets the owning side *and* adds to the inverse collection. Setting
                // only the owning side persists correctly but leaves $country->getProvinces() empty
                // for the rest of this EntityManager's life, because the constructor already
                // initialised it as an empty ArrayCollection and Doctrine will never lazy-load it.
                $country->addProvince($province);
                $this->entityManager->persist($province);
                ++$createdProvinces;
            }
        }

        $this->entityManager->flush();

        return ['countries' => $createdCountries, 'provinces' => $createdProvinces];
    }
}
