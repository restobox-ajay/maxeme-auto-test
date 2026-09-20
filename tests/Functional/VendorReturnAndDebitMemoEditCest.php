<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Entity\DebitMemo;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorReturn;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Two claims about the same two documents, conducted per #624 through the real screens.
 *
 * 1. A DRAFT can be edited and the change is stored — both saves always had an update branch and
 *    nothing could reach it, because no edit route existed and both forms hard-coded `id=0`.
 * 2. The existing immutability SURVIVED. Every assertion that a draft is editable is paired with
 *    one that an issued memo and an authorised return still are not, by column: a fix that makes
 *    everything editable is worse than the bug it replaces.
 *
 * Plus the provenance columns the save now fills in — read back by `table.column`, on a document
 * created through the real form.
 */
final class VendorReturnAndDebitMemoEditCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('doc-edit-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A vendor, two products, a purchase order, and a goods receipt against it naming both — so a
     * return raised from the receipt has a PO to inherit and two receipt lines to tell apart.
     *
     * @return array{vendorId: int, orderId: int, receiptId: int, receiptLineAId: int, receiptLineBId: int, productAId: int, productBId: int}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Doc Edit Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Editable Supply ' . uniqid());
        $em->persist($vendor);

        $productA = (new ProductCore())->setSku('ED-A-' . random_int(1000, 9999))->setName('Editable Widget A')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $productB = (new ProductCore())->setSku('ED-B-' . random_int(1000, 9999))->setName('Editable Widget B')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($productA);
        $em->persist($productB);
        $em->flush();

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-ED-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($warehouse);
        $em->persist($order);
        $em->flush();

        $receipt = (new GoodsReceipt())
            ->setReceiptNumber('RC-ED-' . random_int(1000, 9999))
            ->setVendor($vendor)
            ->setWarehouse($warehouse)
            ->setPurchaseOrder($order);
        $em->persist($receipt);

        $lineA = (new GoodsReceiptLine())->setProduct($productA)->setName($productA->getName())->setSku($productA->getSku())->setQuantity('10.00');
        $lineB = (new GoodsReceiptLine())->setProduct($productB)->setName($productB->getName())->setSku($productB->getSku())->setQuantity('10.00');
        $receipt->addLine($lineA);
        $receipt->addLine($lineB);
        $em->persist($lineA);
        $em->persist($lineB);
        $em->flush();

        return [
            'vendorId' => (int) $vendor->getId(),
            'orderId' => (int) $order->getId(),
            'receiptId' => (int) $receipt->getId(),
            'receiptLineAId' => (int) $lineA->getId(),
            'receiptLineBId' => (int) $lineB->getId(),
            'productAId' => (int) $productA->getId(),
            'productBId' => (int) $productB->getId(),
        ];
    }

    /**
     * Raise a return from the receipt, then EDIT it. The wrong quantity and the wrong product are
     * corrected in place rather than by abandoning the document and retyping it.
     */
    public function aRequestedVendorReturnCanBeEditedAndTheChangeIsStored(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);

        // --- Raise it, from the receipt-seeded form -------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new?receipt=' . $context['receiptId']);
        $I->seeResponseCodeIsSuccessful();
        // The receipt line's own id is on the form now. It always had the row in its hand.
        $I->seeElement('input[type="hidden"][name="lines[0][goods_receipt_line_id]"]');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'goods_receipt_id' => (string) $context['receiptId'],
            'reason' => 'Typed in a hurry.',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productAId'],
                    'goods_receipt_line_id' => (string) $context['receiptLineAId'],
                    'name' => 'Editable Widget A',
                    'sku' => 'ED-A',
                    'quantity' => '3.00',
                    'reason' => 'Overstock',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $vendorReturn = $em->getRepository(VendorReturn::class)->findOneBy(['goodsReceipt' => $context['receiptId']]);
        $I->assertNotNull($vendorReturn, 'the save created the return');
        $returnId = (int) $vendorReturn->getId();

        // --- Provenance, by column, on a document made through the real screen -----------------------
        $stored = $em->getConnection()->fetchAssociative('SELECT purchase_order_id, goods_receipt_id FROM vendor_return WHERE id = ?', [$returnId]);
        $I->assertSame($context['orderId'], (int) $stored['purchase_order_id'], 'vendor_return.purchase_order_id carries the order the goods were bought on');
        $I->assertSame($context['receiptId'], (int) $stored['goods_receipt_id'], 'and the receipt it was raised against, as it always did');

        $storedLine = $em->getConnection()->fetchAssociative('SELECT goods_receipt_line_id, quantity FROM vendor_return_line WHERE vendor_return_id = ?', [$returnId]);
        $I->assertSame($context['receiptLineAId'], (int) $storedLine['goods_receipt_line_id'], 'vendor_return_line.goods_receipt_line_id names the delivery row these units came off');
        $I->assertSame('3.00', number_format((float) $storedLine['quantity'], 2, '.', ''), 'guard: the quantity as first typed');

        // --- Now edit it -----------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId);
        $I->seeElement(sprintf('a[href$="/vendor-returns/%d/edit"]', $returnId));

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        // The form posts the document's id, which is the whole of the defect: it used to post 0.
        $I->assertSame((string) $returnId, $I->grabAttributeFrom('form input[name="id"]', 'value'), 'the edit form posts the return it is editing');
        // Compared as a decimal rather than as a string: SQLite's NUMERIC affinity hands '3.00'
        // back as the number 3, so the field carries '3' and the assertion is about the figure.
        $I->assertSame('3.00', number_format((float) $I->grabValueFrom('input[name="lines[0][quantity]"]'), 2, '.', ''),
            'the edit form arrives carrying the quantity the document already says');

        $editToken = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $editToken,
            'id' => (string) $returnId,
            'vendor_id' => (string) $context['vendorId'],
            'goods_receipt_id' => (string) $context['receiptId'],
            'reason' => 'Corrected.',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productBId'],
                    'goods_receipt_line_id' => (string) $context['receiptLineBId'],
                    'name' => 'Editable Widget B',
                    'sku' => 'ED-B',
                    'quantity' => '7.00',
                    'reason' => 'Wrong item shipped',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // --- The change is stored, on the SAME document ------------------------------------------------
        $em->clear();
        $I->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM vendor_return WHERE goods_receipt_id = ?', [$context['receiptId']]),
            'the edit updated the document instead of creating a second one, which is exactly what a posted id=0 used to do');

        $after = $em->getConnection()->fetchAssociative('SELECT reason FROM vendor_return WHERE id = ?', [$returnId]);
        $I->assertSame('Corrected.', (string) $after['reason']);

        $afterLine = $em->getConnection()->fetchAssociative('SELECT product_id, goods_receipt_line_id, quantity, name FROM vendor_return_line WHERE vendor_return_id = ?', [$returnId]);
        $I->assertSame($context['productBId'], (int) $afterLine['product_id'], 'the wrong product was corrected in place');
        $I->assertSame($context['receiptLineBId'], (int) $afterLine['goods_receipt_line_id'], 'and the receipt-line link moved with it');
        $I->assertSame('7.00', number_format((float) $afterLine['quantity'], 2, '.', ''), 'and so did the quantity');

        // --- The row that must NOT have changed: the receipt the return was raised from -----------------
        $receiptLineB = $em->getConnection()->fetchAssociative('SELECT quantity FROM goods_receipt_line WHERE id = ?', [$context['receiptLineBId']]);
        $I->assertSame('10.00', number_format((float) $receiptLineB['quantity'], 2, '.', ''), 'editing the return did not touch what the delivery says arrived');
    }

    /**
     * The immutability that already existed, proved to have survived: an AUTHORISED return refuses
     * the edit screen AND refuses a hand-rolled post to the save, and its stored row is unchanged
     * by the attempt.
     */
    public function anAuthorisedVendorReturnStillCannotBeEdited(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/new?receipt=' . $context['receiptId']);
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'goods_receipt_id' => (string) $context['receiptId'],
            'reason' => 'Agreed with the vendor.',
            'lines' => [
                0 => [
                    'product_id' => (string) $context['productAId'],
                    'goods_receipt_line_id' => (string) $context['receiptLineAId'],
                    'name' => 'Editable Widget A',
                    'quantity' => '5.00',
                ],
            ],
        ]);
        $returnId = (int) $em->getRepository(VendorReturn::class)->findOneBy(['goodsReceipt' => $context['receiptId']])->getId();

        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId);
        $authToken = $I->grabAttributeFrom('form[action$="/action/authorise"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/' . $returnId . '/action/authorise', ['_token' => $authToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Authorised', (string) $em->getConnection()->fetchOne('SELECT status FROM vendor_return WHERE id = ?', [$returnId]), 'guard: it really is authorised');

        // The screen no longer offers the door.
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId);
        $I->dontSeeElement(sprintf('a[href$="/vendor-returns/%d/edit"]', $returnId));

        // Nor does walking straight to it: the edit route refuses before anything is typed.
        $I->amOnPage('/admin/bundles/procurement/vendor-returns/' . $returnId . '/edit');
        $I->seeCurrentUrlEquals('/admin/bundles/procurement/vendor-returns/' . $returnId);

        // And the save still refuses the post itself, which is where it always refused.
        $postToken = $I->grabAttributeFrom('form[action$="/ship"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-returns/save', [
            '_token' => $postToken,
            'id' => (string) $returnId,
            'vendor_id' => (string) $context['vendorId'],
            'goods_receipt_id' => (string) $context['receiptId'],
            'reason' => 'Rewriting an agreement nobody re-agreed to.',
            'lines' => [
                0 => ['product_id' => (string) $context['productBId'], 'name' => 'Editable Widget B', 'quantity' => '99.00'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // --- The row that must NOT have changed: the authorised return, by column -------------------
        $em->clear();
        $after = $em->getConnection()->fetchAssociative('SELECT reason, status FROM vendor_return WHERE id = ?', [$returnId]);
        $I->assertSame('Agreed with the vendor.', (string) $after['reason'], 'the refused post left the reason exactly as it was');
        $I->assertSame('Authorised', (string) $after['status']);

        $line = $em->getConnection()->fetchAssociative('SELECT product_id, quantity FROM vendor_return_line WHERE vendor_return_id = ?', [$returnId]);
        $I->assertSame($context['productAId'], (int) $line['product_id'], 'and the line still names the product the vendor agreed to take');
        $I->assertSame('5.00', number_format((float) $line['quantity'], 2, '.', ''), 'at the quantity the vendor agreed to');
        $I->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM vendor_return_line WHERE vendor_return_id = ?', [$returnId]), 'and no line was added by the refusal');
    }

    /**
     * The same pair on the debit memo, plus the two bill-provenance columns and the refund's user —
     * all four of them written for the first time by this change.
     */
    public function aDraftDebitMemoCanBeEditedAndAnIssuedOneStillCannot(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $em = $I->grabService('doctrine.orm.entity_manager');
        $context = $this->seed($I);

        $vendor = $em->getRepository(Vendor::class)->find($context['vendorId']);
        $bill = (new VendorBill())->setBillNumber('BILL-ED-' . random_int(1000, 9999))->setVendor($vendor)->setVendorName($vendor->getName())->setTotal('500.00');
        $em->persist($bill);
        $billLine = (new VendorBillLine())
            ->setProduct($em->getRepository(ProductCore::class)->find($context['productAId']))
            ->setName('Editable Widget A')
            ->setSku('ED-A')
            ->setQuantity('10.00')
            ->setUnitCost('5.0000')
            ->setSubtotal('50.00');
        $bill->addLine($billLine);
        $em->persist($billLine);
        $em->flush();
        $billId = (int) $bill->getId();
        $billLineId = (int) $billLine->getId();

        // --- Raise it FROM the bill, the entry point the bill screen now offers ---------------------
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
        $I->seeElement(sprintf('a[href$="/debit-memos/new?bill=%d"]', $billId));

        $I->amOnPage('/admin/bundles/procurement/debit-memos/new?bill=' . $billId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="hidden"][name="lines[0][vendor_bill_line_id]"]');
        $token = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $context['vendorId'],
            'vendor_bill_id' => (string) $billId,
            'reason' => 'Typed in a hurry.',
            'lines' => [
                0 => [
                    'vendor_bill_line_id' => (string) $billLineId,
                    'name' => 'Editable Widget A',
                    'sku' => 'ED-A',
                    'quantity' => '2.00',
                    'unit_cost' => '5.0000',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $memoId = (int) $em->getRepository(DebitMemo::class)->findOneBy(['vendorBill' => $billId])->getId();

        // --- Provenance, by column --------------------------------------------------------------------
        $I->assertSame($billId, (int) $em->getConnection()->fetchOne('SELECT vendor_bill_id FROM debit_memo WHERE id = ?', [$memoId]),
            'debit_memo.vendor_bill_id records the bill this memo was raised from');
        $storedLine = $em->getConnection()->fetchAssociative('SELECT vendor_bill_line_id, product_id, quantity FROM debit_memo_line WHERE debit_memo_id = ?', [$memoId]);
        $I->assertSame($billLineId, (int) $storedLine['vendor_bill_line_id'], 'debit_memo_line.vendor_bill_line_id records the billed row it disputes');
        $I->assertSame($context['productAId'], (int) $storedLine['product_id'], 'and the product came off that bill line, exactly as the sell side takes it off the invoice line');
        $I->assertSame('2.00', number_format((float) $storedLine['quantity'], 2, '.', ''), 'guard: the quantity as first typed');

        // --- Edit the draft ----------------------------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->seeElement(sprintf('a[href$="/debit-memos/%d/edit"]', $memoId));

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame((string) $memoId, $I->grabAttributeFrom('form input[name="id"]', 'value'), 'the edit form posts the memo it is editing');
        $I->assertSame('2.00', number_format((float) $I->grabValueFrom('input[name="lines[0][quantity]"]'), 2, '.', ''),
            'the edit form arrives carrying the quantity the document already says');

        $editToken = $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', [
            '_token' => $editToken,
            'id' => (string) $memoId,
            'vendor_id' => (string) $context['vendorId'],
            'vendor_bill_id' => (string) $billId,
            'reason' => 'Corrected.',
            'lines' => [
                0 => [
                    'vendor_bill_line_id' => (string) $billLineId,
                    'name' => 'Editable Widget A',
                    'sku' => 'ED-A',
                    'quantity' => '6.00',
                    'unit_cost' => '5.0000',
                ],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM debit_memo WHERE vendor_bill_id = ?', [$billId]),
            'the edit updated the memo rather than raising a second one');
        $afterLine = $em->getConnection()->fetchAssociative('SELECT quantity, subtotal FROM debit_memo_line WHERE debit_memo_id = ?', [$memoId]);
        $I->assertSame('6.00', number_format((float) $afterLine['quantity'], 2, '.', ''), 'the corrected quantity is stored');
        $I->assertSame('30.00', number_format((float) $afterLine['subtotal'], 2, '.', ''), 'and the line was re-totalled from it');
        $I->assertSame('30.00', number_format((float) $em->getConnection()->fetchOne('SELECT total FROM debit_memo WHERE id = ?', [$memoId]), 2, '.', ''),
            'and so was the document');

        // --- The row that must NOT have changed: the bill the memo was raised from -----------------------
        $billAfter = $em->getConnection()->fetchAssociative('SELECT total, status FROM vendor_bill WHERE id = ?', [$billId]);
        $I->assertSame('500.00', number_format((float) $billAfter['total'], 2, '.', ''), 'editing the memo did not touch the bill it disputes');
        $billLineAfter = $em->getConnection()->fetchAssociative('SELECT quantity, subtotal FROM vendor_bill_line WHERE id = ?', [$billLineId]);
        $I->assertSame('10.00', number_format((float) $billLineAfter['quantity'], 2, '.', ''), 'nor the billed row it names');
        $I->assertSame('50.00', number_format((float) $billLineAfter['subtotal'], 2, '.', ''));

        // --- Issue it, then prove the immutability survived -----------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $issueToken = $I->grabAttributeFrom('form[action$="/action/issue"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/action/issue', ['_token' => $issueToken]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $I->assertSame('Open', (string) $em->getConnection()->fetchOne('SELECT status FROM debit_memo WHERE id = ?', [$memoId]), 'guard: it really is issued');

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $I->dontSeeElement(sprintf('a[href$="/debit-memos/%d/edit"]', $memoId));

        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId . '/edit');
        $I->seeCurrentUrlEquals('/admin/bundles/procurement/debit-memos/' . $memoId);

        $refundToken = $I->grabAttributeFrom('form[action$="/refund"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/save', [
            '_token' => $refundToken,
            'id' => (string) $memoId,
            'vendor_id' => (string) $context['vendorId'],
            'vendor_bill_id' => (string) $billId,
            'reason' => 'Rewriting a statement already made to the vendor.',
            'lines' => [
                0 => ['name' => 'Something else entirely', 'quantity' => '99.00', 'unit_cost' => '99.0000'],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $memoAfter = $em->getConnection()->fetchAssociative('SELECT reason, total, status FROM debit_memo WHERE id = ?', [$memoId]);
        $I->assertSame('Corrected.', (string) $memoAfter['reason'], 'the refused post left the issued memo exactly as it was');
        $I->assertSame('30.00', number_format((float) $memoAfter['total'], 2, '.', ''));
        $I->assertSame('Open', (string) $memoAfter['status']);
        $I->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM debit_memo_line WHERE debit_memo_id = ?', [$memoId]), 'and added no line');
        $I->assertSame('6.00', number_format((float) $em->getConnection()->fetchOne('SELECT quantity FROM debit_memo_line WHERE debit_memo_id = ?', [$memoId]), 2, '.', ''));

        // --- And the refund records who paid it out -------------------------------------------------------
        $I->amOnPage('/admin/bundles/procurement/debit-memos/' . $memoId);
        $realRefundToken = $I->grabAttributeFrom('form[action$="/refund"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundles/procurement/debit-memos/' . $memoId . '/refund', [
            '_token' => $realRefundToken,
            'method' => 'Bank Transfer',
            'amount' => '10.00',
            'refunded_at' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'comment' => 'Wire received.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->clear();
        $refund = $em->getConnection()->fetchAssociative('SELECT user_id, amount FROM debit_memo_refund WHERE debit_memo_id = ?', [$memoId]);
        $I->assertNotFalse($refund, 'the refund landed');
        $I->assertSame('10.00', number_format((float) $refund['amount'], 2, '.', ''));
        $I->assertNotNull($refund['user_id'], 'debit_memo_refund.user_id names the admin who recorded it — the column the buy side never filled in');
        $signedInEmail = (string) $em->getConnection()->fetchOne('SELECT email FROM admin_user WHERE id = ?', [(int) $refund['user_id']]);
        $I->assertStringStartsWith('doc-edit-', $signedInEmail, 'and it is the admin who was actually signed in, not merely any admin');
    }
}
