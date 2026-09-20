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

/**
 * The one notice at the top of a draft transfer's Lines section that names the lines the source
 * cannot fill (#626), and the conducted test #624 asks for.
 *
 * The per-line hint has printed `N available at <warehouse>` under an over-quantity row since #611.
 * On a forty-line transfer nobody reads that row, so a draft with thirty-nine good lines and one
 * short one looked fine. #626 gathers the same shortfall into one sentence at the top. It is a
 * statement, not a refusal: a draft is a plan, stock arrives, and `dispatch()` is the single place
 * the rule is enforced.
 *
 * **Everything here is driven through the real screens with plain form posts.** Lines go on through
 * the blank entry row's `transfer-add-line` form, corrections through the row's own
 * `line-form-<id>`, and the dispatch through the Dispatch button's form — every one of them via
 * sendFormPostRequest(), i.e. no `X-Requested-With`, which is what a browser with scripting off
 * sends. The CSRF token is scraped off the rendered form each time and posted back, so these go
 * THROUGH the app's CSRF check rather than round it. submitForm() cannot be used on an admin screen
 * at all: with the `admin.localhost` Host header the crawler resolves the form action as an
 * absolute URL and the module refuses it as external — see
 * Tests\Support\Helper\Functional::sendFormPostRequest.
 *
 * ## What each test is actually pinned to, and why not `dontSeeElement`
 *
 * #627's warning applies squarely to a display-only feature: `dontSeeElement('p.alert.warn')`
 * passes just as happily when the selector is a typo, when the page 500s into an error template,
 * and when the notice was renamed — none of which are "the notice is correctly absent". So the
 * absence assertions here are made on TEXT the notice always contains, unscoped, and every one of
 * them is paired with a positive control that makes the same string appear:
 *
 *  - the fits-entirely test raises the very same line's quantity through the very same edit form
 *    and watches the sentence it just asserted absent turn up;
 *  - the negative test asserts the short product IS named inside `p.alert.warn` before asserting
 *    the fitting one is NOT, so the selector is proven to match something first.
 *
 * The counted assertions go through `p.alert.warn strong`, one `<strong>` per named line, because a
 * count of entries is the thing the cap and the two-lots-one-product case are actually about, and
 * text alone cannot tell one entry from two.
 *
 * ## The case that already went wrong once
 *
 * Two lines of the SAME product differing only by lot must render as two distinguishable entries.
 * The first cut of the feature printed the product name alone, so four lines of one SKU read as the
 * same sentence four times — indistinguishable from a rendering bug, and useless for finding the
 * row. `twoLinesOfOneProductDifferingOnlyByLotAreTwoDistinguishableEntries()` fixes the shape of
 * that: same SKU, different batches, deliberately different requested and available figures so no
 * collapsed or duplicated render can satisfy both assertions by accident.
 *
 * ## And the notice is not a gate
 *
 * `dispatchIsStillRefusedByTheDomainActionAndNotByTheNotice()` is the one that would catch the
 * expensive mistake: a template that started refusing would move enforcement out of
 * TransferOrderService and into Twig, where #584's stale-availability problem lives. It asserts the
 * Dispatch button is still rendered under the notice, that the refusal that comes back is the
 * movement layer's own sentence rather than the notice's wording, that `transfer_order.status` and
 * `transfer_order_line.quantity_dispatched` are untouched — and then tops the shelf up and
 * dispatches the same document through the same button, which is what proves the earlier refusal
 * was about the stock and never about the notice.
 */
final class AdminTransferShortfallNoticeCest
{
    /**
     * The notice itself. Nothing else on this screen renders `.alert warn` — flashes are
     * `div.flash-*` from `@WarehouseOps/_nav.html.twig`, and the per-row hint is
     * `small.line-stock-hint` — so this selector means the notice and only the notice.
     */
    private const NOTICE = 'p.alert.warn';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('transfer-shortfall-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A source warehouse with a bin, somewhere to send to, and an empty draft between them.
     *
     * Built as fixtures rather than through the create form because the document is not what is
     * under test — the LINES are, and every one of those goes on through the screen. Each test
     * builds its own, so nothing here depends on seed data or on another test's leftovers.
     *
     * Everything is handed back by ID rather than as an entity, deliberately. A page request through
     * the Symfony module can reset the container — and with it the EntityManager — so a fixture
     * object held across one comes back DETACHED, and the next thing that persists a reference to it
     * dies with "a new entity was found through the relationship 'ProductInventory#warehouse'". Not
     * hypothetical: it is what the six-line cap test did on its first run, because that one is the
     * only test here that builds fixtures BETWEEN page requests. Passing ids and looking them up
     * again through source()/bin() costs one identity-map hit and removes the trap.
     *
     * @return array{sourceId: int, binId: int, sourceName: string, transferId: int, url: string}
     */
    private function draftTransfer(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $sourceRegion = (new FulfillmentRegion())->setName('Shortfall Source ' . uniqid());
        $em->persist($sourceRegion);
        $source = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($sourceRegion, 'BC', 'CA');

        $destinationRegion = (new FulfillmentRegion())->setName('Shortfall Destination ' . uniqid());
        $em->persist($destinationRegion);
        $destination = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($destinationRegion, 'BC', 'CA');

        $bin = (new WarehouseLocation())->setWarehouse($source)->setCode('SF-01')->setSortKey(10);
        $em->persist($bin);

        $transfer = (new TransferOrder())
            ->setNumber('TR-SF-' . random_int(1000, 9999))
            ->setFromWarehouse($source)
            ->setToWarehouse($destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);
        $em->persist($transfer);
        $em->flush();

        $transferId = (int) $transfer->getId();

        return [
            'sourceId' => (int) $source->getId(),
            'binId' => (int) $bin->getId(),
            'sourceName' => $source->getName(),
            'transferId' => $transferId,
            'url' => '/admin/bundles/warehouse-ops/transfers/' . $transferId,
        ];
    }

    /** The source warehouse, freshly managed — see draftTransfer()'s note on detachment. */
    private function source(FunctionalTester $I, array $context): Warehouse
    {
        return $I->grabService('doctrine.orm.entity_manager')->find(Warehouse::class, $context['sourceId']);
    }

    /** The source's one bin, freshly managed. */
    private function bin(FunctionalTester $I, array $context): WarehouseLocation
    {
        return $I->grabService('doctrine.orm.entity_manager')->find(WarehouseLocation::class, $context['binId']);
    }

    /**
     * One dimensional product with the batches named, each holding exactly the units given.
     *
     * The `product_inventory` row opens at ZERO on purpose. InventoryModeSwitcher::toDimensional()
     * moves whatever is in `quantity` in as an opening balance with **no lot** — and a lotless
     * detail row is counted by TransferSourceStock::available() for any line that names no batch,
     * which would quietly pad the availability these tests are asserting exact figures against.
     * Starting at zero and putting every unit away under a named batch means the only stock at the
     * source is stock this method placed there.
     *
     * @param array{sourceId: int, binId: int, sourceName: string, transferId: int, url: string} $context
     * @param array<string, int> $batches lot code => units on the shelf under it
     * @param array<string, string> $serials lot code => the serial its single unit carries
     *
     * @return array{id: int, sku: string, lots: array<string, int>}
     */
    private function stockedProduct(FunctionalTester $I, array $context, string $sku, array $batches, array $serials = []): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Shortfall ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->source($I, $context))->setQuantity(0));

        $lots = [];
        $year = 2027;
        foreach ($batches as $code => $units) {
            $lot = (new InventoryLot())
                ->setProduct($product)
                ->setCode($code)
                ->setExpiry(new \DateTimeImmutable(($year++) . '-06-30'));
            $em->persist($lot);
            $lots[$code] = $lot;
        }

        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'transfer-shortfall@example.test');

        $lotIds = [];
        foreach ($batches as $code => $units) {
            $this->putAway($I, $product, $context, $lots[$code], $serials[$code] ?? null, $units);
            $lotIds[$code] = (int) $lots[$code]->getId();
        }

        return ['id' => (int) $product->getId(), 'sku' => $sku, 'lots' => $lotIds];
    }

    /**
     * Units onto the shelf the way anything else puts them there — a real receipt through the
     * movement layer, so `inventory_detail` is what TransferSourceStock will later read rather than
     * a number this test wrote into a column by hand.
     *
     * @param array{sourceId: int, binId: int, sourceName: string, transferId: int, url: string} $context
     */
    private function putAway(FunctionalTester $I, ProductCore $product, array $context, ?InventoryLot $lot, ?string $serial, int $units): void
    {
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'shortfall-' . uniqid(), 'Put away for the transfer shortfall notice test')
                ->receive($product, new DetailKey($this->source($I, $context), $this->bin($I, $context), $lot, $serial, InventoryDetail::STATUS_AVAILABLE), $units)
        );
    }

    /**
     * The blank entry row at the bottom of the table, submitted.
     *
     * The token comes off the rendered `transfer-add-line` form rather than out of a service, so a
     * form that stopped emitting one would fail here rather than pass on a token the page never had.
     *
     * @param array<string, string> $params
     */
    private function addLine(FunctionalTester $I, array $context, array $params): void
    {
        $I->amOnPage($context['url']);
        $token = (string) $I->grabAttributeFrom('form#transfer-add-line input[name="_token"]', 'value');

        $I->sendFormPostRequest(
            $context['url'] . '/lines',
            array_merge(['_token' => $token], $params),
        );
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * One existing line's own row, corrected in place. `line_id` is the hidden field inside that
     * row's form, which is what names the row the save updates.
     *
     * @param array<string, string> $params
     */
    private function updateLine(FunctionalTester $I, array $context, int $lineId, array $params): void
    {
        $I->amOnPage($context['url']);
        $token = (string) $I->grabAttributeFrom('form#line-form-' . $lineId . ' input[name="_token"]', 'value');

        $I->sendFormPostRequest(
            $context['url'] . '/lines/update',
            array_merge(['_token' => $token, 'line_id' => (string) $lineId], $params),
        );
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * A column off the row itself, read back out of the database after the operation rather than
     * off an entity fetched before it — the identity map would happily hand back the object the
     * request already mutated, which proves nothing about what was stored.
     */
    private function column(FunctionalTester $I, string $sql, array $params): ?string
    {
        $value = $I->grabService('doctrine.orm.entity_manager')->getConnection()->fetchOne($sql, $params);

        return $value === false || $value === null ? null : (string) $value;
    }

    private function lineIdBySku(FunctionalTester $I, int $transferId, string $sku): int
    {
        return (int) $this->column(
            $I,
            'SELECT id FROM transfer_order_line WHERE transfer_order_id = ? AND sku = ?',
            [$transferId, $sku],
        );
    }

    /** The sentence the notice always opens with, whichever way it pluralises. */
    private function noticeOpening(array $context): string
    {
        return ' for more than ' . $context['sourceName'] . ' holds today:';
    }

    // ------------------------------------------------------------------ 1. nothing to say

    /**
     * A draft every line of which fits shows no notice at all — and the absence is asserted the one
     * way #627 leaves standing.
     *
     * `dontSeeElement(self::NOTICE)` on its own would pass for a mistyped selector, a renamed class
     * and a 500 into the error template alike, so what is asserted absent is the notice's own
     * SENTENCE, unscoped. That is still only worth something if the sentence can appear at all — so
     * the second half raises this very line's quantity past the shelf through this very screen's
     * edit form and watches the same string turn up. Same page, same assertion, opposite answer:
     * the only thing that moved is the quantity.
     */
    public function aDraftWhereEveryLineFitsSaysNothingAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->draftTransfer($I);
        $widget = $this->stockedProduct($I, $context, 'SF-FITS-' . strtoupper(substr(uniqid(), -5)), ['FIT-A' => 40]);

        $this->addLine($I, $context, [
            'product_id' => (string) $widget['id'],
            'quantity' => '10',
            'lot_id' => (string) $widget['lots']['FIT-A'],
        ]);

        $lineId = $this->lineIdBySku($I, $context['transferId'], $widget['sku']);
        $I->assertGreaterThan(0, $lineId, 'Add line did not append a transfer_order_line row');

        $I->amOnPage($context['url']);
        $I->seeResponseCodeIsSuccessful();
        // The page really is this draft's line grid, with the line on it — so the absence below is
        // about the notice and not about having landed somewhere else.
        $I->seeElement('tr#line-' . $lineId . ' input[name="quantity"][form="line-form-' . $lineId . '"][value="10"]');
        $I->assertSame(
            '40 available at ' . $context['sourceName'],
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('tr#line-' . $lineId . ' small.line-stock-hint'))),
            'inventory_detail available at the source: 40, not the 140 a see() would also have taken (#627)',
        );

        $I->dontSee($this->noticeOpening($context));
        $I->dontSeeElement(self::NOTICE);

        // The positive control. Ten of forty fits; sixty does not, and nothing else changes.
        $this->updateLine($I, $context, $lineId, [
            'quantity' => '60',
            'lot_id' => (string) $widget['lots']['FIT-A'],
        ]);

        $I->amOnPage($context['url']);
        $I->see($this->noticeOpening($context));
        $I->seeElement(self::NOTICE);
        $I->see($widget['sku'] . ' · lot FIT-A needs 60, 40 available (short 20)', self::NOTICE);
    }

    // ------------------------------------------------------------------ 2. one short line, named

    /**
     * A short line is named — product, batch, and the figure that says how far short.
     *
     * Both ways a line can name its stock are covered, because "which of these four rows" is the
     * whole point of the notice: one line carrying a lot, one carrying a lot AND a serial. The two
     * shortfalls are deliberately different numbers so neither assertion can be satisfied by the
     * other entry's text.
     *
     * The plural also matters and is asserted: `2 lines ask` rather than `2 line asks`, which is the
     * kind of thing a template gets wrong the first time anybody has two of something.
     */
    public function aShortLineIsNamedWithItsBatchSerialAndShortByFigure(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->draftTransfer($I);

        $loose = $this->stockedProduct($I, $context, 'SF-LOOSE-' . strtoupper(substr(uniqid(), -5)), ['SHORT-A' => 4]);
        $unit = $this->stockedProduct(
            $I,
            $context,
            'SF-UNIT-' . strtoupper(substr(uniqid(), -5)),
            ['SHORT-B' => 1],
            ['SHORT-B' => 'SN-99001'],
        );

        $this->addLine($I, $context, [
            'product_id' => (string) $loose['id'],
            'quantity' => '10',
            'lot_id' => (string) $loose['lots']['SHORT-A'],
        ]);
        $this->addLine($I, $context, [
            'product_id' => (string) $unit['id'],
            'quantity' => '3',
            'lot_id' => (string) $unit['lots']['SHORT-B'],
            'serial' => 'SN-99001',
        ]);

        $I->amOnPage($context['url']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement(self::NOTICE);

        $I->assertStringStartsWith(
            '2 lines ask' . $this->noticeOpening($context),
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom(self::NOTICE))),
            'the notice counts the short lines; see() would have taken a leading digit too (#627)',
        );
        $I->see($loose['sku'] . ' · lot SHORT-A needs 10, 4 available (short 6)', self::NOTICE);
        $I->see($unit['sku'] . ' · lot SHORT-B · s/n SN-99001 needs 3, 1 available (short 2)', self::NOTICE);
        // One <strong> per named line: two lines, two entries, no third.
        $I->seeNumberOfElements(self::NOTICE . ' strong', 2);

        // And the notice did not become a refusal on the way in — both lines are on the document.
        $I->assertSame(
            '10',
            $this->column($I, 'SELECT quantity_requested FROM transfer_order_line WHERE id = ?', [$this->lineIdBySku($I, $context['transferId'], $loose['sku'])]),
            'transfer_order_line.quantity_requested — a draft may ask for stock that is not there yet',
        );
    }

    // ------------------------------------------------------------------ 3. the one already got wrong

    /**
     * Two lines of the SAME product differing only by batch are two entries a person can tell apart.
     *
     * This is the case the first cut of #626 got wrong: it printed the product and nothing else, so
     * four lines of one SKU read as the same sentence four times — which looks like a rendering bug
     * and, worse, does not say WHICH row to go and fix. That is the whole reason the notice names
     * products at all instead of counting them.
     *
     * The two lines are given different requested and available figures on purpose. With matching
     * numbers a render that emitted one entry twice, or that collapsed the two lines into one and
     * doubled it, would satisfy both `see()` calls; with these it cannot.
     */
    public function twoLinesOfOneProductDifferingOnlyByLotAreTwoDistinguishableEntries(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->draftTransfer($I);
        $twin = $this->stockedProduct($I, $context, 'SF-TWIN-' . strtoupper(substr(uniqid(), -5)), ['TWIN-A' => 2, 'TWIN-B' => 3]);

        $this->addLine($I, $context, [
            'product_id' => (string) $twin['id'],
            'quantity' => '9',
            'lot_id' => (string) $twin['lots']['TWIN-A'],
        ]);
        $this->addLine($I, $context, [
            'product_id' => (string) $twin['id'],
            'quantity' => '8',
            'lot_id' => (string) $twin['lots']['TWIN-B'],
        ]);

        $I->assertSame(
            '2',
            $this->column($I, 'SELECT COUNT(*) FROM transfer_order_line WHERE transfer_order_id = ?', [$context['transferId']]),
            'the two same-product lines did not both reach transfer_order_line',
        );

        $I->amOnPage($context['url']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement(self::NOTICE);

        $I->assertStringStartsWith(
            '2 lines ask' . $this->noticeOpening($context),
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom(self::NOTICE))),
            'the notice counts the short lines; see() would have taken a leading digit too (#627)',
        );
        $I->seeNumberOfElements(self::NOTICE . ' strong', 2);
        $I->see($twin['sku'] . ' · lot TWIN-A needs 9, 2 available (short 7)', self::NOTICE);
        $I->see($twin['sku'] . ' · lot TWIN-B needs 8, 3 available (short 5)', self::NOTICE);

        // Belt and braces on the failure this test exists for: the two entries must not be the same
        // sentence. Asserting the batch codes are both present is not enough on its own — a notice
        // that printed one line's text twice would still contain each code once if it happened to
        // interleave them — so the entries are compared as whole distinct strings.
        $notice = $I->grabTextFrom(self::NOTICE);
        $I->assertStringContainsString('TWIN-A', $notice);
        $I->assertStringContainsString('TWIN-B', $notice);
        $I->assertSame(1, substr_count($notice, 'needs 9, 2 available (short 7)'), 'the TWIN-A entry is not there exactly once');
        $I->assertSame(1, substr_count($notice, 'needs 8, 3 available (short 5)'), 'the TWIN-B entry is not there exactly once');
    }

    // ------------------------------------------------------------------ 4. the cap

    /**
     * Past five short lines the notice lists five and says how many it did not.
     *
     * A notice that grew with the transfer would be the same problem it exists to solve: forty
     * sentences at the top of a forty-line document is not a summary. So the cap is asserted on the
     * ENTRY COUNT — one `<strong>` per named line — rather than on the text, because "five" is a
     * structural claim and `see('and 1 more')` alone would pass a page that listed all six and then
     * said "and 1 more" underneath.
     *
     * Which five is deliberately not asserted; how many are named is. The names are matched against
     * the SKUs this test created so the count cannot be satisfied by anything else on the page.
     */
    public function pastFiveShortLinesTheNoticeNamesFiveAndCountsTheRest(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->draftTransfer($I);

        $skus = [];
        for ($n = 1; $n <= 6; $n++) {
            $product = $this->stockedProduct($I, $context, 'SF-CAP' . $n . '-' . strtoupper(substr(uniqid(), -5)), ['CAP-' . $n => 1]);
            $skus[] = $product['sku'];

            $this->addLine($I, $context, [
                'product_id' => (string) $product['id'],
                'quantity' => '4',
                'lot_id' => (string) $product['lots']['CAP-' . $n],
            ]);
        }

        $I->assertSame(
            '6',
            $this->column($I, 'SELECT COUNT(*) FROM transfer_order_line WHERE transfer_order_id = ?', [$context['transferId']]),
            'six lines did not reach transfer_order_line, so the cap is not what is being tested',
        );

        $I->amOnPage($context['url']);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement(self::NOTICE);

        // The count is the whole shortfall, not the truncated list.
        $I->assertStringStartsWith(
            '6 lines ask' . $this->noticeOpening($context),
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom(self::NOTICE))),
            'the notice counts the short lines; see() would have taken a leading digit too (#627)',
        );
        $I->seeNumberOfElements(self::NOTICE . ' strong', 5);
        $I->see('and 1 more.', self::NOTICE);

        $notice = $I->grabTextFrom(self::NOTICE);
        $named = array_values(array_filter($skus, static fn (string $sku): bool => str_contains($notice, $sku)));
        $I->assertCount(5, $named, 'the notice named ' . \count($named) . ' of the six short lines rather than five');
    }

    // ------------------------------------------------------------------ 5. still not a gate

    /**
     * Dispatch is still refused by `dispatch()`, and the notice is still only a notice.
     *
     * The mistake worth catching here is a template that quietly became a second enforcement point.
     * That would be wrong twice over: availability moves under a draft constantly, so a rendered
     * decision is stale the moment another order takes the stock (#584's shape exactly), and a
     * second gate is a second place to be wrong about a rule TransferOrderService already owns.
     *
     * Four things say it did not happen:
     *
     *  1. the Dispatch button is still rendered on a draft carrying the notice — the template did
     *     not hide or disable the way through;
     *  2. the refusal that comes back is the MOVEMENT LAYER's sentence — `holds N unit(s); this
     *     movement asks for M`, which only StockMovementService::assertSourcesCanCover() produces —
     *     and is not the notice's own wording, which is what a template-side gate would have said;
     *  3. `transfer_order.status` and `transfer_order_line.quantity_dispatched` are read back out of
     *     the database and have not moved. "It refused" is not "nothing moved" (#594);
     *  4. and then the shelf is topped up and the SAME document is dispatched through the SAME
     *     button. It goes through. Nothing about the page changed except the stock behind it, which
     *     is what proves the first refusal was about the stock and never about the notice.
     */
    public function dispatchIsStillRefusedByTheDomainActionAndNotByTheNotice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->draftTransfer($I);
        $widget = $this->stockedProduct($I, $context, 'SF-GATE-' . strtoupper(substr(uniqid(), -5)), ['GATE-A' => 4]);

        $this->addLine($I, $context, [
            'product_id' => (string) $widget['id'],
            'quantity' => '10',
            'lot_id' => (string) $widget['lots']['GATE-A'],
        ]);

        $lineId = $this->lineIdBySku($I, $context['transferId'], $widget['sku']);

        $I->amOnPage($context['url']);
        $I->seeElement(self::NOTICE);
        $I->see($widget['sku'] . ' · lot GATE-A needs 10, 4 available (short 6)', self::NOTICE);
        // Stated, not forbidden: the way through is still on the page and still a plain form.
        $I->seeElement('form[action$="/dispatch"][method="post"] button[type="submit"]');
        $I->see('Stock may arrive before you dispatch', self::NOTICE);

        $token = (string) $I->grabAttributeFrom('form[action$="/dispatch"] input[name="_token"]', 'value');
        $I->sendFormPostRequest($context['url'] . '/dispatch', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        // The refusal is the domain's, word for word. `holds 4 unit(s); this movement asks for 10`
        // is InsufficientStockException out of assertSourcesCanCover(); nothing in the template can
        // produce that sentence.
        $I->see('holds 4 unit(s); this movement asks for 10', 'div.flash-error');
        // And it is NOT the notice talking. The selector is proven to match by the assertion above,
        // so this says something: the flash carries the movement layer's wording, not `short 6`.
        $I->dontSee('short 6', 'div.flash-error');

        $I->assertSame(
            'draft',
            $this->column($I, 'SELECT status FROM transfer_order WHERE id = ?', [$context['transferId']]),
            'transfer_order.status moved on a dispatch that was refused',
        );
        $I->assertSame(
            '0',
            $this->column($I, 'SELECT quantity_dispatched FROM transfer_order_line WHERE id = ?', [$lineId]),
            'transfer_order_line.quantity_dispatched — a refused dispatch takes nothing off the shelf',
        );

        // Now put the missing six on the shelf, under the batch the line names. Nothing about the
        // document or the screen changes.
        $em = $I->grabService('doctrine.orm.entity_manager');
        $this->putAway($I, $em->find(ProductCore::class, $widget['id']), $context, $em->find(InventoryLot::class, $widget['lots']['GATE-A']), null, 6);

        $I->amOnPage($context['url']);
        $I->dontSee($this->noticeOpening($context));
        $I->dontSeeElement(self::NOTICE);

        $token = (string) $I->grabAttributeFrom('form[action$="/dispatch"] input[name="_token"]', 'value');
        $I->sendFormPostRequest($context['url'] . '/dispatch', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            'dispatched',
            $this->column($I, 'SELECT status FROM transfer_order WHERE id = ?', [$context['transferId']]),
            'transfer_order.status — the same button on the same document, once the stock was there',
        );
        $I->assertSame(
            '10',
            $this->column($I, 'SELECT quantity_dispatched FROM transfer_order_line WHERE id = ?', [$lineId]),
            'transfer_order_line.quantity_dispatched',
        );
    }

    // ------------------------------------------------------------------ 6. the row that must not appear

    /**
     * The negative: a line that FITS is not named, on a document where another line does not.
     *
     * A notice that named every line would be no better than no notice — worse, because it would
     * send somebody to a row with nothing wrong with it. The order of the assertions is what makes
     * the absence mean anything (#627): the short SKU is asserted PRESENT inside `p.alert.warn`
     * first, which proves the selector matches a real element, and only then is the fitting SKU
     * asserted absent from that same element.
     *
     * The fitting line is pinned twice more: it is on the document as a row of its own, and its
     * per-row hint carries the plain `line-stock-hint` class rather than the `-over` variant — so
     * its absence from the notice is the notice being right, not the line having failed to save.
     */
    public function aLineThatFitsIsNotNamedInTheNotice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->draftTransfer($I);

        $short = $this->stockedProduct($I, $context, 'SF-NEG-SHORT-' . strtoupper(substr(uniqid(), -5)), ['NEG-A' => 4]);
        $fits = $this->stockedProduct($I, $context, 'SF-NEG-FITS-' . strtoupper(substr(uniqid(), -5)), ['NEG-B' => 40]);

        $this->addLine($I, $context, [
            'product_id' => (string) $short['id'],
            'quantity' => '10',
            'lot_id' => (string) $short['lots']['NEG-A'],
        ]);
        $this->addLine($I, $context, [
            'product_id' => (string) $fits['id'],
            'quantity' => '10',
            'lot_id' => (string) $fits['lots']['NEG-B'],
        ]);

        $fittingLineId = $this->lineIdBySku($I, $context['transferId'], $fits['sku']);
        $I->assertGreaterThan(0, $fittingLineId, 'the fitting line never reached transfer_order_line');

        $I->amOnPage($context['url']);
        $I->seeResponseCodeIsSuccessful();

        // Present first, so the selector is known to match something.
        $I->seeElement(self::NOTICE);
        $I->see($short['sku'] . ' · lot NEG-A needs 10, 4 available (short 6)', self::NOTICE);
        // Then absent, from that same element.
        $I->dontSee($fits['sku'], self::NOTICE);
        $I->dontSee('NEG-B', self::NOTICE);
        // Singular: the fitting line was not counted either, which a notice that named everything
        // and then filtered the list would still get wrong.
        $I->assertStringStartsWith(
            '1 line asks' . $this->noticeOpening($context),
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom(self::NOTICE))),
            'the notice counts the short lines; see() would have taken a leading digit too (#627)',
        );
        $I->seeNumberOfElements(self::NOTICE . ' strong', 1);

        // And the fitting line really is on the document — its own row, with the hint that says the
        // stock is there rather than the over-quantity variant.
        $I->seeElement('tr#line-' . $fittingLineId . ' input[name="quantity"][value="10"]');
        $I->assertSame(
            '40 available at ' . $context['sourceName'],
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('tr#line-' . $fittingLineId . ' small.line-stock-hint'))),
            'inventory_detail available at the source: 40, read exactly (#627)',
        );
        $I->dontSeeElement('tr#line-' . $fittingLineId . ' small.line-stock-hint-over');
    }
}
