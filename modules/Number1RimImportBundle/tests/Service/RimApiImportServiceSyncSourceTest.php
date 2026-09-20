<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Tests\Service;

use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Service\AppSettings;
use App\Service\Inventory\BackorderReleaseService;
use App\Service\Inventory\CoreInventoryTotalCountService;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\Inventory\DimensionalImportResolver;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\WarehouseFulfillmentRegionService;
use App\Service\ProductImport\ProductImportService;
use App\Service\Uom\ProductBaseUnitService;
use App\Tests\DoctrineIntegrationTestCase;
use Number1RimImportBundle\Service\RimApiClient;
use Number1RimImportBundle\Service\RimApiImportService;
use Number1RimImportBundle\Service\RimApiTransformer;
use Number1RimImportBundle\Service\RimImageSyncService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression guard for issue #477.
 *
 * Until #477 this was the only creation path in the system that stamped ProductCore::syncSource
 * at all — every other one left it null, which is exactly what made the column useless. #477
 * added stamping everywhere else and, more dangerously, made core's shared ProductImportService
 * take a caller-supplied sync_source. That is a change to the code path this import runs through,
 * so the one value that already worked is the one most worth pinning down.
 *
 * Note what this asserts about update: this bundle stamps in its own post-import pass over every
 * touched SKU, not through the shared importer's create-only option, so a rim product keeps being
 * re-stamped on every sync. That is deliberate and unchanged — RimApiImportService::
 * inactivateMissing() is scoped to syncSource = SYNC_SOURCE, so the stamp is load-bearing rather
 * than decorative here.
 */
final class RimApiImportServiceSyncSourceTest extends DoctrineIntegrationTestCase
{
    private function buildService(MockHttpClient $httpClient): RimApiImportService
    {
        $importService = new ProductImportService(
            [],
            self::getContainer()->get(BundleStatusRepository::class),
            self::getContainer()->get(InventoryOperationContext::class),
            self::getContainer()->get(AppSettings::class),
            self::getContainer()->get(WarehouseFulfillmentRegionService::class),
            self::getContainer()->get(BackorderReleaseService::class),
            self::getContainer()->get(InventoryModeResolver::class),
            self::getContainer()->get(DimensionalImportResolver::class),
            self::getContainer()->get(ProductBaseUnitService::class),
            self::getContainer()->get(CoreInventoryTotalCountService::class),
        );

        return new RimApiImportService(
            new RimApiClient($httpClient),
            new RimApiTransformer(),
            $importService,
            new RimImageSyncService($httpClient, self::getContainer()->getParameter('kernel.project_dir')),
            self::getContainer()->get(CustomFieldDefinitionRepository::class),
            self::getContainer()->get(CustomFieldValueRepository::class),
        );
    }

    /** @param list<list<string>> $rows header row + data rows, the shape RimApiClient::fetchRows() returns */
    private function apiResponse(array $rows): MockHttpClient
    {
        return new MockHttpClient(new MockResponse(json_encode(['data' => $rows], JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
    }

    public function testSyncStampsTheRimApiSyncSourceOnACreatedProduct(): void
    {
        $service = $this->buildService($this->apiResponse([
            ['Part No', 'Brand', 'Model', 'Finish', 'Size', 'Weight', 'Price Shop', 'Price Default', 'Remark'],
            ['SKU-RIM-SYNC-1', 'Acme', 'GT Line', 'Gloss Black', '18x7.5', '22.5', '150.00', '175.00', ''],
        ]));

        $service->sync($this->em, [
            'api_url' => 'https://rim.example.test/v1/get-wheels',
            'api_client_id' => 'client',
            'api_key' => 'key',
            'category' => null,
            'region' => null,
            'deactivate_missing' => false,
        ]);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-RIM-SYNC-1']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame('number1-rim-api', $product->getSyncSource());
        self::assertSame(RimApiImportService::SYNC_SOURCE, $product->getSyncSource());
    }

    /**
     * The value itself is pinned by issue #477's decision table, and by the 455 production rows
     * already carrying it — renaming it would orphan every one of them and silently empty
     * inactivateMissing()'s scope.
     */
    public function testTheRimSyncSourceValueIsUnchanged(): void
    {
        self::assertSame('number1-rim-api', RimApiImportService::SYNC_SOURCE);
    }
}
