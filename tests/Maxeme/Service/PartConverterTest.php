<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Service;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoicePartLine;
use App\Maxeme\Entity\Part;
use App\Maxeme\Enum\PartType;
use App\Maxeme\Service\PartConverter;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;

/** The legacy parts become catalogue products, with their stock and their invoice lines. */
final class PartConverterTest extends DoctrineIntegrationTestCase
{
    public function testPartsBecomeProductsOnceWithTheirStockAndInvoiceLines(): void
    {
        $filter = (new Part())->setVin('of-123')->setName('Oil filter')->setManufacturer('Fram')->setType(PartType::Unit)->setUnitPrice('4.00')->setSalePrice('9.50');
        $filter->adjustQuantity(7);
        $wiper = (new Part())->setName('Wiper')->setVin('OF-123');
        $wiper->adjustQuantity(-2);
        $wiper->deactivate();
        array_map($this->em->persist(...), [$filter, $wiper]);
        $invoice = Invoice::forClient((new Client())->setFirstName('Dan'), null, 5, 7);
        $this->em->persist($invoice->getClient());
        $this->em->persist($invoice);
        $line = new InvoicePartLine($invoice, 'Oil filter', 1, '4.00', '9.50');
        (new \ReflectionProperty(InvoicePartLine::class, 'part'))->setValue($line, $filter);
        $this->em->persist($line);
        $this->em->flush();

        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->warehouseForRegionNameOrCreate('Main', 'BC', 'CA');
        $converter = self::getContainer()->get(PartConverter::class);
        self::assertSame(['created' => 2, 'updated' => 0, 'lines' => 1], $converter->convert($warehouse));
        self::assertSame(['created' => 0, 'updated' => 2, 'lines' => 0], $converter->convert($warehouse), 'a re-run updates, never duplicates');

        $products = $this->em->getRepository(ProductCore::class);
        $oil = $products->findOneBy(['syncSource' => PartConverter::SOURCE_PREFIX . $filter->getId()]);
        self::assertSame('OF-123', $oil->getSku(), 'the part number, upper-cased');
        self::assertSame('Active', $oil->getStatus());
        self::assertSame(['4.00', '9.50'], [substr((string) $oil->getCostPrice(), 0, 4), substr((string) $oil->getDefaultPrice(), 0, 4)]);
        self::assertSame("Manufacturer: Fram\nType: Unit", $oil->getRemarks());

        $wiperProduct = $products->findOneBy(['syncSource' => PartConverter::SOURCE_PREFIX . $wiper->getId()]);
        self::assertSame('PART-' . $wiper->getId(), $wiperProduct->getSku(), 'its number is taken, so PART-{id}');
        self::assertSame('Inactive', $wiperProduct->getStatus(), 'a deleted part is kept as an inactive product');

        $stock = $this->em->getRepository(ProductInventory::class);
        self::assertSame(7.0, (float) $stock->findOneBy(['product' => $oil, 'warehouse' => $warehouse])->getQuantity());
        self::assertSame(-2.0, (float) $stock->findOneBy(['product' => $wiperProduct, 'warehouse' => $warehouse])->getQuantity());

        $this->em->refresh($line);
        self::assertSame($oil->getId(), $line->getProduct()?->getId(), 'the invoice line now names the product');
    }
}
