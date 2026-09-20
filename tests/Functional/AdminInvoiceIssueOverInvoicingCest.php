<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A sales order line may not be invoiced past what was ordered (#31) — conducted per #624, through
 * the real screens, with plain form POSTs carrying a scraped CSRF token.
 *
 * ## What was wrong
 *
 * Two invoices could be raised against one order line, each for the full quantity, and BOTH could be
 * issued: the customer was billed twice for one delivery. `OrderInvoicingService::invoiceFromOrder()`
 * does refuse a line above what is left — but at the moment the invoice is RAISED, measured against
 * `SalesOrder::uninvoicedQuantityFor()`, which counts only invoices that count. A draft counts for
 * nothing, so two drafts each for the whole line both passed: each saw the full remainder, because
 * the other was a draft. Nothing looked at the pair again, and the sell side has no exceptions screen
 * that would have surfaced the duplicate afterwards.
 *
 * ## What the ruling is, and what these tests are shaped to prove
 *
 * A draft holds nothing, and that stays true — the FIRST test here is the one that proves this fix
 * did not over-correct into a guard at save. The block is at ISSUE, and the refusal names the two
 * things a person can actually do: unlink the invoice from the order, or increase the order line.
 * Both remedies get a test that carries them out and shows the invoice then issues.
 *
 * ## How these tests are built
 *
 * Every assertion reads `table.column` back out of the database after the POST rather than trusting a
 * flash message, a redirect or an HTTP 200 — none of which is evidence that anything moved. Each
 * refusal is paired with an assertion that the rows which should NOT have changed did not: the first
 * invoice's stored figures, the order line, and the order's own status. A guard that refuses
 * everything would pass a test that only checked the refusal.
 *
 * Quantities come back from SQLite as numbers rather than as the decimal strings Doctrine wrote —
 * NUMERIC affinity converts '10.00' to 10 on the way in — so the comparisons are float-to-float
 * against an explicit expected value, which is the column's own content rather than a rendering of
 * it. Nothing here asserts a bare number with `see()` (#627); the only `see()` calls are on words.
 *
 * The order is created directly. It is the SETUP, not the operation under test: what is conducted
 * here is invoicing, and every invoice is raised through `/admin/invoice/create (order_id)` and issued
 * through `/admin/invoice/{id}/action/issue` exactly as a browser with JavaScript off would.
 */
final class AdminInvoiceIssueOverInvoicingCest
{
    private Company $company;
    private ProductCore $widget;

    /**
     * AppSettings caches its rows in a pool OUTSIDE the per-test transaction, so a snapshot taken
     * here survives the rollback and is read by whatever runs next. Every invoice numbers itself
     * through InvoiceNumberGenerator, which reads its prefix through that cache.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('over-invoicing-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Double Billing Wholesale')
            ->setCode('DBW-' . uniqid());
        $I->haveInRepository($this->company);

        $this->widget = (new ProductCore())
            ->setSku('DBW-WID-' . uniqid())
            ->setName('Over-invoiced Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->widget);
    }

    // ------------------------------------------------------------------ the load-bearing pair

    /**
     * Two drafts for the same order line both save, and neither is altered by the other.
     *
     * THE test that proves this fix did not over-correct. The obvious way to stop a line being
     * invoiced twice is to refuse the second document at save, and the owner ruled that out: a draft
     * is a person part way through deciding what to bill, two people drafting the same instalment is
     * a thing that happens, and it resolves itself when one of them is issued. So a guard at save
     * would be a worse defect than the one being fixed, and this is what would catch it.
     *
     * It also asserts the rows that must be untouched by a draft existing at all: the order line's
     * quantity, and the order's own status, which a draft does not move off Approved.
     */
    public function twoDraftsForOneOrderLineBothSaveAndNeitherDisturbsTheOther(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');

        $this->raiseInvoice($I, $context, '10', 'draft');
        $invoices = $this->invoiceIdsFor($I, $context['orderId']);
        $I->assertCount(1, $invoices, 'the first draft was not written at all');
        $first = $this->invoiceRow($I, $invoices[0]);
        $I->assertSame('Draft', $first['status']);
        $I->assertSame(50.0, (float) $first['total'], 'invoice.total of the first draft');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $invoices[0], $context['lineId']), 'invoice_line.quantity of the first draft');

        // The second draft against the SAME line, at the SAME full quantity.
        $this->raiseInvoice($I, $context, '10', 'draft');
        $invoices = $this->invoiceIdsFor($I, $context['orderId']);
        $I->assertCount(2, $invoices, 'the second draft was refused — a draft must hold nothing');

        $second = $this->invoiceRow($I, $invoices[1]);
        $I->assertSame('Draft', $second['status']);
        $I->assertSame(50.0, (float) $second['total'], 'invoice.total of the second draft');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $invoices[1], $context['lineId']), 'invoice_line.quantity of the second draft');

        // The row that should NOT have changed: the first draft, re-read from the database rather
        // than from an entity fetched earlier, which would answer from the identity map.
        $firstAfter = $this->invoiceRow($I, $invoices[0]);
        $I->assertSame('Draft', $firstAfter['status'], 'the first draft changed status when the second was raised');
        $I->assertSame(50.0, (float) $firstAfter['total'], 'the first draft\'s total changed when the second was raised');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $invoices[0], $context['lineId']), 'the first draft\'s quantity changed when the second was raised');

        // And nothing on the order moved either: a draft claims, reserves and consumes nothing.
        $I->assertSame(10.0, $this->orderLineQuantity($I, $context['lineId']), 'sales_order_line.quantity moved for a draft');
        $I->assertSame('Approved', $this->orderStatus($I, $context['orderId']), 'sales_order.status moved for a draft');
    }

    /**
     * The first issues and claims the line; the second is refused at issue, and the first's stored
     * figures are untouched by the refusal.
     *
     * This is the defect. Both invoices exist legitimately; issuing the second is what bills the
     * customer twice for one delivery.
     */
    public function theSecondInvoiceIsRefusedAtIssueAndTheFirstIsUntouched(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');
        $this->raiseInvoice($I, $context, '10', 'draft');
        $this->raiseInvoice($I, $context, '10', 'draft');
        [$firstId, $secondId] = $this->invoiceIdsFor($I, $context['orderId']);

        $this->issue($I, $firstId);
        $first = $this->invoiceRow($I, $firstId);
        $I->assertSame('Pending', $first['status'], 'the first invoice did not issue');
        $I->assertSame(50.0, (float) $first['total'], 'invoice.total after the first was issued');
        // Issuing is the claim, so the order now says it is invoiced. This is the ONLY reading of
        // "remaining to invoice" there is, and #31 did not add a second.
        $I->assertSame('Invoiced', $this->orderStatus($I, $context['orderId']));

        $this->issue($I, $secondId);

        // Refused: the second is still a draft ...
        $I->assertSame('Draft', $this->invoiceRow($I, $secondId)['status'], 'the second invoice issued, billing the line twice');

        // ... and the row that should NOT have changed did not.
        $firstAfter = $this->invoiceRow($I, $firstId);
        $I->assertSame('Pending', $firstAfter['status'], 'the FIRST invoice\'s status changed during the refusal');
        $I->assertSame(50.0, (float) $firstAfter['total'], 'the FIRST invoice\'s total changed during the refusal');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $firstId, $context['lineId']), 'the FIRST invoice\'s billed quantity changed during the refusal');
        $I->assertSame(10.0, $this->orderLineQuantity($I, $context['lineId']), 'sales_order_line.quantity changed during the refusal');

        // Nor did the refusal quietly bill the line anyway on the second document.
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $secondId, $context['lineId']), 'the refused invoice\'s own line was rewritten');
    }

    /**
     * The refusal names BOTH ways out, because they are the only two things a person can do about it.
     *
     * Asserted as sentences on the page that demonstrably rendered, not as a substring of a number:
     * a refusal that says only "cannot be issued" sends somebody hunting through the invoice screen
     * for a control that does not exist.
     */
    public function theRefusalNamesBothRemedies(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');
        $this->raiseInvoice($I, $context, '10', 'draft');
        $this->raiseInvoice($I, $context, '10', 'draft');
        [$firstId, $secondId] = $this->invoiceIdsFor($I, $context['orderId']);

        $this->issue($I, $firstId);
        $this->issue($I, $secondId);

        $I->see('cannot be issued');
        $I->see('left to invoice on order ' . $context['orderNumber']);
        // Remedy one, named with the order it would detach from.
        $I->see('Unlink this invoice from order ' . $context['orderNumber']);
        // Remedy two.
        $I->see('increase the order line');
        // And the page it said all that on is the invoice's own, which is where both controls live.
        $I->see('Unlink from this order');
    }

    // ------------------------------------------------------------------ the two ways out

    /**
     * Remedy one: detach the invoice from the sales order, and it issues.
     *
     * An invoice with no order draws down nothing, because there is nothing to draw down — which is
     * what a standalone invoice has always been. The order keeps the first invoice and stays exactly
     * as invoiced as it was.
     */
    public function unlinkingTheSecondInvoiceFromTheOrderLetsItIssue(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');
        $this->raiseInvoice($I, $context, '10', 'draft');
        $this->raiseInvoice($I, $context, '10', 'draft');
        [$firstId, $secondId] = $this->invoiceIdsFor($I, $context['orderId']);

        $this->issue($I, $firstId);
        $this->issue($I, $secondId);
        $I->assertSame('Draft', $this->invoiceRow($I, $secondId)['status'], 'the refusal this remedy answers did not happen');

        // The real control, on the real screen: "Unlink from this order".
        $I->amOnPage('/admin/invoice/detail/' . $secondId);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom(
            'form[action="/admin/invoice/' . $secondId . '/unlink"] input[name="_token"]',
            'value',
        );
        $I->sendFormPostRequest('/admin/invoice/' . $secondId . '/unlink', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertNull($this->invoiceRow($I, $secondId)['sales_order_id'], 'invoice.sales_order_id after unlinking');

        $this->issue($I, $secondId);
        $I->assertSame('Pending', $this->invoiceRow($I, $secondId)['status'], 'an invoice detached from its order still could not be issued');

        // The rows that should not have changed: the first invoice, and the order line it billed.
        $I->assertSame('Pending', $this->invoiceRow($I, $firstId)['status'], 'the first invoice changed while the second was being unlinked');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $firstId, $context['lineId']), 'the first invoice\'s billed quantity changed');
        $I->assertSame(10.0, $this->orderLineQuantity($I, $context['lineId']), 'sales_order_line.quantity changed while an invoice was unlinked');
    }

    /**
     * Remedy two: increase the sales order line, and the second invoice issues.
     *
     * The order genuinely grew — twenty were ordered, twenty are billed across two invoices — so this
     * is not a way around the rule, it is the rule being satisfied. The increase is made on the real
     * order edit screen, posting the line's own id so the row is UPDATED in place rather than deleted
     * and rebuilt; if the line were replaced, the invoices' attributions would point at nothing and
     * this test would pass for entirely the wrong reason. Its id is asserted, not just its quantity.
     */
    public function increasingTheOrderLineLetsTheSecondInvoiceIssue(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');
        $this->raiseInvoice($I, $context, '10', 'draft');
        $this->raiseInvoice($I, $context, '10', 'draft');
        [$firstId, $secondId] = $this->invoiceIdsFor($I, $context['orderId']);

        $this->issue($I, $firstId);
        $this->issue($I, $secondId);
        $I->assertSame('Draft', $this->invoiceRow($I, $secondId)['status'], 'the refusal this remedy answers did not happen');

        $I->amOnPage('/admin/order/edit/' . $context['orderId']);
        $I->seeResponseCodeIsSuccessful();
        $version = (string) $I->grabAttributeFrom('input.js-order-version', 'value');
        $I->sendFormPostRequest('/admin/order/edit/' . $context['orderId'], [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'version' => $version,
            'lines' => [
                [
                    'id' => (string) $context['lineId'],
                    'product_id' => (string) $this->widget->getId(),
                    'qty' => '20',
                    'price' => '5.00',
                ],
            ],
            'save_mode' => 'order',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(20.0, $this->orderLineQuantity($I, $context['lineId']), 'sales_order_line.quantity after the increase');

        $this->issue($I, $secondId);
        $I->assertSame('Pending', $this->invoiceRow($I, $secondId)['status'], 'the second invoice was still refused after the order line grew');

        // The first invoice is untouched by any of it, and both now bill the same enlarged line.
        $I->assertSame('Pending', $this->invoiceRow($I, $firstId)['status']);
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $firstId, $context['lineId']), 'the first invoice\'s billed quantity changed');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $secondId, $context['lineId']), 'the second invoice\'s billed quantity changed');
    }

    // ------------------------------------------------------------------ the positive control

    /**
     * A part invoice still works: 6 of 10 issues, then 4 of 10 issues, and 5 does not.
     *
     * Without this every assertion above would pass against a guard that refused every second issue.
     * The third leg matters as much as the second: once six and four are gone the line is full, and
     * an invoice for even part of it must still be refused.
     */
    public function aPartInvoiceStillWorksAndTheRemainderIsExact(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');

        $this->raiseInvoice($I, $context, '6', 'draft');
        [$sixId] = $this->invoiceIdsFor($I, $context['orderId']);
        $this->issue($I, $sixId);
        $I->assertSame('Pending', $this->invoiceRow($I, $sixId)['status'], 'the part invoice did not issue');
        $I->assertSame(6.0, $this->invoicedQuantityOn($I, $sixId, $context['lineId']), 'invoice_line.quantity of the part invoice');
        $I->assertSame('Partially Invoiced', $this->orderStatus($I, $context['orderId']));

        // Four is exactly what is left, and it issues.
        $this->raiseInvoice($I, $context, '4', 'draft');
        $ids = $this->invoiceIdsFor($I, $context['orderId']);
        $fourId = $ids[1];
        $this->issue($I, $fourId);
        $I->assertSame('Pending', $this->invoiceRow($I, $fourId)['status'], 'the remainder was refused, so the guard refuses what it should allow');
        $I->assertSame(4.0, $this->invoicedQuantityOn($I, $fourId, $context['lineId']), 'invoice_line.quantity of the remainder invoice');
        $I->assertSame('Invoiced', $this->orderStatus($I, $context['orderId']));

        // The row that should not have changed: the six, after the four was issued.
        $I->assertSame(6.0, $this->invoicedQuantityOn($I, $sixId, $context['lineId']), 'the first part invoice was altered by the second');

        // Five is not available and never was — but the create screen no longer refuses it at save
        // (#full-parity, 2026-09-13, matching Zoho: the screen-level ceiling is dropped entirely).
        // The third draft saves; only issuing it is refused, by OverInvoicingGuard, same as any
        // other over-quantity issue.
        $this->raiseInvoice($I, $context, '5', 'draft');
        $ids = $this->invoiceIdsFor($I, $context['orderId']);
        $I->assertCount(3, $ids, 'the third draft was accepted, not refused at save');
        $this->issue($I, $ids[2]);
        $I->assertSame('Draft', $this->invoiceRow($I, $ids[2])['status'], 'issuing it is refused, by the guard, not the create screen');
        $I->see('left to invoice on order ' . $context['orderNumber']);
    }

    /**
     * Five of ten, drafted while ten were still free, is refused at issue once six have gone.
     *
     * The partial version of the load-bearing case, and the half the raise-time check cannot reach:
     * when this draft was written there WAS room for it, so nothing was wrong with it then. What the
     * customer would be billed is decided by what is issued, and by the time it is issued the room is
     * gone.
     */
    public function aDraftRaisedBeforeTheLineFilledUpIsRefusedAtIssue(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');

        // Both drafted against an empty line: five and six each fit, and together they do not.
        $this->raiseInvoice($I, $context, '5', 'draft');
        $this->raiseInvoice($I, $context, '6', 'draft');
        [$fiveId, $sixId] = $this->invoiceIdsFor($I, $context['orderId']);

        $this->issue($I, $sixId);
        $I->assertSame('Pending', $this->invoiceRow($I, $sixId)['status'], 'the six did not issue');

        $this->issue($I, $fiveId);
        $I->assertSame('Draft', $this->invoiceRow($I, $fiveId)['status'], 'five issued against a line with only four left');
        $I->see('left to invoice on order ' . $context['orderNumber']);

        // The rows that should not have changed.
        $I->assertSame('Pending', $this->invoiceRow($I, $sixId)['status'], 'the issued invoice changed during the refusal');
        $I->assertSame(6.0, $this->invoicedQuantityOn($I, $sixId, $context['lineId']), 'the issued invoice\'s quantity changed during the refusal');
        $I->assertSame(10.0, $this->orderLineQuantity($I, $context['lineId']), 'sales_order_line.quantity changed during the refusal');
    }

    /**
     * An invoice that is ALREADY issued gets the state machine's refusal, not this guard's.
     *
     * The trap the guard fell into on its first draft. An issued invoice is one of its order's
     * counting invoices, so its own quantity is inside the remainder — run the guard on it and it
     * refuses the invoice for holding the quantity it legitimately holds, answering "nothing left to
     * invoice on this order" when the true answer is "this one is already issued". The check
     * therefore runs only from Draft, and the transition says the rest in its own words.
     */
    public function anAlreadyIssuedInvoiceIsRefusedByTheStateMachineAndNotByThisGuard(FunctionalTester $I): void
    {
        $context = $this->seed($I, '10.00');
        $this->raiseInvoice($I, $context, '10', 'issue');
        [$invoiceId] = $this->invoiceIdsFor($I, $context['orderId']);
        $I->assertSame('Pending', $this->invoiceRow($I, $invoiceId)['status'], 'the invoice was not issued on the way in');

        // Straight at the route: the screen stops offering Issue once it is issued, so this is the
        // scripted post, which is exactly the case that reaches the guard.
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/issue', ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIsSuccessful();

        $I->see('An invoice cannot go from Pending to Pending');
        $I->dontSee('left to invoice on order');
        $I->assertSame('Pending', $this->invoiceRow($I, $invoiceId)['status'], 'the refused re-issue moved the invoice');
        $I->assertSame(10.0, $this->invoicedQuantityOn($I, $invoiceId, $context['lineId']), 'the refused re-issue changed the billed quantity');
    }

    // ------------------------------------------------------------------ fixtures and readers

    /**
     * A company, a product and an APPROVED sales order for $quantity of it at $5.
     *
     * Ten and five are chosen so no figure in this file is a substring of another: 10, 50, 4, 6, 20,
     * 30. These tests assert columns rather than pages, but a test whose numbers collide is
     * unreadable either way (#627).
     *
     * @return array{orderId: int, lineId: int, orderNumber: string}
     */
    private function seed(FunctionalTester $I, string $quantity): array
    {
        $orderNumber = 'DBW-SO-' . strtoupper(substr(uniqid(), -8));
        $total = number_format((float) $quantity * 5.0, 2, '.', '');

        // Re-read from the entity manager rather than reusing the objects _before() built: driving a
        // screen boots a request, and a request may leave the manager cleared, at which point those
        // two are detached and Doctrine reads them as new entities to insert a second time.
        $em = $I->grabService(EntityManagerInterface::class);
        $company = $em->find(Company::class, $this->company->getId()) ?? $this->company;
        $widget = $em->find(ProductCore::class, $this->widget->getId()) ?? $this->widget;

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($orderNumber)
            ->setSubtotal($total)
            ->setTax('0.00')
            ->setTotal($total);

        $line = (new SalesOrderLine())
            ->setProduct($widget)
            ->setName($widget->getName())
            ->setSku((string) $widget->getSku())
            ->setQuantity($quantity)
            ->setPrice('5.00')
            ->setSubtotal($total);
        $order->addLine($line);

        // A live order. Since #539 stage 2 that is approve() on a Draft, not a status string.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        return [
            'orderId' => (int) $order->getId(),
            'lineId' => (int) $line->getId(),
            'productId' => (int) $widget->getId(),
            'orderNumber' => $orderNumber,
        ];
    }

    /**
     * Raises an invoice through the real screen: load Convert to Invoice, scrape its token, post it.
     *
     * $saveMode is the submit button's own value — 'draft' or 'issue' — because the screen has two
     * and they mean different things.
     *
     * @param array{orderId: int, lineId: int, productId: int, orderNumber: string} $context
     */
    private function raiseInvoice(FunctionalTester $I, array $context, string $quantity, string $saveMode): void
    {
        $I->amOnPage('/admin/invoice/create?order_id=' . $context['orderId']);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $context['orderId'], [
            '_token' => $token,
            'lines' => [
                // A real form always posts the pre-filled price box's value too, whether or not the
                // admin touched it — $5.00 is the order line's own price, matching the fixture.
                ['product_id' => (string) $context['productId'], 'sales_order_line_id' => (string) $context['lineId'], 'qty' => $quantity, 'price' => '5.00'],
            ],
            'save_mode' => $saveMode,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * Presses Issue on the invoice's own screen.
     *
     * The token is scraped from THAT form rather than from anywhere on the page, so a test whose
     * button has quietly stopped being rendered fails here instead of asserting nothing.
     */
    private function issue(FunctionalTester $I, int $invoiceId): void
    {
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $action = '/admin/invoice/' . $invoiceId . '/action/issue';
        $token = (string) $I->grabAttributeFrom('form[action="' . $action . '"] input[name="_token"]', 'value');

        $I->sendFormPostRequest($action, ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** @return list<int> every invoice linked to the order, oldest first */
    private function invoiceIdsFor(FunctionalTester $I, int $orderId): array
    {
        return array_map('intval', $this->connection($I)->fetchFirstColumn(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id',
            [$orderId],
        ));
    }

    /** @return array<string, mixed> */
    private function invoiceRow(FunctionalTester $I, int $invoiceId): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT id, status, subtotal, total, sales_order_id FROM invoice WHERE id = ?',
            [$invoiceId],
        );

        return \is_array($row) ? $row : [];
    }

    /** What one invoice bills against one order line, as the database holds it. */
    private function invoicedQuantityOn(FunctionalTester $I, int $invoiceId, int $orderLineId): float
    {
        return (float) $this->connection($I)->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM invoice_line WHERE invoice_id = ? AND sales_order_line_id = ?',
            [$invoiceId, $orderLineId],
        );
    }

    private function orderLineQuantity(FunctionalTester $I, int $orderLineId): float
    {
        return (float) $this->connection($I)->fetchOne(
            'SELECT quantity FROM sales_order_line WHERE id = ?',
            [$orderLineId],
        );
    }

    private function orderStatus(FunctionalTester $I, int $orderId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$orderId]);
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
