<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #787 step 2 — "Move selected" for the same-warehouse (bin move) case: no document, no bucket
 * change, both sides `available`. The cross-warehouse transfer case is step 3, covered in
 * WarehouseOpsTransferMoveCest.
 *
 * The destination is one field, `destination=bin:<id>` (a bin, in whichever warehouse it belongs
 * to) or `destination=warehouse:<id>` ("put away later", transfer only) — see MoveController's own
 * docblock for why one control replaces what used to be a separate warehouse/bin pair.
 */
final class WarehouseOpsMoveCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('warehouse-move-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function warehouse(FunctionalTester $I, string $suffix): Warehouse
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $region = (new FulfillmentRegion())->setName('Move Region ' . $suffix);
        $em->persist($region);

        return $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = (new ProductCore())->setSku($sku)->setName('Move ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);

        return $product;
    }

    private function bin(FunctionalTester $I, Warehouse $warehouse, string $code): WarehouseLocation
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode($code)->setStatus('Active');
        $em->persist($bin);

        return $bin;
    }

    private function detail(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, WarehouseLocation $bin, string $quantity): InventoryDetail
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $detail = (new InventoryDetail())->setProduct($product)->setWarehouse($warehouse)->setLocation($bin)->setStatus(InventoryDetail::STATUS_AVAILABLE);
        $detail->setQuantity($quantity)->touch();
        $em->persist($detail);

        return $detail;
    }

    private function fullFlowToConfirmToken(FunctionalTester $I, int $detailId, string $qty, int $toBinId): string
    {
        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/preview', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => $qty],
            'destination' => 'bin:' . $toBinId,
        ]);
        $I->seeResponseCodeIsSuccessful();

        return $I->grabAttributeFrom('input[name="op_id"]', 'value');
    }

    // ----------------------------------------------------------------- the happy path

    public function aBinMoveWritesNoDocumentAndChangesOnlyTheBins(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'H' . $suffix);
        $product = $this->product($I, 'MOVEH-' . $suffix);
        $fromBin = $this->bin($I, $warehouse, 'FROM-' . $suffix);
        $toBin = $this->bin($I, $warehouse, 'TO-' . $suffix);
        $detail = $this->detail($I, $product, $warehouse, $fromBin, '60');
        $em->flush();
        $detailId = $detail->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/stock?filters%5Bproduct%5D=' . $product->getSku());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="checkbox"][name="rows[]"][value="' . $detailId . '"]');

        $opId = $this->fullFlowToConfirmToken($I, $detailId, '10', $toBin->getId());
        $I->see('Destination');
        $I->see($toBin->getCode());

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/confirm', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '10'],
            'destination' => 'bin:' . $toBin->getId(),
            'op_id' => $opId,
        ]);

        $em->clear();
        $fromRow = $em->find(InventoryDetail::class, $detailId);
        $I->assertSame('50.0000', $fromRow->getQuantity());

        $toRow = $em->getRepository(InventoryDetail::class)->findOneBy(['location' => $toBin, 'product' => $product]);
        $I->assertNotNull($toRow);
        $I->assertSame('10.0000', $toRow->getQuantity());

        // No document — a bin move is not a transfer.
        $I->dontSeeInRepository(\WarehouseOpsBundle\Entity\TransferOrder::class, []);
    }

    /** Same op_id twice moves stock once — a resubmitted confirm, not a second move. */
    public function aDoubleSubmitWithTheSameOpIdMovesOnce(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'D' . $suffix);
        $product = $this->product($I, 'MOVED-' . $suffix);
        $fromBin = $this->bin($I, $warehouse, 'DFROM-' . $suffix);
        $toBin = $this->bin($I, $warehouse, 'DTO-' . $suffix);
        $detail = $this->detail($I, $product, $warehouse, $fromBin, '20');
        $em->flush();
        $detailId = $detail->getId();

        $opId = $this->fullFlowToConfirmToken($I, $detailId, '5', $toBin->getId());

        $confirm = static fn () => $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/confirm', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '5'],
            'destination' => 'bin:' . $toBin->getId(),
            'op_id' => $opId,
        ]);
        $confirm();
        $confirm();

        $em->clear();
        $toRow = $em->getRepository(InventoryDetail::class)->findOneBy(['location' => $toBin, 'product' => $product]);
        $I->assertSame('5.0000', $toRow->getQuantity(), 'a repeated confirm with the same op_id must not move the stock twice');
    }

    /**
     * Guards preview drift: the before/after figures the preview screen shows must be exactly
     * what confirming actually produces, not merely close — checked against a destination bin
     * that already holds stock, so "before" is a real, non-zero number to get wrong.
     */
    public function thePreviewNumbersEqualThePostConfirmNumbers(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'P' . $suffix);
        $product = $this->product($I, 'MOVEP-' . $suffix);
        $fromBin = $this->bin($I, $warehouse, 'PFROM-' . $suffix);
        $toBin = $this->bin($I, $warehouse, 'PTO-' . $suffix);
        $detail = $this->detail($I, $product, $warehouse, $fromBin, '10');
        // The destination already holds stock, so "before" is a real number, not always zero.
        $this->detail($I, $product, $warehouse, $toBin, '25');
        $em->flush();
        $detailId = $detail->getId();

        $opId = $this->fullFlowToConfirmToken($I, $detailId, '10', $toBin->getId());
        $previewText = $I->grabTextFrom('#move-destination-summary');
        $I->assertMatchesRegularExpression('/25\s*→\s*35/u', $previewText, 'preview must state the real before/after, not a placeholder');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/confirm', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '10'],
            'destination' => 'bin:' . $toBin->getId(),
            'op_id' => $opId,
        ]);

        $em->clear();
        $destinationTotal = $em->createQueryBuilder()
            ->select('SUM(d.quantity)')
            ->from(InventoryDetail::class, 'd')
            ->andWhere('d.location = :bin')->setParameter('bin', $toBin)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->getQuery()->getSingleScalarResult();
        $I->assertSame('35.0000', \App\Service\QuantityScale::canonical((string) $destinationTotal), 'the actual post-confirm total must equal the "after" the preview promised');
    }

    // ----------------------------------------------------------------- refusals, nothing written

    public function aQuantityOverTheRowIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'O' . $suffix);
        $product = $this->product($I, 'MOVEO-' . $suffix);
        $fromBin = $this->bin($I, $warehouse, 'OFROM-' . $suffix);
        $toBin = $this->bin($I, $warehouse, 'OTO-' . $suffix);
        $detail = $this->detail($I, $product, $warehouse, $fromBin, '5');
        $em->flush();
        $detailId = $detail->getId();

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/preview', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '999'],
            'destination' => 'bin:' . $toBin->getId(),
        ]);
        $I->see(sprintf('Only 5.0000 of %s is available in %s — asked to move 999.', $product->getSku(), $fromBin->getCode()));

        $em->clear();
        $I->assertSame('5.0000', $em->find(InventoryDetail::class, $detailId)->getQuantity());
    }

    public function mixedSourceWarehousesAreRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouseA = $this->warehouse($I, 'MA' . $suffix);
        $warehouseB = $this->warehouse($I, 'MB' . $suffix);
        $product = $this->product($I, 'MOVEM-' . $suffix);
        $binA = $this->bin($I, $warehouseA, 'MFROM-A-' . $suffix);
        $binB = $this->bin($I, $warehouseB, 'MFROM-B-' . $suffix);
        $detailA = $this->detail($I, $product, $warehouseA, $binA, '5');
        $detailB = $this->detail($I, $product, $warehouseB, $binB, '5');
        $em->flush();

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move', [
            '_token' => $I->csrfToken(),
            'rows' => [(string) $detailA->getId(), (string) $detailB->getId()],
        ]);
        $I->see('Selected rows come from more than one warehouse. Move one warehouse at a time.');

        $em->clear();
        $I->assertSame('5.0000', $em->find(InventoryDetail::class, $detailA->getId())->getQuantity());
        $I->assertSame('5.0000', $em->find(InventoryDetail::class, $detailB->getId())->getQuantity());
    }

    public function theSameBinAsSourceIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'S' . $suffix);
        $product = $this->product($I, 'MOVES-' . $suffix);
        $bin = $this->bin($I, $warehouse, 'SBIN-' . $suffix);
        $detail = $this->detail($I, $product, $warehouse, $bin, '5');
        $em->flush();

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/preview', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detail->getId() => '5'],
            'destination' => 'bin:' . $bin->getId(),
        ]);
        $I->see(sprintf('%s is already in %s.', $product->getSku(), $bin->getCode()));

        $em->clear();
        $I->assertSame('5.0000', $em->find(InventoryDetail::class, $detail->getId())->getQuantity());
    }

    public function rowNoLongerAvailableIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'N' . $suffix);
        $product = $this->product($I, 'MOVEN-' . $suffix);
        $bin = $this->bin($I, $warehouse, 'NBIN-' . $suffix);
        $toBin = $this->bin($I, $warehouse, 'NTO-' . $suffix);
        $detail = (new InventoryDetail())->setProduct($product)->setWarehouse($warehouse)->setLocation($bin)->setStatus(InventoryDetail::STATUS_QUARANTINE);
        $detail->setQuantity('5')->touch();
        $em->persist($detail);
        $em->flush();

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/preview', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detail->getId() => '5'],
            'destination' => 'bin:' . $toBin->getId(),
        ]);
        $I->see('One of the selected rows is no longer available. Nothing was moved — refresh the stock table and try again.');
    }

    public function forgedCsrfOnConfirmMovesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $warehouse = $this->warehouse($I, 'C' . $suffix);
        $product = $this->product($I, 'MOVEC-' . $suffix);
        $fromBin = $this->bin($I, $warehouse, 'CFROM-' . $suffix);
        $toBin = $this->bin($I, $warehouse, 'CTO-' . $suffix);
        $detail = $this->detail($I, $product, $warehouse, $fromBin, '8');
        $em->flush();
        $detailId = $detail->getId();

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/move/confirm', [
            '_token' => 'not-a-valid-token',
            'qty' => [(string) $detailId => '8'],
            'destination' => 'bin:' . $toBin->getId(),
            'op_id' => bin2hex(random_bytes(16)),
        ]);
        $I->seeResponseCodeIs(403);

        $em->clear();
        $I->assertSame('8.0000', $em->find(InventoryDetail::class, $detailId)->getQuantity());
    }
}
