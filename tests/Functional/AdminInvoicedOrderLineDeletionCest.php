<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * An order line that an invoice bills cannot be deleted off the order (item 63) — conducted per
 * #624, anchored per #627.
 *
 * ## What was wrong
 *
 * `invoice_line.sales_order_line_id` is `ON DELETE SET NULL`, deliberately: the column's own
 * docblock says losing the attribution must never silently delete the billing record of goods that
 * were actually sent. Nothing enforced the other half of that bargain. Conducted on clean main, a
 * line of 10 invoiced for 4 and then deleted from the order form left `sales_order_line` gone and
 * `invoice_line.sales_order_line_id` NULL — the invoice still charging the customer for four units
 * while `SalesOrder::invoicedQuantityFor()`, which attributes by exactly that foreign key, stopped
 * counting them against anything.
 *
 * ## Why refusing rather than cascading
 *
 * Cascading would delete an issued invoice's line, which changes what a customer was billed because
 * somebody tidied an order. An invoice is the accounting record and the order is an operations
 * document; that is the same split that makes REDUCING an invoiced line legal and documented —
 * `SalesOrderStatusDeriverTest` names the excess as "a discrepancy to resolve with a credit". So
 * reducing stays legal here and is asserted below, and only the deletion is refused.
 */
final class AdminInvoicedOrderLineDeletionCest
{
    /** Codeception reuses one Cest instance across methods, so every fixture is rebuilt per test. */
    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('invoiced-line-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * THE case: an order line billed for 4 units, deleted from the order form, must survive — and
     * the invoice line must still point at it.
     */
    public function deletingAnInvoicedLineIsRefusedAndTheAttributionSurvives(FunctionalTester $I): void
    {
        // Both fixtures before the first request: reading a page clears the entity manager, and a
        // Company built after that is detached by the time the next fixture references it.
        [$orderId, $billedLineId, $spareLineId, $productId, $spareProductId] = $this->approvedOrderWithTwoLines($I);
        $bystander = $this->approvedOrderWithTwoLines($I);
        $invoiceId = $this->invoiceFor($I, $orderId, $billedLineId, '4.00');
        $bystanderInvoiceId = $this->invoiceFor($I, $bystander[0], $bystander[1], '4.00');

        $I->assertSame($billedLineId, $this->attributionOf($I, $invoiceId), 'guard: the invoice line is attributed to the order line');

        // The delete: the form is posted back carrying only the row that stays.
        $this->saveOrderLines($I, $orderId, [
            ['id' => (string) $spareLineId, 'product_id' => (string) $spareProductId, 'qty' => '2', 'price' => '5.00'],
        ]);

        // --- The refusal, by column --------------------------------------------------------------
        //
        // The attribution first, because it is the defect stated as a number: on unfixed main this
        // reads NULL against an invoice that is still charging for four units.
        $I->assertSame($billedLineId, $this->attributionOf($I, $invoiceId), 'invoice_line.sales_order_line_id was cut loose');
        $I->assertTrue($this->lineExists($I, $billedLineId), 'the invoiced order line was deleted anyway');
        $I->assertSame(4.0, $this->invoicedQuantity($I, $invoiceId), 'and the invoice still bills the four units it always did');

        // The rest of the save went down with it: one transaction, so a refused deletion does not
        // leave half an edit applied. The spare line is still at the quantity it was created with.
        $I->assertSame(3.0, $this->lineQuantity($I, $spareLineId), 'the refused save was rolled back whole');

        // --- And the admin is told why, and what to do instead ------------------------------------
        //
        // Asserted on the POST's own response, which is the form re-rendered at 422 with the
        // banner on it — not on a later page load, because the refusal is reported without a
        // redirect. Anchored to `.form-error-banner`, the element the order form already renders a
        // refusal into; it carries no id and giving it one would mean editing
        // templates/admin/order/form.html.twig, which another worker holds this session.
        $I->seeResponseCodeIs(422);
        $I->see('cannot be deleted', '.form-error-banner');
        $I->see($this->documentNumber($I, $invoiceId), '.form-error-banner');
        $I->see('Reduce the line quantity instead', '.form-error-banner');

        // --- The rows that must not change --------------------------------------------------------
        $I->assertTrue($this->lineExists($I, $bystander[1]), 'the unrelated order was not touched');
        $I->assertSame($bystander[1], $this->attributionOf($I, $bystanderInvoiceId));
        $I->assertSame(3.0, $this->lineQuantity($I, $bystander[2]));
        $I->assertSame($productId, $this->lineProductId($I, $billedLineId), 'and the refused line is byte-for-byte what it was');
    }

    /**
     * The positive control on the same screen and the same save: a line nothing has invoiced still
     * deletes exactly as it always did.
     *
     * Without this, the refusal above would be indistinguishable from an order form that has simply
     * stopped deleting rows.
     */
    public function anUninvoicedLineStillDeletes(FunctionalTester $I): void
    {
        [$orderId, $billedLineId, $spareLineId, $productId, $spareProductId] = $this->approvedOrderWithTwoLines($I);
        $invoiceId = $this->invoiceFor($I, $orderId, $billedLineId, '4.00');

        $I->assertTrue($this->lineExists($I, $spareLineId), 'guard: the spare line is there to begin with');

        // This time the SPARE row is the one left out, and the billed row is posted back.
        $this->saveOrderLines($I, $orderId, [
            ['id' => (string) $billedLineId, 'product_id' => (string) $productId, 'qty' => '10', 'price' => '5.00'],
        ]);

        $I->assertFalse($this->lineExists($I, $spareLineId), 'an uninvoiced line must still be deletable');
        $I->assertTrue($this->lineExists($I, $billedLineId), 'and the billed one survives its own save');
        $I->assertSame($billedLineId, $this->attributionOf($I, $invoiceId));

        // The positive control for the banner: this save was NOT refused, and the same element the
        // refused one painted is absent here.
        $I->dontSeeElement('.form-error-banner');
    }

    /**
     * REDUCING an invoiced line below what was billed is deliberate and documented, and is NOT what
     * this refusal covers. Proved on the same screen so the two cannot be confused.
     *
     * The order is an operations document and may say less than the invoice billed; the difference
     * is a discrepancy to settle with a credit note. Only the deletion severs the attribution, and
     * only the deletion is refused.
     */
    public function reducingAnInvoicedLineBelowWhatWasBilledIsStillAllowed(FunctionalTester $I): void
    {
        [$orderId, $billedLineId, $spareLineId, $productId, $spareProductId] = $this->approvedOrderWithTwoLines($I);
        $invoiceId = $this->invoiceFor($I, $orderId, $billedLineId, '4.00');

        $this->saveOrderLines($I, $orderId, [
            ['id' => (string) $billedLineId, 'product_id' => (string) $productId, 'qty' => '1', 'price' => '5.00'],
            ['id' => (string) $spareLineId, 'product_id' => (string) $spareProductId, 'qty' => '3', 'price' => '5.00'],
        ]);

        $I->assertSame(1.0, $this->lineQuantity($I, $billedLineId), 'the reduction below the invoiced quantity went through');
        $I->assertSame($billedLineId, $this->attributionOf($I, $invoiceId), 'and the invoice still points at the line');
        $I->assertSame(4.0, $this->invoicedQuantity($I, $invoiceId), 'billing four while the order now says one — the documented discrepancy');

        $I->dontSeeElement('.form-error-banner');
    }

    /**
     * A CANCELLED invoice bills nothing and never will, so the line it once named is free again.
     *
     * The rule is drawn at "an invoice that can still charge somebody" rather than at "any invoice
     * line that ever existed", because the second would leave an order permanently unable to drop a
     * row on account of a document that was withdrawn.
     */
    public function aLineBilledOnlyByACancelledInvoiceCanBeDeleted(FunctionalTester $I): void
    {
        [$orderId, $billedLineId, $spareLineId, , $spareProductId] = $this->approvedOrderWithTwoLines($I);
        $invoiceId = $this->invoiceFor($I, $orderId, $billedLineId, '4.00');

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[action="/admin/invoice/' . $invoiceId . '/action/cancel"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/cancel', ['_token' => $token]);
        $I->assertSame('Cancelled', (string) $this->connection($I)->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoiceId]), 'guard: the invoice really was cancelled');

        $this->saveOrderLines($I, $orderId, [
            ['id' => (string) $spareLineId, 'product_id' => (string) $spareProductId, 'qty' => '3', 'price' => '5.00'],
        ]);

        $I->assertFalse($this->lineExists($I, $billedLineId), 'a cancelled invoice must not hold an order line hostage');
        $I->assertNull($this->attributionOf($I, $invoiceId), 'and the dead invoice line is the one that loses its attribution');
    }

    // -------------------------------------------------------------------------------- the plumbing

    /**
     * An approved order with two lines: one that gets invoiced, and a spare of 3 that does not.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} orderId, billedLineId, spareLineId, productId, spareProductId
     */
    private function approvedOrderWithTwoLines(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);

        $company = (new Company())
            ->setName('Invoiced Line Wholesale')
            ->setCode('ILW-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        $product = (new ProductCore())
            ->setSku('ILW-A-' . uniqid())
            ->setName('Billed Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $spareProduct = (new ProductCore())
            ->setSku('ILW-B-' . uniqid())
            ->setName('Spare Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveInRepository($spareProduct);
        $I->haveStockFor($product);
        $I->haveStockFor($spareProduct);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ILW-' . uniqid())
            ->setSubtotal('65.00')
            ->setTax('0.00')
            ->setTotal('65.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Billed Widget')
                ->setSku((string) $product->getSku())
                ->setQuantity('10.00')
                ->setPrice('5.00')
                ->setSubtotal('50.00'),
        );
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($spareProduct)
                ->setName('Spare Widget')
                ->setSku((string) $spareProduct->getSku())
                ->setQuantity('3.00')
                ->setPrice('5.00')
                ->setSubtotal('15.00'),
        );
        $I->haveInRepository($order);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        $lines = $order->getLines()->toArray();

        return [
            (int) $order->getId(),
            (int) $lines[0]->getId(),
            (int) $lines[1]->getId(),
            (int) $product->getId(),
            (int) $spareProduct->getId(),
        ];
    }

    /** Raise and issue an invoice for $quantity of one order line, through the real screen. */
    private function invoiceFor(FunctionalTester $I, int $orderId, int $lineId, string $quantity): int
    {
        $productId = (int) $this->connection($I)->fetchOne('SELECT product_id FROM sales_order_line WHERE id = ?', [$lineId]);

        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $productId, 'sales_order_line_id' => (string) $lineId, 'qty' => $quantity, 'price' => '5.00']],
        ]);

        return (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId],
        );
    }

    /**
     * Save the order edit form carrying exactly $lines. Rows the post leaves out are the deletion.
     *
     * @param list<array<string, string>> $lines
     */
    private function saveOrderLines(FunctionalTester $I, int $orderId, array $lines): void
    {
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'lines' => $lines,
            'save_mode' => 'recalc',
        ]);
    }

    private function lineExists(FunctionalTester $I, int $lineId): bool
    {
        return $this->connection($I)->fetchOne('SELECT COUNT(*) FROM sales_order_line WHERE id = ?', [$lineId]) > 0;
    }

    private function lineQuantity(FunctionalTester $I, int $lineId): float
    {
        return (float) $this->connection($I)->fetchOne('SELECT quantity FROM sales_order_line WHERE id = ?', [$lineId]);
    }

    private function lineProductId(FunctionalTester $I, int $lineId): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT product_id FROM sales_order_line WHERE id = ?', [$lineId]);
    }

    /** `invoice_line.sales_order_line_id` as the database holds it, for the invoice's only line. */
    private function attributionOf(FunctionalTester $I, int $invoiceId): ?int
    {
        $value = $this->connection($I)->fetchOne(
            'SELECT sales_order_line_id FROM invoice_line WHERE invoice_id = ? ORDER BY id LIMIT 1',
            [$invoiceId],
        );

        return $value === null || $value === false ? null : (int) $value;
    }

    private function invoicedQuantity(FunctionalTester $I, int $invoiceId): float
    {
        return (float) $this->connection($I)->fetchOne(
            'SELECT quantity FROM invoice_line WHERE invoice_id = ? ORDER BY id LIMIT 1',
            [$invoiceId],
        );
    }

    private function documentNumber(FunctionalTester $I, int $invoiceId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT document_number FROM invoice WHERE id = ?', [$invoiceId]);
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
