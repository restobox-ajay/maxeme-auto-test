<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Entity\Rfq;
use ProcurementBundle\Entity\RfqVendorReply;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * RFQ -> PurchaseOrder (#637), driven through the real admin screens per #624: one requirement,
 * tendered to two vendors, one reply accepted — and the row #637's own conducted-test requirement
 * names explicitly, "the vendor whose quote was NOT taken", asserted alongside the winner.
 *
 * Every request here is a plain form POST with a scraped CSRF token, no `X-Requested-With` — what a
 * browser with scripting off sends — which is also what keeps the no-JS guarantee honest for free.
 */
final class RfqConversionCest
{
    /**
     * AppSettings caches its rows in a pool that lives OUTSIDE the per-test transaction, so a
     * snapshot taken here survives the rollback and is read by whatever runs next. Every document
     * this test raises allocates a number through PurchaseDocumentNumberGenerator, which reads its
     * prefix through that cache — so this clears it for the same reason ProcurementScreensCest does.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('rfq-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} warehouseId, vendorAId, vendorBId, productId */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('RFQ Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendorA = (new Vendor())->setName('Quote Vendor A ' . uniqid());
        $vendorB = (new Vendor())->setName('Quote Vendor B ' . uniqid());
        $em->persist($vendorA);
        $em->persist($vendorB);

        $product = (new ProductCore())
            ->setSku('RFQ-WIDGET-' . random_int(1000, 9999))
            ->setName('RFQ Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return [(int) $warehouse->getId(), (int) $vendorA->getId(), (int) $vendorB->getId(), (int) $product->getId()];
    }

    /**
     * Raise an RFQ, tender it to two vendors, record two competing quotes, accept the cheaper one —
     * and assert not only the winning purchase order but the reply that lost.
     */
    public function acceptingTheCheaperQuoteRaisesAPurchaseOrderAndRejectsTheOtherReply(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        [$warehouseId, $vendorAId, $vendorBId, $productId] = $this->seed($I);

        // --- Raise the RFQ -------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/rfqs/new');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/save', [
            '_token' => $token,
            'id' => '0',
            'warehouse_id' => (string) $warehouseId,
            'notes' => 'Conducted test RFQ.',
            'lines' => [
                0 => ['product_id' => (string) $productId, 'name' => 'RFQ Widget', 'sku' => 'RFQ-WIDGET', 'quantity' => '10.00'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        /** @var Rfq $rfq */
        $rfq = $em->getRepository(Rfq::class)->findOneBy(['notes' => 'Conducted test RFQ.']);
        $I->assertNotNull($rfq, 'the save form actually created an RFQ');
        $rfqId = (int) $rfq->getId();
        $requirementLine = $rfq->getLines()->first();
        $requirementLineId = (int) $requirementLine->getId();

        // --- Tender it to both vendors --------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/rfqs/' . $rfqId);
        $I->seeResponseCodeIsSuccessful();
        $sendToken = $I->grabAttributeFrom('form[action$="/send"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/' . $rfqId . '/send', [
            '_token' => $sendToken,
            'vendor_ids' => [(string) $vendorAId, (string) $vendorBId],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $replyA = $em->getRepository(RfqVendorReply::class)->findOneBy(['rfq' => $rfqId, 'vendor' => $vendorAId]);
        $replyB = $em->getRepository(RfqVendorReply::class)->findOneBy(['rfq' => $rfqId, 'vendor' => $vendorBId]);
        $I->assertNotNull($replyA, 'sending created a reply for vendor A');
        $I->assertNotNull($replyB, 'sending created a reply for vendor B');
        $replyAId = (int) $replyA->getId();
        $replyBId = (int) $replyB->getId();

        // --- Record two competing quotes: A is cheaper -----------------------------------------
        // Each vendor's prices go in on that vendor's OWN document screen. They used to be typed
        // into a form nested in a cell of the RFQ's replies table, one per reply; the reply is a
        // CommercialDocument with a number, a total and its own detail/edit/print screens now, and
        // this is the screen an admin actually uses.
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyAId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $priceTokenA = $I->grabAttributeFrom('form#reply-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfq-replies/' . $replyAId . '/save', [
            '_token' => $priceTokenA,
            'document_date' => '2026-09-11',
            'currency' => 'CAD',
            'unit_cost' => [(string) $requirementLineId => '4.0000'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyBId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $priceTokenB = $I->grabAttributeFrom('form#reply-form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfq-replies/' . $replyBId . '/save', [
            '_token' => $priceTokenB,
            'document_date' => '2026-09-11',
            'currency' => 'CAD',
            'unit_cost' => [(string) $requirementLineId => '6.5000'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $replyA = $em->getRepository(RfqVendorReply::class)->find($replyAId);
        $replyB = $em->getRepository(RfqVendorReply::class)->find($replyBId);
        $I->assertSame('Replied', $replyA->getStatus()->value, 'vendor A\'s quote was recorded');
        $I->assertSame('Replied', $replyB->getStatus()->value, 'vendor B\'s quote was recorded too — both stand at once, which is the whole point of a tender');

        // --- Accept the cheaper quote ------------------------------------------------------------
        // From the winning quote's own screen, where the prices being accepted are on the page. The
        // action still belongs to RfqController: accepting writes the RFQ header and every other
        // reply on it, so it is not one document's business.
        $I->amOnPage('/admin/bundles/procurement/rfq-replies/' . $replyAId);
        $I->seeResponseCodeIsSuccessful();
        $convertToken = $I->grabAttributeFrom('form[action$="/reply/' . $replyAId . '/convert"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/rfqs/' . $rfqId . '/reply/' . $replyAId . '/convert', ['_token' => $convertToken]);
        $I->seeResponseCodeIsSuccessful();

        // --- Assert the winner: a real purchase order, with the right line -----------------------
        $em->clear();
        $winningReply = $em->getRepository(RfqVendorReply::class)->find($replyAId);
        $I->assertSame('Accepted', $winningReply->getStatus()->value, 'the accepted reply is marked so');
        $I->assertNotNull($winningReply->getPurchaseOrder(), 'and points at the purchase order it became');

        $poId = (int) $winningReply->getPurchaseOrder()->getId();
        $po = $em->getConnection()->fetchAssociative('SELECT po_number, vendor_id, warehouse_id, status, total FROM purchase_order WHERE id = ?', [$poId]);
        $I->assertNotFalse($po, 'the purchase order row actually exists');
        $I->assertSame($vendorAId, (int) $po['vendor_id'], 'raised against the vendor whose quote won');
        $I->assertSame($warehouseId, (int) $po['warehouse_id'], 'and against the RFQ\'s own destination warehouse');
        $I->assertSame('Draft', $po['status'], 'left as a draft for review — acceptance never auto-issues to the vendor');
        // SQLite's NUMERIC affinity hands a raw scalar fetch back as an int/float, not the decimal
        // string Doctrine's type conversion would give. Formatted rather than compared as a string
        // dump.
        $I->assertSame('40.00', number_format((float) $po['total'], 2, '.', ''), '10 units at $4.00');

        $poLine = $em->getConnection()->fetchAssociative(
            'SELECT quantity_ordered, unit_cost, product_id FROM purchase_order_line WHERE purchase_order_id = ?',
            [$poId],
        );
        $I->assertNotFalse($poLine, 'the line copied across');
        $I->assertSame('10.00', number_format((float) $poLine['quantity_ordered'], 2, '.', ''));
        $I->assertSame('4.0000', number_format((float) $poLine['unit_cost'], 4, '.', ''));
        $I->assertSame($productId, (int) $poLine['product_id']);

        // --- The negative: the reply that was NOT taken -------------------------------------------
        $losingReply = $em->getRepository(RfqVendorReply::class)->find($replyBId);
        $I->assertSame('Rejected', $losingReply->getStatus()->value, 'vendor B\'s quote is explicitly marked as not taken');
        $I->assertNull($losingReply->getPurchaseOrder(), 'and never became a purchase order of its own');

        $rejectedCount = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM purchase_order WHERE vendor_id = ?', [$vendorBId]);
        $I->assertSame(0, $rejectedCount, 'no purchase order was ever raised against the losing vendor');

        $rfq = $em->getRepository(Rfq::class)->find($rfqId);
        $I->assertSame('Accepted', $rfq->getStatus()->value, 'the RFQ itself is closed out by the acceptance');
    }
}
