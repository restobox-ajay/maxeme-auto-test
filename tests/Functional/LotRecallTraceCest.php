<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Lot/serial recall traceability (#725) — search by batch, find where it is now and who was sold
 * units of it. The sharpest, most food-safety-relevant finding in the wholesale-inventory-core
 * parity audit.
 */
final class LotRecallTraceCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('lot-recall-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation, lot: InventoryLot} */
    private function seed(FunctionalTester $I, string $code = 'RECALL-BATCH-1'): array
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName('Recall Trace Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('RECALL-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Recall Trace Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(0));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('R-01')->setSortKey(10);
        $em->persist($bin);

        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry(new \DateTimeImmutable('2027-06-30'));
        $em->persist($lot);
        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'recall-trace@example.test');

        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'cest-recall-' . uniqid(), 'Put away into the bin')
                ->receive($product, new DetailKey($warehouse, $bin, $lot, null, InventoryDetail::STATUS_AVAILABLE), 40)
        );

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'lot' => $lot];
    }

    private function issuedInvoiceFor(FunctionalTester $I, ProductCore $product, InventoryLot $lot, int $quantity, string $companyName): Invoice
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $company = (new Company())->setName($companyName)->setCode('RC-' . uniqid());
        $em->persist($company);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('RC-INV-' . uniqid())
            ->setDocumentDate('2026-09-10')
            ->setInvoiceDate('2026-09-10')
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setQuantity((string) $quantity)
                ->setPrice('100.00')
                ->setSubtotal('100.00')
                ->setLotId($lot->getId()),
        );
        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return $invoice;
    }

    public function searchFindsALotByPartialCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 'FIND-ME-42');

        $I->amOnPage('/admin/bundles/inventory-depth/lots/trace?q=FIND-ME');
        $I->seeResponseCodeIsSuccessful();
        $I->see('FIND-ME-42');
        $I->seeElement('a[href*="/admin/bundles/inventory-depth/lots/trace/' . $seed['lot']->getId() . '"]');
    }

    public function theTraceScreenShowsWhereTheLotCurrentlyIs(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage('/admin/bundles/inventory-depth/lots/trace/' . $seed['lot']->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($seed['warehouse']->getName());
        $I->see('R-01');
        $I->see('40');
    }

    public function theTraceScreenShowsWhoItWasSoldTo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $this->issuedInvoiceFor($I, $seed['product'], $seed['lot'], 5, 'Recalled Widget Buyer Co');

        $I->amOnPage('/admin/bundles/inventory-depth/lots/trace/' . $seed['lot']->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Recalled Widget Buyer Co');
        $I->see('5', 'tbody');
    }

    /** A Draft invoice is "written but not issued... counting for nothing" (Invoice::isDraft()) — never a sale. */
    public function aDraftInvoiceDoesNotAppearInTheSoldToList(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $company = (new Company())->setName('Draft Only Co')->setCode('RC-DRAFT-' . uniqid());
        $em->persist($company);
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('RC-DRAFT-' . uniqid())
            ->setDocumentDate('2026-09-10')
            ->setSubtotal('50.00')->setTax('0.00')->setTotal('50.00');
        $invoice->addLine(
            (new InvoiceLine())->setProduct($seed['product'])->setName('x')->setQuantity('2')->setPrice('25.00')->setSubtotal('50.00')->setLotId($seed['lot']->getId()),
        );
        $em->persist($invoice);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/lots/trace/' . $seed['lot']->getId());
        $I->dontSee('Draft Only Co');
    }

    public function declaringARecallFromTheTraceScreenPersistsTheStatus(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage('/admin/bundles/inventory-depth/lots/trace/' . $seed['lot']->getId());
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/lots/trace/' . $seed['lot']->getId() . '/status', [
            '_token' => $token,
            'status' => 'recalled',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(InventoryLot::class, ['id' => $seed['lot']->getId(), 'status' => 'recalled']);
    }

    /** The core gate: a recalled lot stops being offered on the Order/Invoice line lot picker. */
    public function aRecalledLotStopsBeingOfferedOnTheOrderLineLotPicker(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage('/admin/bundles/inventory-depth/lots/available?product_id=' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        $before = json_decode((string) $I->grabPageSource(), true);
        $I->assertIsArray($before);
        $I->assertSame([$seed['lot']->getId()], array_column($before, 'id'), 'the lot must be offered before it is recalled');

        $seed['lot']->setStatus('recalled');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/lots/available?product_id=' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        $body = json_decode((string) $I->grabPageSource(), true);
        $I->assertIsArray($body);
        $I->assertSame([], $body, 'a recalled lot must not be offered on the picker');
    }

    /** Defense in depth: even a lot named directly (bypassing the now-empty picker) measures as 0 available. */
    public function aRecalledLotMeasuresAsZeroAvailableEvenWhenNamedDirectly(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $resolver = $I->grabService(\App\Service\Inventory\LotAvailabilityResolver::class);
        $em = $I->grabService(EntityManagerInterface::class);
        $I->assertSame('40.0000', $resolver->availableForLot((int) $seed['lot']->getId(), $seed['product']));

        $seed['lot']->setStatus('recalled');
        $em->flush();

        $I->assertSame('0.0000', $resolver->availableForLot((int) $seed['lot']->getId(), $seed['product']));
    }
}
