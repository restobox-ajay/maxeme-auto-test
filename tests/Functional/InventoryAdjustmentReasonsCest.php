<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The reason-first adjustment screen, driven through real POSTs (#585).
 *
 * The unit tests assert that the mapping cannot drift and that the reversal bound holds. This is the
 * half they cannot reach: that each of the eight reasons, submitted the way a browser submits it,
 * produces the movement it claims to — and that the fields a given reason and tracking policy ask
 * for are the fields the controller actually reads.
 *
 * That last point is the failure mode worth a functional test all by itself. A form that asks for a
 * serial the controller ignores, or a controller that wants a lot the form never offered, both fail
 * as "why did nothing happen", and neither shows up in a test of the mapping.
 */
final class InventoryAdjustmentReasonsCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('adjust-reasons-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // The shipped reference rows used to be created by RENDERING the screen below. They are
        // now created once, on LoginSuccessEvent, and amLoggedInAs() does not dispatch that event —
        // it installs a token and never runs the authenticator. So this stands in for the real
        // login's side effect. ReferenceDataSeedingCest posts the actual login form.
        $I->haveSeededReferenceData();
    }

    /**
     * A dimensional product with $quantity units on the shelf, and no tracking policy at all —
     * which is what every product has until somebody opts one in, and therefore the case that has
     * to work with the fewest fields on screen.
     *
     * @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation}
     */
    private function seed(FunctionalTester $I, int $quantity = 100, ?TrackingPolicy $policy = null): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Reason Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('REASON-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Reason Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        if ($policy instanceof TrackingPolicy) {
            $product->setTrackingPolicy($policy);
        }

        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('A-12')->setSortKey(10);
        $em->persist($bin);
        $em->flush();

        // Through the real switcher, so the opening balance is written the way the app writes it.
        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'cest@example.test');

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin];
    }

    /**
     * The same shape as seed(), but left on `simple` inventory — no InventoryModeSwitcher call —
     * which is the case StockMovementService.php's 2026-09-18 fix is actually about: WHERE stock is
     * is not a dimensional-only question, so a simple product's own adjustment should show up on
     * `Where It Is` too, bin included.
     *
     * @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation}
     */
    private function seedSimple(FunctionalTester $I, int $quantity = 100): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Simple Reason Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('REASON-SIMPLE-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Reason Product (Simple)')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('S-01')->setSortKey(10);
        $em->persist($bin);
        $em->flush();

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin];
    }

    /**
     * Drives the real screens end to end: submit Stock Found for a `simple` product with a bin
     * named on the form, then load the product's own stock page and read `Where It Is` back — not
     * the database, the rendered table, so a controller that resolved the row but never queried it
     * back for this screen would still fail here.
     */
    public function stockFoundOnASimpleProductShowsWhereItIsOnTheRealScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedSimple($I, 100);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'location_id' => (string) $seed['bin']->getId(),
            'quantity' => '7',
        ]);

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Nothing matches.');
        $I->see($seed['bin']->getCode());
        $I->see($seed['warehouse']->getName());

        $I->assertSame(7, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE));
    }

    private function policy(FunctionalTester $I, string $mode, bool $trackIn, bool $trackOut, bool $requiresExpiry = false): TrackingPolicy
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $policy = (new TrackingPolicy())
            ->setName(ucfirst($mode) . ' ' . ($trackIn ? 'in' : '') . ($trackOut ? 'out' : '') . ' ' . uniqid())
            ->setMode($mode)
            ->setTrackIn($trackIn)
            ->setTrackOut($trackOut)
            ->setRequiresExpiry($requiresExpiry)
            ->setSentinelIn(TrackingPolicy::DEFAULT_SENTINEL);

        $em->persist($policy);
        $em->flush();

        return $policy;
    }

    /** Opens the form for one product and reason and hands back its CSRF token. */
    private function openForm(FunctionalTester $I, ProductCore $product, string $reason): string
    {
        $I->amOnPage(sprintf('/admin/bundles/inventory-depth/adjust?product=%d&reason=%s', $product->getId(), $reason));
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    private function detailTotal(FunctionalTester $I, ProductCore $product, string $status): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        return (int) $em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$product->getId(), $status],
        );
    }

    private function coreRow(FunctionalTester $I, ProductCore $product, Warehouse $warehouse): ProductInventory
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $row = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);
        $I->assertInstanceOf(ProductInventory::class, $row);

        return $row;
    }

    /**
     * The screen still asks for a product first, then a reason — and until a reason is chosen there
     * is no movement form at all.
     */
    public function theScreenAsksWhatHappenedBeforeItAsksAnythingElse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage('/admin/bundles/inventory-depth/adjust');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('input[name="reason"]');

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        // All eight reasons, offered as a controlled list. The two that put stock back come first.
        foreach (['Stock found', 'Reverse a write-off', 'Damaged', 'Spoiled / expired', 'Scrapped', 'Lost / shrinkage', 'Put on hold', 'Release from hold'] as $label) {
            $I->see($label);
        }
        // And no movement form yet: the reason is what shapes it.
        $I->dontSeeElement('input[name="quantity"]');

        $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_DAMAGED);
        $I->seeElement('input[name="quantity"]');
    }

    /**
     * The movement editor is gone: no status dropdowns, no type dropdown, no from/to checkboxes, and
     * no second form.
     *
     * Asserted as absence on the rendered page AND as a dead route, because those are two different
     * claims. Taking a field off the form is a courtesy; removing the action is the rule, and
     * `POST /withdraw` was a route a hand-built request could reach whatever the page showed.
     */
    public function theOldMovementEditorIsGoneFromTheFormAndFromTheRouter(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_DAMAGED);

        $I->dontSeeElement('select[name="to_status"]');
        $I->dontSeeElement('select[name="from_status"]');
        $I->dontSeeElement('select[name="type"]');
        $I->dontSeeElement('input[name="has_from"]');
        $I->dontSeeElement('input[name="has_to"]');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/withdraw', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '5',
            'to_status' => 'damaged',
        ]);
        $I->seeResponseCodeIs(404);

        $I->assertSame(100, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'and the dead route moved nothing');
    }

    /**
     * The five reasons that take stock off the shelf, each writing the status it names — including
     * `expired`, which the old screen could not record at all.
     *
     * Driven on an untracked product, so nothing is asked beyond the warehouse and the quantity and
     * AutomaticSourcePicker chooses the rows. That is the default policy and therefore the common
     * case.
     */
    public function eachOutboundReasonWritesTheStatusItNames(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 100);

        foreach ([
            InventoryAdjustmentReason::CODE_DAMAGED => InventoryDetail::STATUS_DAMAGED,
            InventoryAdjustmentReason::CODE_SPOILED => InventoryDetail::STATUS_EXPIRED,
            InventoryAdjustmentReason::CODE_SCRAPPED => InventoryDetail::STATUS_SCRAPPED,
            InventoryAdjustmentReason::CODE_LOST => InventoryDetail::STATUS_LOST,
            InventoryAdjustmentReason::CODE_HOLD => InventoryDetail::STATUS_QUARANTINE,
        ] as $code => $status) {
            $token = $this->openForm($I, $seed['product'], $code);

            // No status is submitted. That is the whole point — the reason carries it.
            $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
                '_token' => $token,
                'product_id' => (string) $seed['product']->getId(),
                'reason' => $code,
                'warehouse_id' => (string) $seed['warehouse']->getId(),
                'quantity' => '4',
                'note' => 'Recorded by ' . $code,
            ]);

            $I->assertSame(4, $this->detailTotal($I, $seed['product'], $status), sprintf('"%s" must write %s', $code, $status));
        }

        $I->assertSame(80, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'five lots of four came off the shelf');

        $row = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame('16.0000', $row->getWriteOffQuantity(), 'damaged, expired, scrapped and lost are one bucket');
        $I->assertSame('4.0000', $row->getQuarantineQuantity(), 'and a hold is a different one');
    }

    /**
     * Spoilage, specifically. It is the reason #585 was raised over: `expired` was not on the old
     * withdraw list, so anybody finding spoiled stock before the nightly lot sweep had to mis-file
     * it as `scrapped` — and the two are not the same fact about a business.
     */
    public function spoiledStockIsRecordedAsExpiredAndNotAsScrapped(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 30);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_SPOILED);

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_SPOILED,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '6',
        ]);

        $I->assertSame(6, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_EXPIRED));
        $I->assertSame(0, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_SCRAPPED), 'and it is not filed as something it is not');
    }

    /** Holding stock back and letting it go again — one reason each way, and the bucket follows. */
    public function stockCanBePutOnHoldAndReleasedAgain(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_HOLD);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_HOLD,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '10',
        ]);

        $I->assertSame('10.0000', $this->coreRow($I, $seed['product'], $seed['warehouse'])->getQuarantineQuantity());

        // Releasing names the held rows, because a hold is always against particular goods — there
        // is no automatic picker for held stock and there should not be one.
        $I->amOnPage(sprintf('/admin/bundles/inventory-depth/adjust?product=%d&reason=%s', $seed['product']->getId(), InventoryAdjustmentReason::CODE_RELEASE_HOLD));
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="source_detail_id"]');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $heldId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM inventory_detail WHERE product_id = ? AND status = ? AND quantity > 0',
            [$seed['product']->getId(), InventoryDetail::STATUS_QUARANTINE],
        );

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_RELEASE_HOLD,
            'source_detail_id' => (string) $heldId,
            'quantity' => '10',
        ]);

        $I->assertSame('0.0000', $this->coreRow($I, $seed['product'], $seed['warehouse'])->getQuarantineQuantity(), 'the hold is off');
        $I->assertSame(40, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'and it is sellable again');
    }

    /**
     * Found stock and a reversed write-off are different facts, and the screen now records both —
     * which is the ambiguity #585 opens with.
     *
     * Recording previously-written-off stock as "found" invents units while leaving the loss on the
     * books: availability comes out right by accident and both underlying figures are wrong. So the
     * assertions here are about the two buckets, not about the total.
     */
    public function foundStockAndAReversedWriteOffAreDifferentFacts(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 50);

        // Lose twelve.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_LOST);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_LOST,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '12',
        ]);
        $I->assertSame('12.0000', $this->coreRow($I, $seed['product'], $seed['warehouse'])->getWriteOffQuantity());

        // The reversal screen lists the entry with what is left on it, rather than offering an empty
        // quantity box against nothing.
        $I->amOnPage(sprintf('/admin/bundles/inventory-depth/adjust?product=%d&reason=%s', $seed['product']->getId(), InventoryAdjustmentReason::CODE_REVERSE_WRITE_OFF));
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="original_movement_id"]');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
        $originalId = (int) $I->grabAttributeFrom('input[name="original_movement_id"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_REVERSE_WRITE_OFF,
            'original_movement_id' => (string) $originalId,
            'quantity' => '12',
        ]);

        $row = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame('0.0000', $row->getWriteOffQuantity(), 'the loss comes off the books, which is what a reversal is for');
        $I->assertSame(50, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE));

        // Whereas found stock enters the ledger and raises `received`. Different reason, different
        // bucket, same physical trigger.
        $receivedBefore = $row->getReceivedQuantity();
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'location_id' => (string) $seed['bin']->getId(),
            'quantity' => '3',
        ]);

        $row = $this->coreRow($I, $seed['product'], $seed['warehouse']);
        $I->assertSame(bcadd($receivedBefore, '3', 4), $row->getReceivedQuantity(), 'found stock is stock this app now knows about and the client\'s system does not');
        $I->assertSame('0.0000', $row->getWriteOffQuantity(), 'and it invents nothing against the write-off bucket');
    }

    /**
     * Over HTTP: a reversal cannot claim more than the entry still has, and the refusal leaves
     * everything exactly where it was.
     */
    public function aReversalOverHttpCannotExceedWhatIsLeftOnTheEntry(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 50);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_DAMAGED);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_DAMAGED,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '12',
        ]);

        $I->amOnPage(sprintf('/admin/bundles/inventory-depth/adjust?product=%d&reason=%s', $seed['product']->getId(), InventoryAdjustmentReason::CODE_REVERSE_WRITE_OFF));
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
        $originalId = (int) $I->grabAttributeFrom('input[name="original_movement_id"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_REVERSE_WRITE_OFF,
            'original_movement_id' => (string) $originalId,
            'quantity' => '500',
        ]);

        $I->assertSame(12, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_DAMAGED), 'the refused reversal put nothing back');
        $I->assertSame(38, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE));
    }

    /**
     * `track_out = false` means the operator is not asked which rows — AutomaticSourcePicker chooses,
     * earliest expiry first.
     *
     * Two batches are seeded with different dates so the choice is observable rather than assumed:
     * if anything picked the other way, or picked the wrong row, the assertion below says which.
     */
    public function anUntrackedOutboundProductIsNotAskedForALotAndThePickerChoosesTheEarliestExpiry(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        // Lot mode, captured INBOUND only — Dynamics' "Active but not Physical", and the common case.
        $seed = $this->seed($I, 0, $this->policy($I, TrackingPolicy::MODE_LOT, trackIn: true, trackOut: false));

        $em = $I->grabService('doctrine.orm.entity_manager');
        $soon = (new InventoryLot())->setProduct($seed['product'])->setCode('SOON')->setExpiry(new \DateTimeImmutable('2027-01-31'));
        $later = (new InventoryLot())->setProduct($seed['product'])->setCode('LATER')->setExpiry(new \DateTimeImmutable('2029-12-31'));
        $em->persist($soon);
        $em->persist($later);
        $em->flush();

        foreach ([$soon, $later] as $lot) {
            $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
            $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
                '_token' => $token,
                'product_id' => (string) $seed['product']->getId(),
                'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
                'warehouse_id' => (string) $seed['warehouse']->getId(),
                'location_id' => (string) $seed['bin']->getId(),
                'lot_id' => (string) $lot->getId(),
                'quantity' => '10',
            ]);
        }

        $I->assertSame(20, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'ten of each batch is on the shelf');

        // Outbound the policy tracks nothing, so the form asks for a warehouse and nothing else.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_DAMAGED);
        $I->dontSeeElement('select[name="source_detail_id"]');
        $I->see('earliest expiry first');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_DAMAGED,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '10',
        ]);

        $em->clear();
        $damagedFromSoon = (int) $em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ? AND lot_id = ?',
            [$seed['product']->getId(), InventoryDetail::STATUS_DAMAGED, $soon->getId()],
        );
        $I->assertSame(10, $damagedFromSoon, 'the picker took the batch that expires first, without being asked');
    }

    /**
     * `track_out = true` means the operator DOES name the rows, and the form offers them.
     *
     * The mirror of the test above, and the reason the two flags are independent: the same product
     * class can require a batch on the way in and not on the way out, or the other way round.
     */
    public function anOutboundTrackedProductIsAskedWhichRowsTheStockComesOff(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 25, $this->policy($I, TrackingPolicy::MODE_LOT, trackIn: false, trackOut: true));

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_SCRAPPED);
        $I->seeElement('select[name="source_detail_id"]');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $sourceId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM inventory_detail WHERE product_id = ? AND status = ? AND quantity > 0',
            [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE],
        );

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_SCRAPPED,
            'source_detail_id' => (string) $sourceId,
            'quantity' => '5',
        ]);

        $I->assertSame(5, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_SCRAPPED));

        // And a row that is not this product's available stock is refused, which is what a POST body
        // can always try and a dropdown can never stop.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_SCRAPPED);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_SCRAPPED,
            'source_detail_id' => '999999',
            'quantity' => '5',
        ]);

        $I->assertSame(5, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_SCRAPPED), 'the made-up row scrapped nothing');
    }

    /**
     * `track_in = true` means the batch is asked for on the way in, and it is PICK-OR-CREATE: a found
     * box carries a code that may never have been in this system.
     *
     * The expiry rule is checked in the same test because it only exists on creation — an existing
     * batch already has whatever date it has, and it is the NEW one that a policy with
     * `requires_expiry` is talking about.
     */
    public function aLotTrackedProductCanHaveANewBatchTypedInAndMustDateItWhenThePolicySaysSo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 0, $this->policy($I, TrackingPolicy::MODE_LOT, trackIn: true, trackOut: false, requiresExpiry: true));

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->seeElement('input[name="new_lot_code"]');
        $I->seeElement('input[name="new_lot_expiry"]');

        // Undated, against a policy that requires a date: refused, and nothing is written.
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'new_lot_code' => 'BATCH-77',
            'quantity' => '8',
        ]);
        $I->assertSame(0, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'an undated batch is not a batch under this policy');

        // Dated: the batch is created and the stock lands on it.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'new_lot_code' => 'BATCH-77',
            'new_lot_expiry' => '2028-03-31',
            'quantity' => '8',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $lot = $em->getRepository(InventoryLot::class)->findOneBy(['product' => $seed['product']->getId(), 'code' => 'BATCH-77']);
        $I->assertInstanceOf(InventoryLot::class, $lot);
        $I->assertSame('2028-03-31', $lot->getExpiry()?->format('Y-m-d'));
        $I->assertSame(8, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE));
    }

    /**
     * Expiry is its own Yes/No, independent of lot, serial and direction (#795): a `none`-mode
     * policy — no batch, no serial — can still require a date, and this is the row-level box that
     * asks for it once there is no lot for the date to ride on instead.
     */
    public function anUntrackedProductRequiringExpiryAsksForItOnTheRowAndDatesIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 0, $this->policy($I, TrackingPolicy::MODE_NONE, trackIn: false, trackOut: false, requiresExpiry: true));

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->dontSeeElement('input[name="new_lot_code"]');
        $I->seeElement('input[name="new_detail_expiry"]');

        // Undated, against a policy that requires a date: refused, and nothing is written.
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '8',
        ]);
        $I->assertSame(0, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'an undated row is refused under this policy');

        // Dated: the row is written carrying its own expiry, with no lot to hide it inside.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'new_detail_expiry' => '2028-03-31',
            'quantity' => '8',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $row = $em->getConnection()->fetchAssociative(
            'SELECT quantity, expiry, lot_id FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE],
        );

        $I->assertIsArray($row);
        $I->assertSame(8, (int) $row['quantity']);
        $I->assertNull($row['lot_id'], 'nothing is tracked, so there is no lot for the date to ride on');
        $I->assertSame('2028-03-31', substr((string) $row['expiry'], 0, 10));
    }

    /**
     * `track_in = false` on a tracked product means the operator is NOT asked — the row takes the
     * sentinel and `expect_resolution`, and lands on the tracking worklist.
     *
     * That mechanism has existed since #573 and the old adjustment form ignored it entirely, writing
     * a bare NULL lot onto a lot-tracked product with nothing recording that anyone should come
     * back.
     */
    public function aProductThatDoesNotCaptureBatchesInboundGetsTheSentinelAndTheWorklistFlag(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 0, $this->policy($I, TrackingPolicy::MODE_LOT, trackIn: false, trackOut: true));

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->dontSeeElement('input[name="new_lot_code"]');
        $I->see('tracking worklist');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '9',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $row = $em->getConnection()->fetchAssociative(
            'SELECT d.quantity, d.expect_resolution, l.code AS lot_code
               FROM inventory_detail d LEFT JOIN inventory_lot l ON l.id = d.lot_id
              WHERE d.product_id = ? AND d.status = ?',
            [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE],
        );

        $I->assertIsArray($row);
        $I->assertSame(9, (int) $row['quantity']);
        $I->assertSame(1, (int) $row['expect_resolution'], 'the row is flagged for somebody to come back to');
        $I->assertSame(TrackingPolicy::DEFAULT_SENTINEL, $row['lot_code'], 'and it carries the placeholder rather than a made-up code');

        // Which is exactly what the worklist is a list of.
        $I->amOnPage('/admin/bundles/inventory-depth/tracking');
        $I->seeResponseCodeIsSuccessful();
        $I->see($seed['product']->getSku());
    }

    /**
     * Serials are repeatable rows, and the row count has to equal the quantity.
     *
     * The old screen had one serial text box against a service that refuses more than one unit per
     * serial row, so 300 found serialised units meant 300 submissions. The mismatch is refused with
     * a sentence rather than by writing something wrong.
     */
    public function serialisedStockIsEnteredOneRowPerUnitAndTheCountMustMatch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 0, $this->policy($I, TrackingPolicy::MODE_SERIAL, trackIn: true, trackOut: false));

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->seeElement('input[name="serials[]"]');

        // Two serials against a quantity of three: refused outright, because one of the two answers
        // would have to be invented.
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '3',
            'serials' => ['ABC-0001', 'ABC-0002'],
        ]);
        $I->assertSame(0, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'a short serial list writes nothing at all');

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '3',
            'serials' => ['ABC-0001', 'ABC-0002', 'ABC-0003'],
        ]);

        $I->assertSame(3, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE));

        $em = $I->grabService('doctrine.orm.entity_manager');
        $I->assertSame(
            3,
            (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND serial IS NOT NULL AND quantity = 1',
                [$seed['product']->getId()],
            ),
            'three rows of one, which is what a serial means',
        );
    }

    /**
     * The same one-row-per-unit rule on the way OUT: the operator picks which serialised rows leave,
     * one per unit.
     *
     * The inbound half is above. This is the outbound half, and it is a genuinely different code
     * path — inbound TYPES serials, outbound CHOOSES existing rows — reached when the policy tracks
     * serials on the way out. Worth its own test because a screen that offered the rows and a
     * controller that read a different field would fail as "why did nothing happen", which is the
     * least debuggable shape this form has.
     */
    public function serialisedStockLeavesOneRowPerUnitToo(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 0, $this->policy($I, TrackingPolicy::MODE_SERIAL, trackIn: true, trackOut: true));

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_STOCK_FOUND);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_STOCK_FOUND,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'location_id' => (string) $seed['bin']->getId(),
            'quantity' => '3',
            'serials' => ['SN-1', 'SN-2', 'SN-3'],
        ]);
        $I->assertSame(3, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE));

        // Outbound the policy tracks serials, so the form offers the rows rather than a picker.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_LOST);
        $I->seeElement('select[name="source_detail_ids[]"]');
        $I->dontSeeElement('select[name="source_detail_id"]');
        $I->see('SN-1');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $rows = $em->getConnection()->fetchFirstColumn(
            'SELECT id FROM inventory_detail WHERE product_id = ? AND status = ? AND serial IN (?, ?) ORDER BY serial ASC',
            [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE, 'SN-1', 'SN-2'],
        );
        $I->assertCount(2, $rows);

        // Two rows against a quantity of two: one unit each, which is what a serial means.
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_LOST,
            'quantity' => '2',
            'source_detail_ids' => [(string) $rows[0], (string) $rows[1]],
        ]);

        $I->assertSame(2, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_LOST));
        $I->assertSame(1, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'SN-3 is untouched');

        $I->assertSame(
            1,
            (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND status = ? AND serial = ? AND quantity = 1',
                [$seed['product']->getId(), InventoryDetail::STATUS_LOST, 'SN-1'],
            ),
            'the units that left are the ones that were named, not "two of them"',
        );

        // And a row count that disagrees with the quantity writes nothing at all.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_LOST);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_LOST,
            'quantity' => '2',
            'source_detail_ids' => [(string) $rows[0]],
        ]);
        $I->assertSame(2, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_LOST), 'one row cannot answer for two units');
    }

    /**
     * The reason rows are configurable, so an admin can point one at a status a document owns — and
     * the controller refuses it anyway (#581).
     *
     * This is the guard that replaces the old status-dropdown refusal. The dropdown is gone; the
     * rule is not, and it has to live somewhere the configuration cannot reach. Driven by editing
     * the row rather than by a hand-built POST, because that is the door this design actually opens.
     */
    public function aReasonConfiguredToWriteADocumentBackedStatusIsStillRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);

        // Render once so the catalogue is seeded, then bend one of its rows.
        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_DAMAGED);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $reason = $em->getRepository(InventoryAdjustmentReason::class)->findOneBy(['code' => InventoryAdjustmentReason::CODE_DAMAGED]);
        $I->assertInstanceOf(InventoryAdjustmentReason::class, $reason);
        $reason->setToStatus(InventoryDetail::STATUS_SOLD);
        $em->flush();

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_DAMAGED,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '5',
        ]);

        $I->assertSame(0, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_SOLD), 'stock becomes sold through the invoice that bills it, and nowhere else');
        $I->assertSame(40, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_AVAILABLE), 'and the refused adjustment moved nothing');
    }

    /**
     * A deactivated reason comes off the list AND stops being recordable, because a dropdown cannot
     * stop a POST from an already-open page.
     */
    public function aDeactivatedReasonIsRefusedAndNotJustHidden(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_SCRAPPED);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $reason = $em->getRepository(InventoryAdjustmentReason::class)->findOneBy(['code' => InventoryAdjustmentReason::CODE_SCRAPPED]);
        $I->assertInstanceOf(InventoryAdjustmentReason::class, $reason);
        $reason->setActive(false);
        $em->flush();

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_SCRAPPED,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '5',
        ]);

        $I->assertSame(0, $this->detailTotal($I, $seed['product'], InventoryDetail::STATUS_SCRAPPED));

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId());
        $I->dontSeeElement('input[value="scrapped"]');
    }

    /**
     * The reason code lands on the movement group beside the free-text note, and both show in the
     * ledger.
     *
     * They answer different questions and neither substitutes for the other, which is the argument
     * for keeping `inventory_movement_group.reason` exactly as it is.
     */
    public function theReasonCodeAndTheFreeTextNoteBothReachTheLedger(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 20);

        $token = $this->openForm($I, $seed['product'], InventoryAdjustmentReason::CODE_DAMAGED);
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => InventoryAdjustmentReason::CODE_DAMAGED,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '2',
            'reference' => 'CLAIM-9912',
            'note' => 'Forklift went through the pallet',
            'occurred_at' => '2026-08-14',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $group = $em->getConnection()->fetchAssociative(
            'SELECT g.reason, g.reference, g.occurred_at, r.code AS reason_code, g.type
               FROM inventory_movement_group g
               LEFT JOIN inventory_adjustment_reason r ON r.id = g.adjustment_reason_id
              WHERE g.reference = ?',
            ['CLAIM-9912'],
        );

        $I->assertIsArray($group);
        $I->assertSame('damaged', $group['reason_code'], 'the code is what a report groups by');
        $I->assertSame('Forklift went through the pallet', $group['reason'], 'and the note is what the next reader needs');
        $I->assertSame('status_change', $group['type'], 'the type is derived from the reason, not chosen');
        $I->assertStringStartsWith('2026-08-14', (string) $group['occurred_at'], 'stock damaged on the 14th belongs on the 14th');

        $I->amOnPage('/admin/bundles/inventory-depth/movements');
        $I->see('Forklift went through the pallet');
        $I->see('Damaged');
    }
}
