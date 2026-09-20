<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Support;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use CustomHeaderFooterBundle\Service\CustomHeaderFooterStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Builds a real CustomHeaderFooterStore (and the real AppSettings it wraps) backed by an
 * in-memory fake of the Doctrine repository/entity-manager calls the store actually makes
 * (getRepository()->findOneBy(), persist(), flush(), remove()) plus a real ArrayAdapter cache
 * — no database, no kernel boot. AppSettings and CustomHeaderFooterStore are both `final`, so
 * they can't be mocked directly; faking their two dependencies (EntityManagerInterface and the
 * cache) is the only way to unit test them in isolation.
 */
trait CreatesInMemoryStore
{
    /** @return array{0: CustomHeaderFooterStore, 1: callable(): int} */
    private function createStore(): array
    {
        $rows = [];

        // Regular closures with explicit `use (&$rows)` are required here, not `fn(...) =>`
        // arrow functions — arrow functions auto-capture `$rows` by value at creation time
        // (frozen to the empty array), so later persist()/remove() calls would never be seen.
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use (&$rows): ?AppSetting {
                return $rows[$criteria['settingKey']] ?? null;
            },
        );
        $repository->method('findBy')->willReturnCallback(
            static function () use (&$rows): array {
                return array_values($rows);
            },
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(AppSetting::class)->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$rows): void {
                if ($entity instanceof AppSetting) {
                    $rows[$entity->getSettingKey()] = $entity;
                }
            },
        );
        $entityManager->method('remove')->willReturnCallback(
            static function (object $entity) use (&$rows): void {
                if ($entity instanceof AppSetting) {
                    unset($rows[$entity->getSettingKey()]);
                }
            },
        );
        $entityManager->method('flush');

        $appSettings = new AppSettings($entityManager, new ArrayAdapter());
        $store = new CustomHeaderFooterStore($appSettings, $entityManager);

        /** How many distinct AppSetting rows currently exist, at call time. */
        $countRows = static function () use (&$rows): int {
            return \count($rows);
        };

        return [$store, $countRows];
    }
}
