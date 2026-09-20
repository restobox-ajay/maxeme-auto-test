<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Queue item 56 / GitHub #662: the RFQ comes off the menu and nothing else moves.
 *
 * It is hidden rather than finished because `RfqConversionService::convert()` awards one whole
 * RFQ to one `RfqVendorReply` and refuses it unless `isFullyPriced()` — one vendor wins everything,
 * and a vendor quoting only the lines it stocks cannot win at all. NetSuite and Zoho both award per
 * LINE, several vendors able to win different lines of one tender, so this is not a small gap and
 * the owner parked it.
 *
 * That makes two halves to prove, and one without the other is worth nothing:
 *
 *  1. There is no way INTO an RFQ from the menu — asserted by enumerating the hrefs the sidebar
 *     actually rendered, so a second entry point added later fails this rather than sliding past a
 *     pair of named URLs. Every absence here is paired with a sibling asserted present in the same
 *     breath (#627): on an empty nav, "no RFQ link" passes and means nothing.
 *  2. An RFQ that already exists is still worked through its own URL. Somebody mid-tender does not
 *     lose a live tender because a menu was tidied, so the screens are driven for real — plain form
 *     POSTs with the CSRF scraped off the page, no `X-Requested-With` (#624) — and the result is
 *     re-read from the database rather than believed because a redirect was a 200.
 */
final class RfqIsOffTheMenuCest
{
    /** The path every RFQ screen hangs off. Nothing in the sidebar may lead here. */
    private const RFQ_PATH = '/admin/bundles/procurement/rfqs';

    /**
     * AppSettings caches its rows in a pool that lives OUTSIDE the per-test transaction, so a
     * snapshot taken here would survive the rollback and be read by whatever runs next. The
     * document numbers this test allocates read their prefix through that cache.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('menu-parked-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** The rendered `<nav>`, not the page: "RFQ" is a word that appears in settings labels too. */
    private function navFragment(FunctionalTester $I): string
    {
        preg_match('/<nav id="primary-navigation".*?<\/nav>/s', $I->grabPageSource(), $matches);
        $nav = $matches[0] ?? '';
        $I->assertNotSame('', $nav, 'no sidebar rendered at all, so nothing below is testing anything');

        return $nav;
    }

    /**
     * Both halves against the sidebar of whatever page is currently open: every href it rendered,
     * enumerated, with none of them leading into an RFQ — and the neighbouring Purchases rows
     * asserted present from the SAME list, which is what makes the absence mean something.
     */
    private function seeTheSidebarOffersNoRouteIntoAnRfq(FunctionalTester $I): void
    {
        $nav = $this->navFragment($I);
        $hrefs = $I->grabMultiple('nav#primary-navigation a', 'href');

        // The positive control first, so a nav that rendered nothing useful cannot pass below.
        $I->assertContains('/admin/bundles/procurement/purchase-orders', $hrefs, 'the Purchases group still lists Purchase Orders');
        $I->assertContains('/admin/bundles/procurement/bills', $hrefs, 'the Purchases group still lists Bills');
        $I->assertContains('/admin/bundles/procurement/vendor-returns', $hrefs, 'the Purchases group still lists Vendor Returns');
        $I->assertContains('/admin/bundles/procurement/vendors', $hrefs, 'the Vendors group is still there');
        $I->assertStringContainsString('<span class="nav-label">Purchases</span>', $nav, 'the group the RFQ row sat in is still there');

        // And then the absence, enumerated rather than named: ANY sidebar link into the RFQ fails
        // this, including one nobody thought to list here.
        $intoAnRfq = array_values(array_filter(
            $hrefs,
            static fn (string $href): bool => str_starts_with($href, self::RFQ_PATH) || str_starts_with($href, '/admin/bundles/procurement/rfq-replies'),
        ));
        $I->assertSame([], $intoAnRfq, 'the sidebar leads into an RFQ: ' . implode(', ', $intoAnRfq));
        $I->assertStringNotContainsString('<span class="nav-label">RFQs</span>', $nav, 'the RFQ row is still drawn in the sidebar');
    }

    /** @return array{0: int, 1: int, 2: int} warehouseId, vendorId, productId */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Hidden RFQ Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Parked Tender Vendor ' . uniqid());
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('HIDDEN-RFQ-' . random_int(1000, 9999))
            ->setName('Parked Tender Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return [(int) $warehouse->getId(), (int) $vendor->getId(), (int) $product->getId()];
    }

    /** Half one, on the screen an admin lands on. */
    public function theAdminSidebarOffersNoWayIntoAnRfqAndKeepsEveryNeighbouringRow(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();

        $this->seeTheSidebarOffersNoRouteIntoAnRfq($I);
    }

    /**
     * The bundle's own landing page is the route the sidebar item and the App Management descriptor
     * both resolve to, and it reports on the buy side — so it is the obvious place for a count of
     * open tenders to have crept in. It never had one, and hiding the row must not have added one:
     * the four figures it does report are asserted present by their labels, which is also the
     * positive control for the absence.
     */
    public function theProcurementLandingPageStillReportsTheSameFourThingsAndCountsNoRfqs(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $I->amOnPage('/admin/bundles/procurement');
        $I->seeResponseCodeIsSuccessful();

        // Scoped to the panel that carries the figures, not the page: the signed-in admin's own
        // email address sits in the user menu, and a page-wide word search reads that too.
        $I->see('active vendor(s)', '.stat-row');
        $I->see('purchase order(s) still expecting goods', '.stat-row');
        $I->see('receipt(s) recorded', '.stat-row');
        $I->see('bill(s) payable', '.stat-row');
        $I->dontSee('RFQ', '.stat-row');
        $I->dontSee('tender', '.stat-row');

        $this->seeTheSidebarOffersNoRouteIntoAnRfq($I);
    }

    /**
     * Half two, conducted. Raise a requirement and tender it to a vendor through the real screens,
     * then reach every one of those screens again by URL alone — which is the promise this change
     * made to anybody who already had a tender out when the row disappeared.
     *
     * The row that should NOT have changed is asserted throughout: tendering must not invent a
     * purchase order (that only happens when a reply is accepted), and the requirement's own line
     * must still name the same product at the same quantity after the send.
     */
    public function anRfqAlreadyRaisedIsStillWorkedThroughItsOwnUrlAfterTheRowIsGone(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        [$warehouseId, $vendorId, $productId] = $this->seed($I);

        $notes = 'Tender live when the menu row went (' . uniqid() . ').';

        // --- Raise it on the real screen -----------------------------------------------------
        $I->amOnPage(self::RFQ_PATH . '/new');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest(self::RFQ_PATH . '/save', [
            '_token' => $token,
            'id' => '0',
            'warehouse_id' => (string) $warehouseId,
            'notes' => $notes,
            'lines' => [
                0 => ['product_id' => (string) $productId, 'name' => 'Parked Tender Widget', 'sku' => 'HIDDEN-RFQ', 'quantity' => '7.00'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        /** @var Rfq|null $rfq */
        $rfq = $em->getRepository(Rfq::class)->findOneBy(['notes' => $notes]);
        $I->assertNotNull($rfq, 'the save form actually created an RFQ row');
        $rfqId = (int) $rfq->getId();
        $documentNumber = (string) $rfq->getDocumentNumber();
        $I->assertNotSame('', $documentNumber, 'the RFQ was numbered');
        // What the requirement said the moment it was raised, to compare against after tendering
        // rather than against a literal this test would have to guess the storage format of.
        $quantityAsRaised = (string) $rfq->getLines()->first()->getQuantity();
        $ordersBefore = $em->getRepository(PurchaseOrder::class)->count([]);

        // --- Its own URL still answers, and still says which RFQ it is ------------------------
        $I->amOnPage(self::RFQ_PATH . '/' . $rfqId);
        $I->seeResponseCodeIsSuccessful();
        $I->see($documentNumber);
        $I->see($notes);
        // The screen is whole, not merely a 200: the tender action is on it.
        $I->seeElement('form[action$="/send"]');
        // ...and the sidebar around it still has no way back into an RFQ, with its neighbours there.
        $this->seeTheSidebarOffersNoRouteIntoAnRfq($I);

        // --- Tender it, through the screen ----------------------------------------------------
        $sendToken = $I->grabAttributeFrom('form[action$="/send"] input[name="_token"]', 'value');
        $I->sendFormPostRequest(self::RFQ_PATH . '/' . $rfqId . '/send', [
            '_token' => $sendToken,
            'vendor_ids' => [(string) $vendorId],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Re-read, not believed: the status moved and exactly the vendor asked got a reply row.
        $em->clear();
        /** @var Rfq $reread */
        $reread = $em->getRepository(Rfq::class)->find($rfqId);
        $I->assertSame('Sent', $reread->getStatus()->value, 'the RFQ was tendered');

        $replies = $em->getRepository(RfqVendorReply::class)->findBy(['rfq' => $rfqId]);
        $I->assertCount(1, $replies, 'one vendor was asked, so one reply row exists');
        $I->assertSame($vendorId, (int) $replies[0]->getVendor()->getId(), 'the reply belongs to the vendor that was asked');

        // The half that should NOT have changed. Tendering asks for prices; it does not buy.
        $I->assertSame(
            $ordersBefore,
            $em->getRepository(PurchaseOrder::class)->count([]),
            'tendering an RFQ raised a purchase order, which only accepting a reply may do',
        );
        $line = $reread->getLines()->first();
        $I->assertSame($productId, (int) $line->getProduct()->getId(), 'the requirement still names the product it was raised for');
        $I->assertSame($quantityAsRaised, (string) $line->getQuantity(), 'the requirement still asks for the quantity it was raised with');

        // --- Every screen behind the hidden row still resolves by URL --------------------------
        $replyId = (int) $replies[0]->getId();
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyId);
        $I->seeResponseCodeIsSuccessful();
        $I->see($documentNumber, 'a[href="' . self::RFQ_PATH . '/' . $rfqId . '"]');

        // The list, too: hidden from the menu is not removed from the application, and this is
        // where somebody who was working a batch of tenders finds the rest of them.
        $I->amOnPage(self::RFQ_PATH);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a', ['href' => self::RFQ_PATH . '/' . $rfqId]);
        $this->seeTheSidebarOffersNoRouteIntoAnRfq($I);
    }
}
