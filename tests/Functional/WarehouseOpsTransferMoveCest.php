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
use InventoryDepthBundle\Entity\WarehouseLocation;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WarehouseOpsBundle\Entity\TransferOrder;

/**
 * #787 step 3 — "Move selected" across warehouses. Destination warehouse differs from source, so
 * the app creates a real TransferOrder instead of calling StockMovementService directly (#787 §5).
 * The atomicity guarantee behind "send and receive now" (one DB transaction covering create,
 * dispatch and receive) is proven separately, at the service level, by
 * modules/WarehouseOpsBundle/tests/Transfer/ConfirmTransferAtomicityTest.php — this file drives
 * the real screens and asserts the document/ledger outcome, the same discipline as every other
 * Cest in this suite.
 */
final class WarehouseOpsTransferMoveCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('warehouse-transfer-move-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function warehouse(FunctionalTester $I, string $suffix): Warehouse
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $region = (new FulfillmentRegion())->setName('Transfer Move Region ' . $suffix);
        $em->persist($region);

        return $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = (new ProductCore())->setSku($sku)->setName('Transfer Move ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
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

    private function previewToken(FunctionalTester $I, int $detailId, string $qty, string $destination, string $travelMode): string
    {
        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/preview', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => $qty],
            'destination' => $destination,
            'travel_mode' => $travelMode,
        ]);
        $I->seeResponseCodeIsSuccessful();

        return $I->grabAttributeFrom('input[name="op_id"]', 'value');
    }

    public function sendAndReceiveNowCreatesAReceivedTransferAndShelvesTheStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $source = $this->warehouse($I, 'SRC' . $suffix);
        $destination = $this->warehouse($I, 'DST' . $suffix);
        $product = $this->product($I, 'XFER-' . $suffix);
        $fromBin = $this->bin($I, $source, 'XFROM-' . $suffix);
        $toBin = $this->bin($I, $destination, 'XTO-' . $suffix);
        $detail = $this->detail($I, $product, $source, $fromBin, '15');
        $em->flush();
        $detailId = $detail->getId();

        $opId = $this->previewToken($I, $detailId, '15', 'bin:' . $toBin->getId(), 'send_and_receive');
        $I->see('recorded as a warehouse transfer');
        $I->see('Send and receive now');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/confirm', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '15'],
            'destination' => 'bin:' . $toBin->getId(),
            'travel_mode' => 'send_and_receive',
            'op_id' => $opId,
        ]);

        $em->clear();

        $transfer = $em->getRepository(TransferOrder::class)->findOneBy(['fromWarehouse' => $source, 'toWarehouse' => $destination]);
        $I->assertNotNull($transfer, 'a transfer order must exist for a cross-warehouse move');
        $I->assertSame(TransferOrder::STATUS_RECEIVED, $transfer->getStatus());
        $I->assertCount(1, $transfer->getLines());

        $fromRow = $em->find(InventoryDetail::class, $detailId);
        $I->assertSame('0.0000', $fromRow->getQuantity(), 'the whole quantity moved, so the source row is empty');

        $shelfRow = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $product,
            'warehouse' => $destination,
            'location' => $toBin,
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $I->assertNotNull($shelfRow, 'the stock must have landed on the destination bin as available');
        $I->assertSame('15.0000', $shelfRow->getQuantity());
    }

    public function trackInTransitDispatchesButDoesNotShelveTheStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $source = $this->warehouse($I, 'TSRC' . $suffix);
        $destination = $this->warehouse($I, 'TDST' . $suffix);
        $product = $this->product($I, 'XFERT-' . $suffix);
        $fromBin = $this->bin($I, $source, 'TXFROM-' . $suffix);
        $detail = $this->detail($I, $product, $source, $fromBin, '9');
        $em->flush();
        $detailId = $detail->getId();

        $opId = $this->previewToken($I, $detailId, '9', 'warehouse:' . $destination->getId(), 'track_in_transit');
        $I->see('Track in transit');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/confirm', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '9'],
            'destination' => 'warehouse:' . $destination->getId(),
            'travel_mode' => 'track_in_transit',
            'op_id' => $opId,
        ]);

        $em->clear();

        $transfer = $em->getRepository(TransferOrder::class)->findOneBy(['fromWarehouse' => $source, 'toWarehouse' => $destination]);
        $I->assertNotNull($transfer);
        $I->assertSame(TransferOrder::STATUS_DISPATCHED, $transfer->getStatus());

        $shelved = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $product,
            'warehouse' => $destination,
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $I->assertNull($shelved, 'track-in-transit must not put anything on a destination shelf yet');

        $inTransit = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $product,
            'warehouse' => $source,
            'status' => InventoryDetail::STATUS_IN_TRANSIT,
        ]);
        $I->assertNotNull($inTransit);
        $I->assertSame('9.0000', $inTransit->getQuantity());
    }

    public function aFractionalQuantityIsRefusedForATransfer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $suffix = strtoupper(substr(uniqid(), -6));
        $em = $I->grabService(EntityManagerInterface::class);

        $source = $this->warehouse($I, 'FSRC' . $suffix);
        $destination = $this->warehouse($I, 'FDST' . $suffix);
        $product = $this->product($I, 'XFERF-' . $suffix);
        $fromBin = $this->bin($I, $source, 'FXFROM-' . $suffix);
        $detail = $this->detail($I, $product, $source, $fromBin, '5.5');
        $em->flush();
        $detailId = $detail->getId();

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/move/preview', [
            '_token' => $I->csrfToken(),
            'qty' => [(string) $detailId => '2.5'],
            'destination' => 'warehouse:' . $destination->getId(),
            'travel_mode' => 'send_and_receive',
        ]);
        $I->see('a transfer between warehouses moves whole units only');

        $em->clear();
        $I->assertSame('5.5000', $em->find(InventoryDetail::class, $detailId)->getQuantity());
        $I->assertNull($em->getRepository(TransferOrder::class)->findOneBy(['fromWarehouse' => $source, 'toWarehouse' => $destination]));
    }
}
