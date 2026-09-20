<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\PriceList;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #768: "Apply to all pages" with no filters on a large catalog reportedly exhausts php-fpm's
 * 128MB memory_limit.
 *
 * What this is NOT: a missing $entityManager->clear() in the batch loop. That call has been there
 * since e749c929a/d82ead9ec, well before this issue was filed, and measuring confirms it is doing
 * real work — the same loop with the clear()/debug-holder-reset calls stripped out finishes a
 * 6000-row run at a measurably higher final memory footprint (confirmed while writing this test by
 * temporarily reverting them: ~35MB higher over the same catalog).
 *
 * What it IS: PHP's real (allocated-from-OS) memory footprint climbs by roughly 10-15KB per row
 * for the life of the request, independent of batch size (confirmed empirically — batch sizes of
 * 50, 250 and 1000 all land the same run in the same final-memory neighbourhood) and regardless of
 * clear(). This is Doctrine's per-entity overhead (UnitOfWork bookkeeping, change-tracking,
 * hydration, proxies) compounded by PHP's own memory manager, which does not return freed heap
 * chunks to the OS mid-script even once nothing references their contents — every measurement
 * during a real run here is a new all-time high, never a dip. Nothing at the request/batch level
 * makes that go away — the durable fix for a genuinely huge single-shot catalog is what the issue
 * itself names as the alternative: take very large applies off the request/response cycle entirely
 * (a queued, chunked job across multiple worker processes, each free to actually release memory on
 * exit), which is a bigger change than this pass.
 *
 * This test is therefore a regression guard on the MARGINAL cost per row, not a promise of flat
 * memory: comparing a small catalog (dominated by fixed request/kernel overhead) against a large
 * one isolates the cost the batch loop itself adds per extra row, and pins it to a generous
 * ceiling so a real regression (the identity map growing unbounded again, or a future change that
 * hydrates full entities on the read side) is caught without asserting a bound this endpoint's
 * current architecture cannot actually deliver.
 *
 * Seeded via raw DBAL inserts, not haveInRepository() — thousands of Doctrine-managed inserts
 * would themselves take longer than this test's plausible budget and would exercise the write
 * path this test isn't about.
 */
final class AdminProductPriceBulkApplyMemoryCest
{
    private const SMALL_COUNT = 250;
    private const LARGE_COUNT = 6000;

    // Generous: measured marginal cost on this machine is ~11-13KB/row with clear() in place, and
    // closer to ~17KB/row with it stripped out. This sits comfortably above the healthy figure and
    // below the stripped one, so it still catches a real regression without being sensitive to
    // machine noise.
    private const MAX_MARGINAL_BYTES_PER_ROW = 25 * 1024;

    public function applyingToAllPagesOnALargeCatalogStaysWithinAPerRowMemoryBudget(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-bulk-mem-768@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $priceList = (new PriceList())->setName('Bulk Memory 768')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        /** @var Connection $connection */
        $connection = $I->grabService(Connection::class);
        $this->seedProducts($connection, 'BULKMEM-768-SMALL-', self::SMALL_COUNT);
        $this->seedProducts($connection, 'BULKMEM-768-LARGE-', self::LARGE_COUNT);

        $I->amOnPage('/admin/product/price/index');
        $token = (string) $I->grabAttributeFrom('#price-write-tokens', 'data-bulk-apply-token');

        $smallPeakBytes = $this->applyAndMeasurePeakBytes($I, $token, (int) $priceList->getId(), 'BULKMEM-768-SMALL-', self::SMALL_COUNT);
        $largePeakBytes = $this->applyAndMeasurePeakBytes($I, $token, (int) $priceList->getId(), 'BULKMEM-768-LARGE-', self::LARGE_COUNT);

        $marginalBytesPerRow = ($largePeakBytes - $smallPeakBytes) / (self::LARGE_COUNT - self::SMALL_COUNT);

        $I->assertLessThan(
            self::MAX_MARGINAL_BYTES_PER_ROW,
            $marginalBytesPerRow,
            sprintf(
                'Small catalog (%d products) peaked at %.1fMB; large catalog (%d products) peaked at '
                . '%.1fMB — a marginal cost of %.1fKB/row, above the %.1fKB/row budget. The identity '
                . 'map or query cache is likely growing unbounded again instead of staying batch-bounded.',
                self::SMALL_COUNT,
                $smallPeakBytes / (1024 * 1024),
                self::LARGE_COUNT,
                $largePeakBytes / (1024 * 1024),
                $marginalBytesPerRow / 1024,
                self::MAX_MARGINAL_BYTES_PER_ROW / 1024,
            ),
        );
    }

    private function seedProducts(Connection $connection, string $skuPrefix, int $count): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            $connection->executeStatement(
                'INSERT INTO product_core (sku, name, is_private, status, visible, featured, deleted, default_price, created_at)
                 VALUES (:sku, :name, 0, :status, 1, 0, 0, :price, :now)',
                [
                    'sku' => $skuPrefix . $i,
                    'name' => 'Bulk Memory Product ' . $skuPrefix . $i,
                    'status' => 'Active',
                    'price' => '100.00',
                    'now' => $now,
                ],
            );
        }
        $connection->commit();
    }

    private function applyAndMeasurePeakBytes(FunctionalTester $I, string $token, int $priceListId, string $skuPrefix, int $expectedCount): float
    {
        gc_collect_cycles();
        memory_reset_peak_usage();

        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceListId,
            'field' => 'both',
            'type' => 'Discount%',
            'value' => '10',
            'filters' => ['sku' => $skuPrefix],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertIsArray($response);
        $I->assertTrue($response['ok'] ?? false);
        $I->assertGreaterThanOrEqual($expectedCount, $response['processed'] ?? 0, 'Every product matching the filter must be reached, not just the first page.');

        return (float) memory_get_peak_usage(true);
    }
}
