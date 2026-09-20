<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Service\Uom\ProductAvailableUnitService;
use App\Service\Uom\UnitOfMeasureService;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Base-unit storage on document lines, conducted through the real admin screens (#601, #659, #624).
 *
 * Two claims, and they only mean something together:
 *
 *  1. **A line that names no unit is unchanged.** The order form posts a quantity, the line stores
 *     it, the hold moves by it, and both denomination columns come out NULL — which is what "entered
 *     in the product's base unit" is spelled as, and is exactly true of every line this app has ever
 *     saved.
 *  2. **The base figure is the only one that travels.** Re-express the same line as 3 BOX-12 and
 *     `product_inventory` does not move an inch, because 3 x 12 is the 36 it was already holding.
 *     Ask for 4 BOX-12 and it moves to 48 — never to 4.
 *
 * Claim 2 is the one to review hardest, and the second half of it is the assertion that catches the
 * failure: a build where the entered figure leaked would show a hold of 3, or of 4, and would look
 * like a plain arithmetic bug months later rather than a design implemented wrongly.
 *
 * The product declares a base unit and lists BOX-12 as available throughout. A product listing no
 * units could not tell a build that resolves correctly from one that never resolves at all.
 */
final class UomBaseUnitLinesCest
{
    private const REGION = 'Base Unit Region';

    /**
     * The order form's own POST body. `qty` is in base units because no `unit_id` is posted, which
     * is what the selector's first option means and what every line said before #659.
     *
     * @return array<string, mixed>
     */
    private function orderPost(FunctionalTester $I, Company $company, ProductCore $product, string $qty): array
    {
        return [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => self::REGION,
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => $qty, 'price' => '10.00', 'tax_code' => 'E', 'location' => self::REGION],
            ],
            'save_mode' => 'order',
        ];
    }

    /**
     * The order screen behaves exactly as it did before phase 3, down to the two new columns being
     * NULL rather than filled in with a copy of the quantity.
     *
     * NULL is asserted on the columns and not on a getter: `getQuantityEntered()` answers 36 for this
     * row, correctly, and would go on answering 36 if a backfill had written 36 into the column. The
     * difference between those two databases is what "no UPDATE ran" means.
     */
    public function theOrderScreenSavesInBaseUnitsAndLeavesBothNewColumnsNull(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 1000, 'BASE');
        $this->makeAvailable($I, $product, 'BOX-12', '12');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->orderPost($I, $company, $product, '36'));

        $line = $this->onlyLineOf($I, $company);
        $row = $this->columnsOf($I, (int) $line->getId());

        $I->assertSame(36.0, (float) $row['quantity'], 'sales_order_line.quantity is the base figure and is what the form posted');
        $I->assertNull($row['quantity_entered'], 'sales_order_line.quantity_entered stays NULL: no screen writes it in phase 3');
        $I->assertNull($row['unit_id'], 'sales_order_line.unit_id stays NULL: NULL is the base unit');

        $I->assertTrue($line->isEnteredInBaseUnits());
        $I->assertSame(36.0, (float) $line->getQuantityEntered(), 'with no unit named, the entered figure IS the base figure');

        $I->assertSame(36, $this->inventoryFor($I, $product)->getSalesHoldQuantity());
    }

    /**
     * Re-expressing the same line in boxes moves nothing.
     *
     * 3 BOX-12 is the 36 the line already held, so `product_inventory` must come out of this byte for
     * byte unchanged — which is the strongest form of "the entered figure never reaches the inventory
     * layer" available: it changed from 36 to 3 and the bucket did not notice.
     */
    public function reExpressingALineInBoxesLeavesTheInventoryLayerExactlyWhereItWas(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 1000, 'CASED');
        $control = $this->makeProductWithStock($I, 1000, 'CTL');
        $box = $this->makeAvailable($I, $product, 'BOX-12', '12');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->orderPost($I, $company, $product, '36'));

        $I->assertSame(36, $this->inventoryFor($I, $product)->getSalesHoldQuantity(), 'before');

        $this->reExpress($I, $company, '3', $box);

        $line = $this->onlyLineOf($I, $company);
        $row = $this->columnsOf($I, (int) $line->getId());

        $I->assertSame(36.0, (float) $row['quantity'], 'sales_order_line.quantity: 36 -> 36');
        $I->assertSame(3.0, (float) $row['quantity_entered'], 'sales_order_line.quantity_entered: NULL -> 3');
        $I->assertSame((int) $box->getId(), (int) $row['unit_id'], 'sales_order_line.unit_id: NULL -> BOX-12');

        $inventory = $this->inventoryFor($I, $product);
        $I->assertSame(36, $inventory->getSalesHoldQuantity(), 'product_inventory.sales_hold_quantity: 36 -> 36, never 3');
        $I->assertSame(1000, $inventory->getQuantity(), 'product_inventory.quantity: a hold moves no stock');

        // The row that should NOT have changed.
        $controlRow = $this->inventoryFor($I, $control);
        $I->assertSame(0, $controlRow->getSalesHoldQuantity());
        $I->assertSame(1000, $controlRow->getQuantity());
    }

    /** Ask for four boxes and the hold becomes 48. Four never appears below the document. */
    public function askingForFourBoxesHoldsFortyEightAndNeverFour(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 1000, 'FOUR');
        $box = $this->makeAvailable($I, $product, 'BOX-12', '12');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->orderPost($I, $company, $product, '36'));

        $this->reExpress($I, $company, '4', $box);

        $row = $this->columnsOf($I, (int) $this->onlyLineOf($I, $company)->getId());
        $I->assertSame(48.0, (float) $row['quantity'], 'sales_order_line.quantity: 36 -> 48');
        $I->assertSame(4.0, (float) $row['quantity_entered'], 'sales_order_line.quantity_entered: NULL -> 4');

        $inventory = $this->inventoryFor($I, $product);
        $I->assertSame(48, $inventory->getSalesHoldQuantity(), 'product_inventory.sales_hold_quantity: 36 -> 48, not 4');
        $I->assertSame(952, $inventory->getAvailableQuantity(), '1000 on the shelf less a hold of 48');
    }

    /**
     * The freeze guard, biting on a real document through the real screen.
     *
     * Twelve is part of what that order MEANS once the line names `BOX-12`: editing the ratio to 20
     * would restate 3 boxes from 36 units to 60 without touching a row of the order. Refused at the
     * Units of Measure screen, and the refusal names the column that is holding it.
     */
    public function theUnitScreenRefusesToRestateAUnitAnOrderIsDenominatedIn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProductWithStock($I, 1000, 'FROZEN');
        $box = $this->makeAvailable($I, $product, 'BOX-12', '12');
        $boxId = (int) $box->getId();

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/order/create', $this->orderPost($I, $company, $product, '36'));
        $this->reExpress($I, $company, '3', $box);

        $I->amOnPage('/admin/product/units-of-measure?edit=' . $boxId);
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => $boxId,
            'code' => $box->getCode(),
            'name' => $box->getName(),
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '20',
            'rounding_precision' => '1',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();

        $reread = $em->find(UnitOfMeasure::class, $boxId);
        $I->assertInstanceOf(UnitOfMeasure::class, $reread);
        $I->assertSame('12.000000', $reread->getFactorToFamilyBase(), 'unit_of_measure.factor_to_family_base: 12 -> 12, the edit was refused');

        $I->assertSame(
            1,
            $I->grabService(UnitOfMeasureService::class)->referenceCounts($reread)['sales_order_line.unit_id'] ?? 0,
            'and the refusal names the document column holding it',
        );

        // And the order still means what it said.
        $row = $this->columnsOf($I, (int) $this->onlyLineOf($I, $company)->getId());
        $I->assertSame(36.0, (float) $row['quantity']);
        $I->assertSame(36, $this->inventoryFor($I, $product)->getSalesHoldQuantity());
    }

    // --- fixture -----------------------------------------------------------------------------------

    /**
     * Says the line in $entered of $unit, through the same call the form makes.
     *
     * Deliberately the entity's own method and not a hand-written UPDATE, because what is under test
     * is that the resolution runs and the inventory layer then does not see it.
     */
    private function reExpress(FunctionalTester $I, Company $company, string $entered, UnitOfMeasure $unit): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $line = $this->onlyLineOf($I, $company);
        $fresh = $em->find(UnitOfMeasure::class, (int) $unit->getId());

        $I->assertInstanceOf(UnitOfMeasure::class, $fresh);

        $line->setEnteredQuantity($entered, $fresh, $line->getProduct()?->getBaseUnit());
        $em->flush();
    }

    private function onlyLineOf(FunctionalTester $I, Company $company): SalesOrderLine
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $order = $em->getRepository(SalesOrder::class)->findOneBy(['company' => $company]);

        $I->assertInstanceOf(SalesOrder::class, $order, 'the order form save was accepted');
        $I->assertCount(1, $order->getLines());

        $line = $order->getLines()->first();
        $I->assertInstanceOf(SalesOrderLine::class, $line);

        return $line;
    }

    /** @return array<string, mixed> */
    private function columnsOf(FunctionalTester $I, int $lineId): array
    {
        $row = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT quantity, quantity_entered, unit_id FROM sales_order_line WHERE id = ?',
            [$lineId],
        );

        $I->assertIsArray($row);

        return $row;
    }

    /**
     * Defines a global unit and lists it as available on $product.
     *
     * Two statements, because #659 makes them two: the TERM and its ratio are instance-wide, and
     * which products may be expressed in it is per product. A unit that existed but was not listed
     * would be refused by the line save, which is the scoping this model exists for.
     */
    private function makeAvailable(FunctionalTester $I, ProductCore $product, string $code, string $factor): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $unit = $I->grabService(UnitOfMeasureService::class)
            ->add($code . '-' . strtoupper(substr(uniqid(), -5)), 'Box of ' . $factor, UnitOfMeasure::FAMILY_QUANTITY, $factor, '1');

        $fresh = $em->find(ProductCore::class, (int) $product->getId());
        $I->assertInstanceOf(ProductCore::class, $fresh);

        $I->grabService(ProductAvailableUnitService::class)->apply($fresh, [(int) $unit->getId()], null);
        $em->flush();

        return $unit;
    }

    /** The base unit every product in this file is counted in — `EA`, created once. */
    private function baseUnit(FunctionalTester $I): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $each = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'EA']);

        return $each instanceof UnitOfMeasure
            ? $each
            : $I->grabService(UnitOfMeasureService::class)->add('EA', 'Each', UnitOfMeasure::FAMILY_QUANTITY, '1', '1');
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-uom-lines-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Base Unit Co')
            ->setCode('BU-' . uniqid());
        $I->haveInRepository($company);

        $em = $I->grabService(EntityManagerInterface::class);
        $region = $em->getRepository(FulfillmentRegion::class)->findOneBy(['name' => self::REGION]);
        if (!$region instanceof FulfillmentRegion) {
            $region = (new FulfillmentRegion())->setName(self::REGION)->setStatus('Active');
            $I->haveInRepository($region);
            $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
            $em->flush();
        }

        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
        );

        return $company;
    }

    private function makeProductWithStock(FunctionalTester $I, int $quantity, string $tag): ProductCore
    {
        $product = (new ProductCore())
            ->setSku('UOM-' . $tag . '-' . uniqid())
            ->setName('Cased Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        // Declared, not typed: the base unit is what every quantity of this product is counted in,
        // and it is the second half of every ratio the line save resolves through.
        $product->setBaseUnit($this->baseUnit($I));
        $I->haveInRepository($product);

        $I->haveStockFor($product, $quantity, self::REGION);

        return $product;
    }

    private function inventoryFor(FunctionalTester $I, ProductCore $product): ProductInventory
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionName(self::REGION);
        $I->assertInstanceOf(Warehouse::class, $warehouse);

        $inventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $em->find(ProductCore::class, (int) $product->getId()),
            'warehouse' => $warehouse,
        ]);
        $I->assertInstanceOf(ProductInventory::class, $inventory);
        $em->refresh($inventory);

        return $inventory;
    }
}
