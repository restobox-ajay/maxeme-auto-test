<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The inventory ledger — `product_inventory`'s hold-bucket columns (the balance trail) and
 * `inventory_bucket_change_log` (the audit trail, written exclusively by the onFlush listener
 * `App\EventSubscriber\InventoryBucketChangeLogger`) — across Estimate, Sales Order and Invoice,
 * at the real status transitions that move them.
 *
 * ## What actually moves, and when (there is no fourth option)
 *
 * Nothing in this lifecycle ever decrements `product_inventory.quantity`. Merely CREATING an
 * estimate touches no bucket — but ACCEPTING one can, immediately: `EstimateConversionService`
 * auto-approves the order it produces the moment stock covers it (no separate admin Approve step
 * on the CUSTOMER's own accept path, which also invoices the order in full in the same breath —
 * `QuoteConversionInvoicing::RaiseInvoice`; the admin's own Accept action passes `NoInvoice` and
 * stops at Approved). Only a shortfall holds the converted order back as an inert Draft. An
 * Invoice holds `pending` while Pending and `approved` while Processing/Completed — `approved` is
 * released only by a product import/recount, never by this lifecycle (InventoryDepthBundle/
 * README.md). So "prove the ledger" here means: prove the bucket columns move by exactly the right
 * amount at exactly these transitions, prove the audit log grows by exactly one row per column that
 * actually changed, and — the harder half — prove nothing moves at every OTHER point (a Draft
 * order/estimate that never resolved, a product row nothing here bills).
 *
 * ## Tracking mode is provably irrelevant to any of this
 *
 * A lot- or serial-tracked product moves the ledger identically to an untracked one — nothing in
 * `InventoryReservationReconciler` reads `TrackingPolicy` or `batch` at all. Asserted directly by
 * running the same order-approval assertion against both a lot-tracked and a serial-tracked product,
 * per SellSideBatchCaptureAcrossDocumentsCest's own finding that `batch` is inert data.
 */
final class SellSideInventoryLedgerAcrossDocumentsCest
{
    private ?Company $company = null;

    public function _before(FunctionalTester $I): void
    {
        $this->company = null;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('ledger-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ---------------------------------------------------------------- fixtures

    private function makeCompany(FunctionalTester $I): Company
    {
        if ($this->company instanceof Company) {
            return $this->company;
        }
        $company = (new Company())
            ->setName('Ledger Trail Co')
            ->setCode('LTC-' . uniqid())
            ->setPrimaryEmail('ap@ledger-trail.example');
        $I->haveInRepository($company);

        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Bill')->setCompanyName('Ledger Trail Co')
            ->setFirstName('Bill')->setLastName('Payer')->setAddressLine1('1 Billing Way')
            ->setCity('Toronto')->setProvince('ON')->setCountry('CA')->setPostalCode('M4B1B3')
            ->setIsDefaultBilling(true));
        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Ship')->setCompanyName('Ledger Trail Co')
            ->setFirstName('Ship')->setLastName('Receiver')->setAddressLine1('2 Shipping Road')
            ->setCity('Toronto')->setProvince('ON')->setCountry('CA')->setPostalCode('M4B1B4')
            ->setIsDefaultShipping(true));

        $I->haveActiveFulfillmentRegionFor($company, 'Main');
        $this->company = $company;

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $skuPrefix, ?string $trackingMode, int $stock = 200): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($skuPrefix . '-' . uniqid())
            ->setName('Ledger Widget ' . $skuPrefix)
            ->setUnit('EA')
            ->setSalesTaxCode('G')
            ->setCostPrice('4.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        $em = $I->grabService(EntityManagerInterface::class);
        if ($trackingMode !== null) {
            $policy = (new TrackingPolicy())
                ->setName('Policy ' . $skuPrefix)
                ->setMode($trackingMode)
                ->setTrackIn(true)
                ->setTrackOut(true);
            $em->persist($policy);
            $product->setTrackingPolicy($policy);
        }
        $em->persist($product);
        $em->flush();

        $I->haveStockFor($product, $stock, 'Main');

        return $product;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function inventoryIdFor(FunctionalTester $I, ProductCore $product): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT id FROM product_inventory WHERE product_id = ?', [$product->getId()]);
    }

    /** One column of one product_inventory row, re-read from the database. */
    private function inventoryColumn(FunctionalTester $I, int $inventoryId, string $column): int
    {
        return (int) $this->connection($I)->fetchOne(sprintf('SELECT %s FROM product_inventory WHERE id = ?', $column), [$inventoryId]);
    }

    /** @return list<array<string, mixed>> every audit row for this product, oldest first */
    private function auditRowsFor(FunctionalTester $I, ProductCore $product): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT * FROM inventory_bucket_change_log WHERE product_id = ? ORDER BY id ASC',
            [$product->getId()],
        );
    }

    private function approveOrder(FunctionalTester $I, SalesOrder $order): void
    {
        $I->sendFormPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);
    }

    // -------------------------------------------------------- 1. the estimate: touches nothing

    /**
     * Accepting an estimate the shelf can actually cover does NOT land as an inert Draft, and does
     * not stop at Approved either. The CUSTOMER's own accept endpoint calls `EstimateConversionService
     * ::convert()` with `invoicing: QuoteConversionInvoicing::RaiseInvoice` (unlike the admin's own
     * accept action, which passes `NoInvoice`) — so one customer click both approves the order AND
     * raises a full invoice against it, in the same transaction. The order's `sales_hold` therefore
     * never settles anywhere an observer outside that transaction can see it move: it rises to the
     * accepted quantity and falls straight back to 0 (fully invoiced) before the one flush the whole
     * operation performs, so the audit log — which only ever records a NET change per flush — shows
     * no `sales_hold` row at all. What it does show is the invoice's own `pending` bucket, because
     * that bucket's net change across the same flush is real: 0 straight to the billed quantity.
     * This is empirically confirmed (not assumed) against the real accept endpoint.
     */
    public function acceptingAnInStockEstimateGoesStraightToAFullyInvoicedOrderAndHoldsOnlyPending(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'EST-LOT', TrackingPolicy::MODE_LOT, 200);
        $inventoryId = $this->inventoryIdFor($I, $product);
        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), 'guard: nothing held before acceptance');
        $I->assertCount(0, $this->auditRowsFor($I, $product), 'guard: no audit history yet');

        $this->acceptEstimate($I, $company, $product, '3.00');

        $order = $this->connection($I)->fetchAssociative(
            'SELECT status FROM sales_order WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertIsArray($order, 'guard: accepting really did create an order');
        $I->assertSame('Invoiced', (string) $order['status'], 'sales_order.status — approved and immediately fully billed, one customer action');

        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), 'product_inventory.sales_hold_quantity nets to 0 — the order is fully invoiced by the time anything is flushed');
        $I->assertSame(3, $this->inventoryColumn($I, $inventoryId, 'pending_quantity'), 'product_inventory.pending_quantity holds exactly what the auto-raised invoice bills');
        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'approved_quantity'), 'the invoice is issued (Pending), not yet Processing — nothing is approved_quantity yet');

        $entries = $this->auditRowsFor($I, $product);
        $I->assertCount(1, $entries, 'only pending actually net-changed across the one flush the whole accept performs — sales_hold rose and fell within it, so the logger records no row for it');
        $I->assertSame(InventoryBucketChangeLog::BUCKET_PENDING, (string) $entries[0]['bucket']);
        $I->assertSame(0, (int) $entries[0]['previous_quantity']);
        $I->assertSame(3, (int) $entries[0]['new_quantity']);
        $I->assertSame('invoice_reconciled', (string) $entries[0]['action']);
        $I->assertNull($entries[0]['group_id'], 'a sell-side hold moves no stock physically');
    }

    /**
     * The other half of the same rule: an estimate accepted for MORE than the shelf holds converts
     * into a Draft order instead — `AdminOrderStockValidator::shortfallsForOrder()` is not empty, so
     * `EstimateConversionService` never calls `setStatus('Approved', ...)` at all, and Draft is one
     * of the two statuses `OrderInventoryBucketResolver` holds nothing for. THIS is the case that
     * actually touches no bucket and writes no audit row — not "every Estimate", but "one that would
     * oversell if it committed".
     */
    public function acceptingAnEstimateThatWouldOversellLeavesTheOrderADraftThatHoldsNothing(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'EST-SHORT', TrackingPolicy::MODE_LOT, 1);
        $inventoryId = $this->inventoryIdFor($I, $product);
        $auditCountBefore = count($this->auditRowsFor($I, $product));

        // 3 requested against 1 in stock — a real, unmistakable shortfall.
        [, $customer] = $this->acceptEstimate($I, $company, $product, '3.00');

        $order = $this->connection($I)->fetchAssociative(
            'SELECT status FROM sales_order WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertIsArray($order, 'guard: accepting still creates an order — acceptance is never refused');
        $I->assertSame('Draft', (string) $order['status'], 'a shortfall holds the order back as a Draft rather than oversell the shelf');

        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), 'product_inventory.sales_hold_quantity is untouched — a Draft reserves nothing');
        $I->assertCount($auditCountBefore, $this->auditRowsFor($I, $product), 'inventory_bucket_change_log gained no row for a Draft order');
    }

    /**
     * Builds and accepts a Priced, fully-priced estimate for one product/quantity, through the real
     * customer accept screen, and returns [estimate id, the logged-in customer].
     *
     * @return array{0: int, 1: CustomerUser}
     */
    private function acceptEstimate(FunctionalTester $I, Company $company, ProductCore $product, string $quantity): array
    {
        $unitPrice = 10.00;
        $subtotal = number_format($unitPrice * (float) $quantity, 2, '.', '');

        $estimate = (new Estimate())
            ->setCompany($company)->setDocumentNumber('LTC-EST-' . uniqid())->setSource('Admin')
            // isFullyPriced() (the customer Accept button's own gate) requires shippingTotal to be
            // resolved too, not just the lines — a shipping row is what resolves it.
            ->setFeeLines(json_encode([['slug' => 'shipping', 'label' => 'Shipping', 'taxClass' => 'G', 'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'manual']]))
            ->setSubtotal($subtotal)->setTax('0.00')->setTotal($subtotal);
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->setFulfillmentRegion('Main');
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setLocation('Main')->setUnit('EA')->setTaxCode('G')
                ->setQuantity($quantity)->setCost('4.00')->setPrice((string) $unitPrice)->setSubtotal($subtotal)
        );
        $I->haveInRepository($estimate);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('ledger-buyer-' . uniqid() . '@example.test')
            ->setFirstName('Buy')->setLastName('Er')->setCompany($company)->setStatus('Active');
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);
        $I->amLoggedInAs($customer, 'main');
        $I->haveHttpHeader('Host', 'localhost');

        $I->amOnPage('/estimates/' . $estimate->getId());
        preg_match('/<form[^>]*action="[^"]*\/accept"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/', $I->grabPageSource(), $m);
        $I->assertNotEmpty($m[1] ?? '', 'the accept form rendered a CSRF token');
        $I->sendAjaxPostRequest('/estimates/' . $estimate->getId() . '/accept', ['_token' => $m[1]]);
        $I->seeResponseCodeIsSuccessful();

        return [(int) $estimate->getId(), $customer];
    }

    // ---------------------------------------------- 2. the order: Approve raises sales_hold

    public function approvingAnOrderRaisesSalesHoldWithAMatchingAuditRowForALotTrackedProduct(FunctionalTester $I): void
    {
        $this->assertApprovingRaisesSalesHold($I, TrackingPolicy::MODE_LOT);
    }

    public function approvingAnOrderRaisesSalesHoldWithAMatchingAuditRowForASerialTrackedProduct(FunctionalTester $I): void
    {
        $this->assertApprovingRaisesSalesHold($I, TrackingPolicy::MODE_SERIAL);
    }

    /**
     * Approving an order (through the real status-update route, not a direct entity write) raises
     * `sales_hold_quantity` by exactly the line's quantity, and writes exactly one audit row —
     * bucket `sales_hold`, 0 -> quantity, action `order_reconciled`, no movement group (a sell-side
     * hold moves nothing physically, InventoryBucketChangeLog's own docblock). Run against both a
     * lot- and a serial-tracked product: identical ledger behavior either way, because the
     * reconciler reads quantities and buckets, never TrackingPolicy.
     */
    private function assertApprovingRaisesSalesHold(FunctionalTester $I, string $trackingMode): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'APR-' . strtoupper($trackingMode), $trackingMode);
        $inventoryId = $this->inventoryIdFor($I, $product);
        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), 'guard: nothing held before approval');
        $I->assertCount(0, $this->auditRowsFor($I, $product), 'guard: no audit history yet');

        $order = (new SalesOrder())
            ->setCompany($company)->setOrderNumber('LTC-ORD-' . uniqid())
            ->setSubtotal('80.00')->setTax('0.00')->setTotal('80.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setLocation('Main')->setUnit('EA')->setTaxCode('G')
                ->setQuantity('8.00')->setPrice('10.00')->setSubtotal('80.00')
        );
        $I->haveInRepository($order);

        $this->approveOrder($I, $order);

        $I->assertSame(8, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), sprintf('product_inventory.sales_hold_quantity for a %s-tracked product', $trackingMode));

        $entries = $this->auditRowsFor($I, $product);
        $I->assertCount(1, $entries, 'exactly one bucket changed, so exactly one audit row was written');
        $I->assertSame(InventoryBucketChangeLog::BUCKET_SALES_HOLD, (string) $entries[0]['bucket'], 'inventory_bucket_change_log.bucket');
        $I->assertSame(0, (int) $entries[0]['previous_quantity'], 'inventory_bucket_change_log.previous_quantity');
        $I->assertSame(8, (int) $entries[0]['new_quantity'], 'inventory_bucket_change_log.new_quantity');
        $I->assertSame('order_reconciled', (string) $entries[0]['action'], 'inventory_bucket_change_log.action');
        $I->assertNull($entries[0]['group_id'], 'a sell-side hold moves no stock physically, so it opens no movement group');
    }

    // --------------------------------------- 3. SO -> Invoice: sales_hold moves to pending

    /**
     * Billing PART of an approved order moves two buckets in the same pass: the order's sales_hold
     * drops by what was just billed (its uninvoiced remainder shrank) and the new invoice's pending
     * rises by the same amount — two separate audit rows, because two separate columns changed, on
     * two separate products' worth of nothing else (there is only the one product here, but each row
     * names its own bucket).
     */
    public function raisingAPartialInvoiceAgainstAnApprovedOrderMovesSalesHoldIntoPending(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'CONV', TrackingPolicy::MODE_LOT);
        $inventoryId = $this->inventoryIdFor($I, $product);

        $order = (new SalesOrder())
            ->setCompany($company)->setOrderNumber('LTC-ORD-' . uniqid())
            ->setSubtotal('100.00')->setTax('0.00')->setTotal('100.00');
        $line = (new SalesOrderLine())
            ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
            ->setLocation('Main')->setUnit('EA')->setTaxCode('G')
            ->setQuantity('10.00')->setPrice('10.00')->setSubtotal('100.00');
        $order->addLine($line);
        $I->haveInRepository($order);
        $this->approveOrder($I, $order);
        $I->assertSame(10, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), 'guard: the full ten are held after approval');

        $auditCountBeforeInvoice = count($this->auditRowsFor($I, $product));

        $url = '/admin/invoice/create?order_id=' . $order->getId();
        $I->amOnPage($url);
        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $line->getId(), 'location' => 'Main', 'qty' => '4.00', 'price' => '10.00']],
        ]);

        // The order: 10 - 4 = 6 still held as sales_hold.
        $I->assertSame(6, $this->inventoryColumn($I, $inventoryId, 'sales_hold_quantity'), 'product_inventory.sales_hold_quantity dropped by exactly what was billed');
        // The invoice: 4 now held as pending.
        $I->assertSame(4, $this->inventoryColumn($I, $inventoryId, 'pending_quantity'), 'product_inventory.pending_quantity rose by exactly what the invoice bills');

        $newEntries = array_slice($this->auditRowsFor($I, $product), $auditCountBeforeInvoice);
        $I->assertCount(2, $newEntries, 'two columns changed, so exactly two new audit rows were written');

        $byBucket = [];
        foreach ($newEntries as $entry) {
            $byBucket[(string) $entry['bucket']] = $entry;
        }
        $I->assertArrayHasKey(InventoryBucketChangeLog::BUCKET_SALES_HOLD, $byBucket, 'one new row names sales_hold');
        $I->assertSame(10, (int) $byBucket[InventoryBucketChangeLog::BUCKET_SALES_HOLD]['previous_quantity']);
        $I->assertSame(6, (int) $byBucket[InventoryBucketChangeLog::BUCKET_SALES_HOLD]['new_quantity']);
        $I->assertSame('order_reconciled', (string) $byBucket[InventoryBucketChangeLog::BUCKET_SALES_HOLD]['action']);

        $I->assertArrayHasKey(InventoryBucketChangeLog::BUCKET_PENDING, $byBucket, 'the other new row names pending');
        $I->assertSame(0, (int) $byBucket[InventoryBucketChangeLog::BUCKET_PENDING]['previous_quantity']);
        $I->assertSame(4, (int) $byBucket[InventoryBucketChangeLog::BUCKET_PENDING]['new_quantity']);
        $I->assertSame('invoice_reconciled', (string) $byBucket[InventoryBucketChangeLog::BUCKET_PENDING]['action']);
    }

    // --------------------------------------------- 4. standalone invoice: pending, bystander alone

    /**
     * The Invoice-only path (no order behind it at all): issuing it holds exactly what it bills, on
     * its own product's ledger row and nowhere else — a bystander product stocked in the SAME
     * warehouse gets neither a bucket change nor an audit row, which is what proves the reconciler
     * keyed off the right product rather than "the" inventory row for that warehouse.
     */
    public function issuingAStandaloneInvoiceHoldsPendingAndLeavesABystanderProductsLedgerAlone(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        // Stocked FIRST, so "the first row in this warehouse" and "this line's own row" cannot be
        // the same row by accident.
        $bystander = $this->makeProduct($I, 'BYSTANDER', null);
        $product = $this->makeProduct($I, 'STANDALONE', TrackingPolicy::MODE_SERIAL);
        $bystanderInventoryId = $this->inventoryIdFor($I, $bystander);
        $inventoryId = $this->inventoryIdFor($I, $product);
        $bystanderAuditCountBefore = count($this->auditRowsFor($I, $bystander));

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'save_mode' => 'issue',
            'fulfillment_region' => 'Main',
            'lines' => [['product_id' => (string) $product->getId(), 'location' => 'Main', 'qty' => '5', 'price' => '10.00', 'tax_code' => 'G']],
        ]);

        $I->assertSame(5, $this->inventoryColumn($I, $inventoryId, 'pending_quantity'), 'product_inventory.pending_quantity rose by exactly what the invoice bills');
        $entries = $this->auditRowsFor($I, $product);
        $I->assertCount(1, $entries);
        $I->assertSame(InventoryBucketChangeLog::BUCKET_PENDING, (string) $entries[0]['bucket']);
        $I->assertSame(0, (int) $entries[0]['previous_quantity']);
        $I->assertSame(5, (int) $entries[0]['new_quantity']);
        $I->assertSame('invoice_reconciled', (string) $entries[0]['action']);

        $I->assertSame(0, $this->inventoryColumn($I, $bystanderInventoryId, 'pending_quantity'), 'the bystander product, same warehouse, holds nothing');
        $I->assertCount($bystanderAuditCountBefore, $this->auditRowsFor($I, $bystander), 'and its audit history gained no row');
    }

    // ----------------------------------------- 5. invoice lifecycle: pending -> approved -> (still) approved

    /**
     * Processing moves the whole held amount from `pending` to `approved` — two more audit rows, one
     * per column that changed. Then Completed, per InvoiceInventoryBucketResolver's own table, maps
     * to the SAME bucket as Processing — so the second transition must move NOTHING and write NO
     * further row, which is the assertion an implementation that re-applies "approved" on every
     * transition would fail.
     */
    public function processingMovesPendingToApprovedAndCompletingMovesNothingFurther(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I, 'LIFECYCLE', TrackingPolicy::MODE_LOT);
        $inventoryId = $this->inventoryIdFor($I, $product);

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'save_mode' => 'issue',
            'fulfillment_region' => 'Main',
            'lines' => [['product_id' => (string) $product->getId(), 'location' => 'Main', 'qty' => '6', 'price' => '10.00', 'tax_code' => 'G']],
        ]);
        $invoiceId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$company->getId()],
        );
        $I->assertSame(6, $this->inventoryColumn($I, $inventoryId, 'pending_quantity'), 'guard: issuing held it as pending');
        $auditCountAfterIssue = count($this->auditRowsFor($I, $product));

        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/start-processing', ['_token' => $I->csrfToken()]);

        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'pending_quantity'), 'product_inventory.pending_quantity emptied on Processing');
        $I->assertSame(6, $this->inventoryColumn($I, $inventoryId, 'approved_quantity'), 'product_inventory.approved_quantity received the same amount');

        $afterProcessing = $this->auditRowsFor($I, $product);
        $newFromProcessing = array_slice($afterProcessing, $auditCountAfterIssue);
        $I->assertCount(2, $newFromProcessing, 'two columns changed on the Processing transition');
        $byBucket = [];
        foreach ($newFromProcessing as $entry) {
            $byBucket[(string) $entry['bucket']] = $entry;
        }
        $I->assertSame(6, (int) $byBucket[InventoryBucketChangeLog::BUCKET_PENDING]['previous_quantity']);
        $I->assertSame(0, (int) $byBucket[InventoryBucketChangeLog::BUCKET_PENDING]['new_quantity']);
        $I->assertSame(0, (int) $byBucket[InventoryBucketChangeLog::BUCKET_APPROVED]['previous_quantity']);
        $I->assertSame(6, (int) $byBucket[InventoryBucketChangeLog::BUCKET_APPROVED]['new_quantity']);
        foreach ($newFromProcessing as $entry) {
            $I->assertSame('invoice_reconciled', (string) $entry['action']);
        }

        // Completed maps to the same bucket as Processing (InvoiceInventoryBucketResolver), so this
        // transition must be a genuine no-op on the ledger.
        $auditCountAfterProcessing = count($this->auditRowsFor($I, $product));
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/complete', ['_token' => $I->csrfToken()]);

        $I->assertSame(0, $this->inventoryColumn($I, $inventoryId, 'pending_quantity'), 'Completed leaves pending at 0');
        $I->assertSame(6, $this->inventoryColumn($I, $inventoryId, 'approved_quantity'), 'Completed leaves approved exactly where Processing left it');
        $I->assertCount($auditCountAfterProcessing, $this->auditRowsFor($I, $product), 'Completed writes no further audit row — nothing changed for the logger to see');
    }
}
