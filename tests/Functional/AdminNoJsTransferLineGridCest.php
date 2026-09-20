<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
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
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;

/**
 * Transfer line entry as a grid, driven the way a browser with scripting off drives it (#590 P2,
 * #610, #611).
 *
 * The screen used to be a four-field panel below the table with `<input type="number" name="lot_id">`
 * in it: one line per submission, the batch named by primary key, and no indication anywhere of what
 * that batch held. It is now one row per line inside the table itself, with a blank row at the
 * bottom that Add line turns into the next line.
 *
 * **Nothing here uses JavaScript, and that is the point of the file.** Every POST below goes through
 * sendFormPostRequest(), i.e. a plain browser form post with no `X-Requested-With` header — what a
 * browser with scripting off sends when a submit button is pressed. It carries only field names the
 * page actually renders, and each test first asserts that those fields ARE rendered, in the table,
 * carrying the `form=` attribute that joins them to a real `<form>` emitted outside it. (submitForm()
 * cannot be used on an admin screen at all: the crawler resolves the action against the
 * admin.localhost Host header and the module then refuses it as an external URL — see
 * Tests\Support\Helper\Functional::sendFormPostRequest.)
 *
 * The `form=` attribute is what makes the grid legal HTML rather than merely convenient: a `<form>`
 * inside a `<form>` is invalid and every parser silently drops the inner one, so the row controls
 * have to live in the table while their forms live beside it.
 *
 * The assertions are about rows: which `transfer_order_line` exists afterwards and what its
 * `lot_id`, `serial` and `quantity_requested` read. Seeing a flash is not evidence that anything
 * was stored.
 */
final class AdminNoJsTransferLineGridCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('nojs-transfer-grid-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * One dimensional product with two batches at a source warehouse, and a draft transfer to
     * somewhere else. The batches are deliberately dated so that "longest-dated" and
     * "earliest-expiring" are different rows — a transfer takes the longer one.
     *
     * @return array{product: ProductCore, other: ProductCore, warehouse: Warehouse, bin: WarehouseLocation, early: InventoryLot, late: InventoryLot, transfer: TransferOrder}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('NoJs Grid Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $destinationRegion = (new FulfillmentRegion())->setName('NoJs Grid Region B ' . uniqid());
        $em->persist($destinationRegion);
        $destination = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($destinationRegion, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('NOJSGRID-' . strtoupper(substr(uniqid(), -6)))
            ->setName('No-JS Grid Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(60));

        // A second product, so "this lot is not a batch of that product" has something to refuse.
        $other = (new ProductCore())
            ->setSku('NOJSOTHER-' . strtoupper(substr(uniqid(), -6)))
            ->setName('No-JS Grid Other Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($other);
        $em->persist((new ProductInventory())->setProduct($other)->setWarehouse($warehouse)->setQuantity(5));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('NJ-01')->setSortKey(10);
        $em->persist($bin);

        $early = (new InventoryLot())->setProduct($product)->setCode('SEA-2609')->setExpiry(new \DateTimeImmutable('2026-09-10'));
        $late = (new InventoryLot())->setProduct($product)->setCode('SEA-2704')->setExpiry(new \DateTimeImmutable('2027-09-27'));
        $otherLot = (new InventoryLot())->setProduct($other)->setCode('OTH-2801')->setExpiry(new \DateTimeImmutable('2028-01-01'));
        $em->persist($early);
        $em->persist($late);
        $em->persist($otherLot);

        $transfer = (new TransferOrder())
            ->setNumber('TR-NJ-' . random_int(1000, 9999))
            ->setFromWarehouse($warehouse)
            ->setToWarehouse($destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);
        $em->persist($transfer);

        $em->flush();

        $switcher = $I->grabService(InventoryModeSwitcher::class);
        $switcher->toDimensional($product, 'nojs-transfer-grid@example.test');
        $switcher->toDimensional($other, 'nojs-transfer-grid@example.test');

        $this->stockInto($I, $product, $warehouse, $bin, $early, null, 40);
        $this->stockInto($I, $product, $warehouse, $bin, $late, null, 18);
        $this->stockInto($I, $product, $warehouse, $bin, $late, 'SN-77001', 1);
        $this->stockInto($I, $other, $warehouse, $bin, $otherLot, null, 5);

        return [
            'product' => $product,
            'other' => $other,
            'warehouse' => $warehouse,
            'bin' => $bin,
            'early' => $early,
            'late' => $late,
            'otherLot' => $otherLot,
            'transfer' => $transfer,
        ];
    }

    private function stockInto(FunctionalTester $I, ProductCore $product, Warehouse $warehouse, ?WarehouseLocation $bin, ?InventoryLot $lot, ?string $serial, int $quantity): void
    {
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'nojs-grid-' . uniqid(), 'Put away for the no-JS transfer grid test')
                ->receive($product, new DetailKey($warehouse, $bin, $lot, $serial, InventoryDetail::STATUS_AVAILABLE), $quantity)
        );
    }

    /**
     * The blank row at the bottom of the table, submitted.
     *
     * Every name here is a control the entry row renders — `product_id`, `quantity`, `lot_id`,
     * `lot_code`, `serial`, `serial_match` — all of them carrying `form="transfer-add-line"`, which
     * is asserted in the tests rather than assumed here.
     *
     * @param array<string, string> $params
     */
    private function addLine(FunctionalTester $I, int $transferId, array $params): void
    {
        $I->sendFormPostRequest(
            '/admin/bundles/warehouse-ops/transfers/' . $transferId . '/lines',
            array_merge(['_token' => $I->csrfToken()], $params),
        );
    }

    /**
     * One line's own row, submitted. `line_id` is the hidden field inside that row's form, which is
     * what names the row the save updates.
     *
     * @param array<string, string> $params
     */
    private function updateLine(FunctionalTester $I, int $transferId, int $lineId, array $params): void
    {
        $I->sendFormPostRequest(
            '/admin/bundles/warehouse-ops/transfers/' . $transferId . '/lines/update',
            array_merge(['_token' => $I->csrfToken(), 'line_id' => (string) $lineId], $params),
        );
    }

    /** @return list<TransferOrderLine> */
    private function linesOf(FunctionalTester $I, int $transferId): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $transfer = $em->find(TransferOrder::class, $transferId);
        $I->assertInstanceOf(TransferOrder::class, $transfer);

        return array_values($transfer->getLines()->toArray());
    }

    // ----------------------------------------------------------- the add-a-row loop

    /**
     * The loop the whole redesign exists for: fill the blank row at the bottom of the table, press
     * Add line, and the page that comes back has the line as a row AND a fresh blank row under it.
     * Repeat for as many lines as the delivery has.
     *
     * Asserted after every hop, not just the first — without the fresh row the "grid" is a one-shot
     * form with extra steps.
     */
    public function addingALineWithoutJavaScriptAppendsARowAndLeavesAFreshBlankRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();
        $url = '/admin/bundles/warehouse-ops/transfers/' . $transferId;

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        // The blank row is IN the table, not in a panel below it.
        $I->seeElement('table tr#new-line select[name="product_id"][form="transfer-add-line"]');
        $I->seeElement('form#transfer-add-line[method="post"]');

        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '7',
            'lot_id' => (string) $seed['early']->getId(),
        ]);
        $I->seeResponseCodeIsSuccessful();

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(1, $lines, 'Add line did not append a transfer_order_line row');
        $I->assertSame(7, $lines[0]->getQuantityRequested());
        $I->assertSame((int) $seed['early']->getId(), (int) $lines[0]->getLot()?->getId(), 'the chosen batch is not the one stored in transfer_order_line.lot_id');

        // Back on the page: the line is a row of controls, and the blank row is waiting again.
        $I->amOnPage($url);
        $lineId = (int) $lines[0]->getId();
        $I->seeElement('tr#line-' . $lineId . ' input[name="quantity"][form="line-form-' . $lineId . '"][value="7"]');
        $I->seeElement('tr#new-line select[name="product_id"][form="transfer-add-line"]');

        // Second hop, through the same blank row.
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '3',
            'lot_id' => (string) $seed['late']->getId(),
        ]);

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(2, $lines, 'the second Add line did not append a second row');

        $I->amOnPage($url);
        $I->seeElement('tr#new-line select[name="product_id"][form="transfer-add-line"]');
    }

    /**
     * A line is corrected in its own row. The update form is a sibling of the table and the row's
     * quantity box belongs to it through `form=` — so a plain submit with no script posts it.
     */
    public function aLineIsEditedInItsOwnRowWithoutJavaScript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();
        $url = '/admin/bundles/warehouse-ops/transfers/' . $transferId;

        $I->amOnPage($url);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '4',
            'lot_id' => (string) $seed['early']->getId(),
        ]);

        $lines = $this->linesOf($I, $transferId);
        $lineId = (int) $lines[0]->getId();

        $I->amOnPage($url);
        // There is no separate "Add or edit" panel any more, and no ?edit= fork to reach one.
        $I->dontSeeElement('input[type="number"][name="lot_id"]');
        $I->seeElement('form#line-form-' . $lineId . '[method="post"] input[type="hidden"][name="line_id"][value="' . $lineId . '"]');

        $this->updateLine($I, $transferId, $lineId, [
            'quantity' => '9',
            'lot_id' => (string) $seed['late']->getId(),
        ]);

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(1, $lines, 'the in-row edit forked the line instead of updating it');
        $I->assertSame(9, $lines[0]->getQuantityRequested());
        $I->assertSame((int) $seed['late']->getId(), (int) $lines[0]->getLot()?->getId());
    }

    // ----------------------------------------------------------- naming the batch

    /**
     * The lot picker names a batch the way a human recognises one — code, expiry and what the source
     * holds — and there is no number box left to type a primary key into.
     *
     * It is a plain `<select>` with every option rendered server-side; `js-searchable-select` only
     * layers type-to-filter on top of it when scripting is on, so what this test sees is exactly what
     * a browser with JavaScript off gets.
     */
    public function theLotPickerNamesTheBatchAndItsAvailableQuantityRatherThanAnId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $seed['transfer']->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeElement('input[type="number"][name="lot_id"]');
        $I->seeElement('tr#new-line select[name="lot_id"].js-searchable-select');

        // Code, expiry and quantity, all three, because a bare batch code is ambiguous.
        $I->see('SEA-2609 · exp 2026-09-10 · 40 available', 'tr#new-line select[name="lot_id"] option');
        $I->see('SEA-2704 · exp 2027-09-27 · 19 available', 'tr#new-line select[name="lot_id"] option');
        // The transfer rule, on screen and choosable rather than a hint inside a <label>.
        $I->see('auto: the longest-dated batch', 'tr#new-line select[name="lot_id"] option');

        // Serials are chosen from what is actually there, not typed blind.
        $I->seeElement('tr#new-line select[name="serial"]');
        $I->see('S/N SN-77001', 'tr#new-line select[name="serial"] option');
    }

    /**
     * Leaving the lot alone still means "the longest-dated batch", which is the transfer rule and
     * the opposite of what a customer shipment does. The dropdown's first option SAYS so; this
     * asserts it still DOES so.
     */
    public function leavingTheLotBlankStillTakesTheLongestDatedBatch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '2',
            'lot_id' => '0',
        ]);

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(1, $lines);
        $I->assertSame(
            (int) $seed['late']->getId(),
            (int) $lines[0]->getLot()?->getId(),
            'a blank lot took something other than the longest-dated batch',
        );
    }

    // ----------------------------------------------------------- wildcard search

    /**
     * The typed pattern beside the dropdown, matched on the server: `SEA-26*` names the batch
     * without anybody knowing its id, and it works with scripting off because it is an ordinary form
     * field on an ordinary POST.
     */
    public function aTypedWildcardPatternNamesTheLotServerSide(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->seeElement('tr#new-line input[type="text"][name="lot_code"][form="transfer-add-line"]');

        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '5',
            'lot_id' => '0',
            'lot_code' => 'SEA-26*',
        ]);

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(1, $lines);
        $I->assertSame((int) $seed['early']->getId(), (int) $lines[0]->getLot()?->getId(), 'the wildcard pattern did not resolve to SEA-2609');
    }

    /** A pattern that names two batches is refused with both of them spelled out, not guessed at. */
    public function anAmbiguousLotPatternIsRefusedAndNamesTheCandidates(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '5',
            'lot_id' => '0',
            'lot_code' => 'SEA*',
        ]);

        // The refusal is on the page the POST landed on — a flash is read once, so asserting it
        // after another page load would assert nothing.
        $I->see('matches 2 batches');
        $I->see('SEA-2609 · exp 2026-09-10 · 40 available');

        $I->assertCount(0, $this->linesOf($I, $transferId), 'an ambiguous pattern added a line anyway');
    }

    /** And one that names nothing is refused rather than falling through to the auto-suggestion. */
    public function aLotPatternMatchingNothingIsRefusedRatherThanIgnored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '5',
            'lot_id' => '0',
            'lot_code' => 'NOSUCHBATCH*',
        ]);

        $I->see('NOSUCHBATCH*');

        $I->assertCount(0, $this->linesOf($I, $transferId), 'an unmatched pattern quietly used the auto-suggestion');
    }

    /** Serials take the same pattern, against the serials the source actually holds. */
    public function aTypedWildcardPatternNamesTheSerialServerSide(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->seeElement('tr#new-line input[type="text"][name="serial_match"][form="transfer-add-line"]');

        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '1',
            'lot_id' => '0',
            'serial_match' => 'SN-77*',
        ]);

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(1, $lines);
        $I->assertSame('SN-77001', $lines[0]->getSerial());
    }

    // ----------------------------------------------------------- the refusal is the rule

    /**
     * The dropdown is a convenience; the refusal is the rule. A hand-built POST naming a batch of a
     * different product is turned away, which is the check `addLine()` never had — it used to
     * `find()` the id and store it, and the line was only discovered to be nonsense at dispatch.
     */
    public function aLotBelongingToAnotherProductIsRefusedAtEntry(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '1',
            'lot_id' => (string) $seed['otherLot']->getId(),
        ]);

        $I->see('is not a batch of');

        $I->assertCount(0, $this->linesOf($I, $transferId), 'a lot of another product was accepted onto the line');
    }

    /**
     * Asking for more than the source holds is stated, not refused: a draft is a plan, and stock
     * arriving tomorrow is an ordinary reason to raise one. The figure is printed under the row's
     * quantity as well, server-rendered, so it is readable with scripting off.
     */
    public function askingForMoreThanTheSourceHoldsIsStatedOnTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '99',
            'lot_id' => (string) $seed['late']->getId(),
        ]);

        $lines = $this->linesOf($I, $transferId);
        $I->assertCount(1, $lines, 'a draft line for stock not yet there was refused');

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->seeElement('tr#line-' . $lines[0]->getId() . ' small.line-stock-hint-over');
        // Read out of that row's hint (#627): see('19 available at') is a substring match over the
        // page, so '119 available at' and a hint belonging to some other row satisfied it equally.
        $I->assertStringStartsWith(
            '19 available at ',
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('tr#line-' . $lines[0]->getId() . ' small.line-stock-hint'))),
            'inventory_detail available on the late lot: 19, against a line asking 99',
        );
    }

    // ----------------------------------------------------------- shape

    /**
     * The forms the row controls belong to are emitted OUTSIDE the table, never nested inside the
     * receive form or each other. A `<form>` inside a `<form>` is invalid HTML and every parser
     * drops the inner one, so a nested Save would silently post the wrong thing.
     */
    public function theRowFormsAreSiblingsOfTheTableRatherThanNestedInIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $transferId = (int) $seed['transfer']->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $this->addLine($I, $transferId, [
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => '1',
            'lot_id' => '0',
        ]);

        $lineId = (int) $this->linesOf($I, $transferId)[0]->getId();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers/' . $transferId);
        $I->dontSeeElement('table form');
        $I->dontSeeElement('form form');
        $I->seeElement('button.table-action[type="submit"][form="line-form-' . $lineId . '"]');
        $I->seeElement('button.table-action.danger[type="submit"][form="delete-line-' . $lineId . '"]');
        // And this is a document page, so it carries none of the list-screen scroll furniture.
        $I->dontSeeElement('.table-scroll-region');
    }
}
