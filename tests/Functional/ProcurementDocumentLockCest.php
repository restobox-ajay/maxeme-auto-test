<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Exception\DocumentLocked;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Lock/Unlock for the two buy-side documents (#759) — the same conducted shape
 * `DocumentCloneAndLockCest` already proves for the three sell-side ones.
 *
 * Every case drives the REAL routes with plain form POSTs carrying a CSRF token, creates its own
 * data, and reads `purchase_order`/`vendor_bill`, their line tables and `document_lock` back out of
 * SQLite by column. A locked document's write is proved refused three ways, deliberately, because
 * they are different claims:
 *
 * - Through a real named action (`cancel`/`dispute`) that never calls `assertWritable()` itself —
 *   `act()` merely catches `\DomainException`, and `DocumentLocked` is one. This is
 *   `DocumentLockFlushGuard` alone, reached the way a route written next month would reach it: no
 *   lock-specific code runs before the flush that refuses it.
 * - Through the edit screen's GET, which DOES call `assertWritable()` explicitly (#759) — proves
 *   that controller-level check, the "nicer screen" optimisation `DocumentLockService`'s own
 *   docblock describes.
 * - Through a direct `EntityManager::flush()` with no controller involved at all — the same
 *   `DocumentLockFlushGuard`, exercised on a line rather than the header, proving
 *   `LockableDocument::$owners` resolves `PurchaseOrderLine`/`VendorBillLine` back to their
 *   document. Nothing an enumerated list of controller checks could ever prove, which is the whole
 *   reason that guard exists (see its own docblock: "an unenumerated write path is an unlocked
 *   document").
 *
 * Each case also asserts a SECOND, untouched document of the same type is unaffected — the cheap
 * half #624 names as what would alone have caught several real integrity bugs.
 */
final class ProcurementDocumentLockCest
{
    private function actAsSuperAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('po-lock-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']); // inherits ROLE_SUPER_ADMIN
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    private function actAsPlainAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('po-lock-plain-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        return $I->grabService('doctrine.orm.entity_manager');
    }

    private function post(FunctionalTester $I, string $refererListPage, string $uri, array $params = []): void
    {
        $I->amOnPage($refererListPage);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->csrfToken();
        $I->assertNotSame('', $token, 'no CSRF token could be scraped');

        $I->sendFormPostRequest($uri, ['_token' => $token] + $params);
    }

    /** @return array<string, mixed> */
    private function row(FunctionalTester $I, string $table, int $id): array
    {
        $row = $this->em($I)->getConnection()->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$id]);
        $I->assertIsArray($row, sprintf('%s #%d does not exist', $table, $id));

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function lockRow(FunctionalTester $I, string $type, int $id): ?array
    {
        $row = $this->em($I)->getConnection()->fetchAssociative(
            'SELECT * FROM document_lock WHERE document_type = ? AND document_id = ?',
            [$type, $id],
        );

        return $row === false ? null : $row;
    }

    /** A fresh Draft PO with one line, its own vendor and warehouse. */
    private function purchaseOrder(FunctionalTester $I): PurchaseOrder
    {
        $em = $this->em($I);

        $region = (new FulfillmentRegion())->setName('PO Lock Region ' . uniqid());
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('PO Lock Vendor ' . uniqid());
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('PO-LOCK-' . uniqid())
            ->setName('PO Lock Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-LOCK-' . random_int(10000, 99999))
            ->setVendor($vendor)
            ->deriveTaxProvinceFrom($warehouse)
            ->setExpectedDate('2026-10-01');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName('PO Lock Widget')
            ->setSku('PO-LOCK-1')
            ->setQuantityOrdered('10.00')
            ->setUnitCost('2.5000')
            ->setSubtotal('25.00');
        $order->addLine($line);
        $em->persist($line);
        $em->flush();

        return $order;
    }

    /** A fresh Draft Bill against its own PO. */
    private function vendorBill(FunctionalTester $I, PurchaseOrder $order): VendorBill
    {
        $em = $this->em($I);

        $bill = (new VendorBill())
            ->setBillNumber('BILL-LOCK-' . random_int(10000, 99999))
            ->setVendor($order->getVendor())
            ->setVendorName($order->getVendor()->getName())
            ->setPurchaseOrder($order)
            ->setDueDate('2026-10-15');
        $em->persist($bill);

        $billLine = (new VendorBillLine())
            ->setPurchaseOrderLine($order->getLines()->first())
            ->setProduct($order->getLines()->first()->getProduct())
            ->setName('PO Lock Widget')
            ->setQuantity('10.00')
            ->setUnitCost('2.5000')
            ->setSubtotal('25.00');
        $bill->addLine($billLine);
        $em->persist($billLine);
        $bill->recalculateTotals();
        $em->flush();

        return $bill;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Purchase Order
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function aLockedPurchaseOrderRefusesEveryWritePath(FunctionalTester $I): void
    {
        $this->actAsSuperAdmin($I);
        $order = $this->purchaseOrder($I);
        $orderId = (int) $order->getId();
        $untouched = $this->purchaseOrder($I);
        $untouchedId = (int) $untouched->getId();

        $listPage = '/admin/bundles/procurement/purchase-orders';

        // ── POSITIVE CONTROL: issuing works before the lock.
        $this->post($I, $listPage, '/admin/bundles/procurement/purchase-orders/' . $orderId . '/issue');
        $I->assertSame('Issued', (string) $this->row($I, 'purchase_order', $orderId)['status'], 'issue did not work before the lock');

        // ── Lock it through the real route.
        $this->post($I, $listPage, '/admin/bundles/procurement/purchase-orders/' . $orderId . '/lock', ['reason' => 'Under audit.']);
        $lock = $this->lockRow($I, 'purchase_order', $orderId);
        $I->assertIsArray($lock, 'document_lock has no row for the locked purchase order');
        $I->assertSame('Under audit.', (string) $lock['reason']);

        $frozen = $this->row($I, 'purchase_order', $orderId);

        // ── WRITE PATH 1: a real named action (cancel is legal from Issued — nothing has arrived).
        //    No lock-specific code runs on this path; DocumentLockFlushGuard alone refuses it, and
        //    act()'s existing \DomainException catch is what turns that into this flash.
        $this->post($I, $listPage, '/admin/bundles/procurement/purchase-orders/' . $orderId . '/cancel', ['reason' => 'Trying anyway.']);
        $I->assertSame('Issued', (string) $this->row($I, 'purchase_order', $orderId)['status'], 'a locked PO was cancelled');
        $I->see('is locked and cannot be', '.flash-error');
        $I->see('Unlock it', '.flash-error');

        // ── WRITE PATH 2: the edit screen, on GET. It must not even open.
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $orderId . '/edit');
        $I->seeCurrentUrlEquals('/admin/bundles/procurement/purchase-orders/' . $orderId);

        // ── WRITE PATH 3 and 4: the service layer, no controller involved — DocumentLockFlushGuard
        //    is the only thing that can refuse these.
        $em = $this->em($I);
        $em->clear();
        $reloaded = $em->find(PurchaseOrder::class, $orderId);
        $reloaded->setPoNumber('SERVICE-LAYER-REWRITE');
        $refused = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refused = true;
        }
        $I->assertTrue($refused, 'a flush that rewrote a locked PO\'s header was not refused');
        $I->assertSame((string) $frozen['po_number'], (string) $this->row($I, 'purchase_order', $orderId)['po_number']);

        $em->clear();
        $reloadedLine = $em->getRepository(PurchaseOrderLine::class)->findOneBy(['purchaseOrder' => $orderId]);
        $reloadedLine->setQuantityOrdered('999.00');
        $refusedLine = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refusedLine = true;
        }
        $I->assertTrue($refusedLine, 'a flush that rewrote a locked PO\'s line was not refused — PurchaseOrderLine owner mapping is broken');
        $em->clear();
        $I->assertSame('10.00', number_format((float) $this->row($I, 'purchase_order_line', (int) $reloadedLine->getId())['quantity_ordered'], 2, '.', ''), 'a locked PO line quantity moved');

        // ── Only a super admin may unlock.
        $this->actAsPlainAdmin($I);
        $I->amOnPage($listPage . '/' . $orderId);
        $token = $I->csrfToken();
        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/' . $orderId . '/unlock', ['_token' => $token]);
        $I->seeResponseCodeIs(403);
        $I->assertNotNull($this->lockRow($I, 'purchase_order', $orderId), 'a plain admin unlocked a purchase order');

        $this->actAsSuperAdmin($I);
        $this->post($I, $listPage, '/admin/bundles/procurement/purchase-orders/' . $orderId . '/unlock');
        $I->assertNull($this->lockRow($I, 'purchase_order', $orderId), 'unlock did not remove the row');

        // ── The action that was refused now works.
        $this->post($I, $listPage, '/admin/bundles/procurement/purchase-orders/' . $orderId . '/cancel', ['reason' => 'Now allowed.']);
        $I->assertSame('Cancelled', (string) $this->row($I, 'purchase_order', $orderId)['status']);

        // ── Control: the untouched PO never moved.
        $I->assertNull($this->lockRow($I, 'purchase_order', $untouchedId));
        $I->assertSame('Draft', (string) $this->row($I, 'purchase_order', $untouchedId)['status']);
    }

    public function aLockedDocumentIsStillViewedAndPrinted(FunctionalTester $I): void
    {
        $this->actAsSuperAdmin($I);
        $order = $this->purchaseOrder($I);
        $orderId = (int) $order->getId();
        $listPage = '/admin/bundles/procurement/purchase-orders';

        $this->post($I, $listPage, '/admin/bundles/procurement/purchase-orders/' . $orderId . '/lock');

        $I->amOnPage($listPage . '/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage($listPage . '/' . $orderId . '/print');
        $I->seeResponseCodeIsSuccessful();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // Vendor Bill
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function aLockedBillRefusesEveryWritePath(FunctionalTester $I): void
    {
        $this->actAsSuperAdmin($I);
        $order = $this->purchaseOrder($I);
        $bill = $this->vendorBill($I, $order);
        $billId = (int) $bill->getId();
        $untouched = $this->vendorBill($I, $this->purchaseOrder($I));
        $untouchedId = (int) $untouched->getId();

        $listPage = '/admin/bundles/procurement/bills';

        // ── POSITIVE CONTROL: approving works before the lock.
        $this->post($I, $listPage, '/admin/bundles/procurement/bills/' . $billId . '/approve');
        $I->assertSame('Open', (string) $this->row($I, 'vendor_bill', $billId)['status'], 'approve did not work before the lock');

        // ── Lock it through the real route.
        $this->post($I, $listPage, '/admin/bundles/procurement/bills/' . $billId . '/lock', ['reason' => 'Disputed internally first.']);
        $lock = $this->lockRow($I, 'vendor_bill', $billId);
        $I->assertIsArray($lock, 'document_lock has no row for the locked bill');

        $frozen = $this->row($I, 'vendor_bill', $billId);

        // ── WRITE PATH 1: a real named action. Dispute is legal from Open. Same as PO's — no
        //    lock-specific code on this path, DocumentLockFlushGuard alone refuses it.
        $this->post($I, $listPage, '/admin/bundles/procurement/bills/' . $billId . '/dispute', ['reason' => 'Trying anyway.']);
        $I->assertSame('Open', (string) $this->row($I, 'vendor_bill', $billId)['status'], 'a locked bill was disputed');
        $I->see('is locked and cannot be', '.flash-error');

        // ── WRITE PATH 2: the edit screen, on GET.
        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId . '/edit');
        $I->seeCurrentUrlEquals('/admin/bundles/procurement/bills/' . $billId);

        // ── WRITE PATH 3 and 4: the service layer.
        $em = $this->em($I);
        $em->clear();
        $reloaded = $em->find(VendorBill::class, $billId);
        $reloaded->setBillNumber('SERVICE-LAYER-REWRITE');
        $refused = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refused = true;
        }
        $I->assertTrue($refused, 'a flush that rewrote a locked bill\'s header was not refused');
        $I->assertSame((string) $frozen['bill_number'], (string) $this->row($I, 'vendor_bill', $billId)['bill_number']);

        $em->clear();
        $reloadedLine = $em->getRepository(VendorBillLine::class)->findOneBy(['bill' => $billId]);
        $reloadedLine->setQuantity('999.00');
        $refusedLine = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refusedLine = true;
        }
        $I->assertTrue($refusedLine, 'a flush that rewrote a locked bill\'s line was not refused — VendorBillLine owner mapping is broken');
        $em->clear();
        $I->assertSame('10.00', number_format((float) $this->row($I, 'vendor_bill_line', (int) $reloadedLine->getId())['quantity'], 2, '.', ''), 'a locked bill line quantity moved');

        // ── Only a super admin may unlock.
        $this->actAsPlainAdmin($I);
        $I->amOnPage($listPage . '/' . $billId);
        $token = $I->csrfToken();
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/unlock', ['_token' => $token]);
        $I->seeResponseCodeIs(403);
        $I->assertNotNull($this->lockRow($I, 'vendor_bill', $billId), 'a plain admin unlocked a bill');

        $this->actAsSuperAdmin($I);
        $this->post($I, $listPage, '/admin/bundles/procurement/bills/' . $billId . '/unlock');
        $I->assertNull($this->lockRow($I, 'vendor_bill', $billId));

        // ── The action that was refused now works.
        $this->post($I, $listPage, '/admin/bundles/procurement/bills/' . $billId . '/dispute', ['reason' => 'Now allowed.']);
        $I->assertSame('Disputed', (string) $this->row($I, 'vendor_bill', $billId)['status']);

        // ── Control: the untouched bill never moved.
        $I->assertNull($this->lockRow($I, 'vendor_bill', $untouchedId));
        $I->assertSame('Draft', (string) $this->row($I, 'vendor_bill', $untouchedId)['status']);
    }
}
