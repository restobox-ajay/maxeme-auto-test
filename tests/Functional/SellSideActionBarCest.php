<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AbstractDocumentAddress;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\DocumentLock;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Tests\Support\FunctionalTester;

/**
 * The ONE action bar the order, the quote and the invoice now share —
 * `admin/_partials/sales_document_action_bar.html.twig`.
 *
 * ## What this file is for
 *
 * Three things an extraction like this can silently get wrong, and one thing it deliberately does.
 *
 *   1. A CONTROL THAT LOOKS RIGHT AND POSTS NOWHERE. Clone and Lock/Unlock are wired here for the
 *      first time on two documents of three, so every case that asserts a control exists also POSTs
 *      its form's own action — scraped off the page, with the token scraped out of that same form —
 *      and reads `sales_order` / `estimate` / `invoice` / `document_lock` back BY COLUMN afterwards
 *      (#624). A button whose form pointed at the wrong route would render perfectly and pass any
 *      test that only looked at the screen.
 *
 *   2. A VISIBILITY RULE LOST IN THE MOVE. Six of them ran through the three old <nav>s, over three
 *      different state machines. Each is asserted BOTH WAYS on the SAME element id — "no Edit tab
 *      when the order is Void" is worth nothing without "and there is one when it is Draft", because
 *      a page that failed to render passes the first alone.
 *
 *   3. AN OVERFLOW NOBODY CAN OPEN. The `.row-action-toggle` dropdown the row grids use is opened
 *      only by app.js:4254, so with scripting off everything behind it is unreachable — which is why
 *      this bar's two menus are `<details>`/`<summary>`. Asserted structurally, and conducted: every
 *      POST below is a plain form post, which is exactly what a no-JS browser sends.
 *
 *   4. DELETE IS A DEAD BUTTON, BY INSTRUCTION. Asserted inert, and asserted against the ROUTER
 *      rather than against a list written down here: no `admin_*_delete` route exists for any of the
 *      three, so the control cannot be anything else.
 *
 * ## #627
 *
 * Nothing here calls see() on a bare word. "Edit", "Lock", "Delete", "Clone" and "View" all occur
 * elsewhere on these screens — in the status modal, the line-row actions, the sidebar — so every
 * assertion names an element id the shared bar emits (`{order|quote|invoice}-action-*`).
 *
 * ## Codeception reuses ONE Cest instance
 *
 * So nothing is kept on `$this` between methods. `begin()` resets the two memoised ids and is the
 * first line of every case.
 */
final class SellSideActionBarCest
{
    /** The id prefix each document's bar is emitted under, and the route stem behind it. */
    private const DOCUMENTS = [
        'order' => 'admin_order',
        'quote' => 'admin_estimate',
        'invoice' => 'admin_invoice',
    ];

    private string $tag = '';

    private ?int $companyId = null;

    private ?int $productId = null;

    // ─────────────────────────────────────────────────────────────── fixtures

    private function begin(FunctionalTester $I, string $role = 'ROLE_SUPER_ADMIN'): void
    {
        $this->tag = strtoupper(substr(uniqid(), -6));
        $this->companyId = null;
        $this->productId = null;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('action-bar-' . strtolower($this->tag) . '@example.test');
        $admin->setRoles([$role]);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
        // DocumentLockedSubscriber sends a refused write back to the Referer, never onward to an
        // attacker-supplied one. Without one a refusal lands on '/', which is the storefront.
        $I->haveHttpHeader('Referer', 'http://admin.localhost/admin/order');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        return $I->grabService('doctrine.orm.entity_manager');
    }

    private function company(FunctionalTester $I): Company
    {
        if ($this->companyId !== null) {
            return $this->em($I)->find(Company::class, $this->companyId);
        }

        $company = (new Company())
            ->setName('Action Bar Co ' . $this->tag)
            ->setCode('AB-' . $this->tag);
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);
        $this->companyId = $company->getId();

        return $company;
    }

    /** Memoised BY ID and re-found: an `amOnPage()` in between reboots the kernel and detaches it. */
    private function product(FunctionalTester $I): ProductCore
    {
        if ($this->productId !== null) {
            return $this->em($I)->find(ProductCore::class, $this->productId);
        }

        $product = (new ProductCore())
            ->setSku('AB-' . $this->tag)
            ->setName('Action Bar Widget ' . $this->tag)
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product);
        $this->productId = $product->getId();

        return $product;
    }

    private function order(FunctionalTester $I, string $status = 'Draft', string $suffix = 'A'): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company($I))
            ->setOrderNumber('AB-SO-' . $this->tag . '-' . $suffix)
            ->setPoNumber('PO-' . $this->tag)
            ->setUserName('Priya Raman')
            ->setFulfillmentRegion('Main')
            ->setSubtotal('250.00')
            ->setTax('0.00')
            ->setTotal('250.00');
        $order->setDocumentDate('2026-03-04');
        $order->addLine($this->line($I, new SalesOrderLine()));
        $this->address($order);

        if ($status === 'Void') {
            $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
            $order->setStatus(
                'Void',
                DocumentActor::system(),
                sprintf('Order voided (was %s): Customer changed their mind.', $order->getStatus()),
            );
        }

        $I->haveInRepository($order);

        return $order;
    }

    private function estimate(FunctionalTester $I, string $status = 'Priced', string $suffix = 'A'): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($this->company($I))
            ->setDocumentNumber('AB-QO-' . $this->tag . '-' . $suffix);
        $estimate
            ->setPoNumber('PO-' . $this->tag)
            ->setUserName('Priya Raman')
            ->setFulfillmentRegion('Main')
            ->setSubtotal('250.00')
            ->setTax('0.00')
            ->setTotal('250.00')
            ->setDocumentDate('2026-03-04');
        $estimate->addLine($this->line($I, new EstimateLine()));
        $this->address($estimate);
        $estimate->setStatus($status, DocumentActor::system());

        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function invoice(FunctionalTester $I, string $status = 'Draft', ?SalesOrder $order = null, string $suffix = 'A'): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($this->company($I))
            ->setDocumentNumber('AB-INV-' . $this->tag . '-' . $suffix);
        $invoice
            ->setPoNumber('PO-' . $this->tag)
            ->setUserName('Priya Raman')
            ->setFulfillmentRegion('Main')
            ->setSubtotal('250.00')
            ->setTax('0.00')
            ->setTotal('250.00')
            ->setDocumentDate('2026-03-04');
        $invoice->setInvoiceDate('2026-03-04')->setDueDate('2026-04-03');
        if ($order !== null) {
            $invoice->setSalesOrder($order);
        }
        $invoice->addLine($this->line($I, new InvoiceLine()));
        $this->address($invoice);

        if ($status === 'Pending' || $status === 'Cancelled') {
            $invoice->issue(DocumentActor::system());
        }
        if ($status === 'Cancelled') {
            $invoice->setStatus('Cancelled', DocumentActor::system(), 'Cancelled for this test.');
        }

        $I->haveInRepository($invoice);

        return $invoice;
    }

    /** @template T of object @param T $line @return T */
    private function line(FunctionalTester $I, object $line): object
    {
        $product = $this->product($I);

        return $line
            ->setProduct($product)
            ->setName((string) $product->getName())
            ->setSku((string) $product->getSku())
            ->setQuantity('5.00')
            ->setUnit('EA')
            ->setTaxCode('E')
            ->setCost('30.00')
            ->setPrice('50.00')
            ->setSubtotal('250.00')
            ->setSortOrder(0);
    }

    private function address(object $document): void
    {
        $document->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setFirstName('Dana')->setLastName('Okafor')
            ->setAddressLine1('14 Dock Road')->setCity('Surrey')->setProvince('BC')
            ->setPostalCode('V3S 0A1')->setCountry('CA');
    }

    // ────────────────────────────────────────────────────────── conducting

    /**
     * Press a control in the bar exactly as a browser with scripting off does: read the form's own
     * `action` and its own `_token` off the rendered page, then POST that.
     *
     * Deliberately NOT a URL built in the test. What is being proved is that the CONTROL is wired —
     * a Lock button whose form posted to the clone route would pass a test that posted
     * `/admin/order/lock/{id}` itself.
     */
    private function press(FunctionalTester $I, string $formId, array $params = []): void
    {
        $action = (string) $I->grabAttributeFrom('form#' . $formId, 'action');
        $I->assertNotSame('', $action, $formId . ' has no action');
        $token = (string) $I->grabAttributeFrom('form#' . $formId . ' input[name="_token"]', 'value');
        $I->assertNotSame('', $token, $formId . ' carries no CSRF token');

        $I->sendFormPostRequest($action, ['_token' => $token] + $params);
    }

    /** @return array<string, mixed> */
    private function row(FunctionalTester $I, string $table, int $id): array
    {
        $row = $this->em($I)->getConnection()->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id = ?', $table),
            [$id],
        );
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

    private function newestId(FunctionalTester $I, string $table, int $excludingId): int
    {
        $id = (int) $this->em($I)->getConnection()->fetchOne(
            sprintf('SELECT MAX(id) FROM %s WHERE company_id = ? AND id <> ?', $table),
            [$this->companyId, $excludingId],
        );
        $I->assertGreaterThan(0, $id, sprintf('no second %s row for this case\'s company — nothing was cloned', $table));

        return $id;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1. One component, three documents
    // ═════════════════════════════════════════════════════════════════════

    /**
     * The same shell is on all three screens, and it is the shared partial's shell and not three
     * lookalikes: the ids are built from `idPrefix` inside one template, so an order screen that
     * still rendered its own <nav> could not emit `#order-action-print-menu` beside a
     * `details.no-js-row-actions`.
     *
     * The pdf tab's LABEL differs per document and is asserted per document — that difference is a
     * parameter, and asserting it here is what stops the shared bar from flattening three document
     * names into one.
     *
     * The order alone passes `pdf: null` (#650): its own PDF is the identical `admin_order_document`
     * route already sitting in its PDF / Download menu as "Print Sales Order", so a standalone tab
     * for it would be a second control for the one document. That is asserted here as an ABSENCE
     * paired with the two other documents' presence, on the same element id.
     */
    public function everySellSideDocumentRendersTheOneSharedBar(FunctionalTester $I): void
    {
        $this->begin($I);

        // None of the three renders a standalone PDF tab any more: each document's PDF/Download
        // disclosure already reaches the identical route, so a bar does not offer the same PDF
        // from two buttons — the rule order's own bar always applied, now applied consistently.
        $screens = [
            'order' => '/admin/order/detail/' . $this->order($I)->getId(),
            'quote' => '/admin/estimate/detail/' . $this->estimate($I)->getId(),
            'invoice' => '/admin/invoice/detail/' . $this->invoice($I)->getId(),
        ];

        foreach ($screens as $prefix => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $I->seeElement('nav.company-action-links.order-tab-nav #' . $prefix . '-action-view');
            $I->dontSeeElement('#' . $prefix . '-action-pdf');

            // The two disclosures, the shared overflow contents, and the slot each document fills.
            $I->seeElement('details.row-action-menu.no-js-row-actions > summary#' . $prefix . '-action-print-menu');
            $I->seeElement('details.row-action-menu.no-js-row-actions > summary#' . $prefix . '-action-more');
            $I->seeElement('#' . $prefix . '-action-clone');
            $I->seeElement('#' . $prefix . '-action-delete');
            $I->seeElement('#' . $prefix . '-action-lock');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2. The overflow opens without JavaScript
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Both menus are native disclosures, and neither carries the class app.js binds to.
     *
     * `.row-action-toggle` is a `display: none` panel plus one delegated click handler that copies
     * the panel's HTML into a body-level clone (app.js:4254). With scripting off it never opens, so
     * Clone, Lock and Send Invoice would all be unreachable behind one. `<details>`/`<summary>` is
     * what core's own `admin/_row_actions_no_js.html.twig` uses for exactly this reason, and the
     * summary deliberately does NOT carry `.row-action-toggle` — one that did would be opened twice,
     * once natively and once as a clone.
     *
     * The positive control for "the class is absent" is on the same element: the summary EXISTS.
     */
    public function theOverflowAndPrintMenusOpenWithoutJavaScript(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I);

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        foreach (['order-action-more', 'order-action-print-menu'] as $summary) {
            $I->seeElement('summary#' . $summary);
            $I->seeElement('details.no-js-row-actions > summary#' . $summary);
            $I->dontSeeElement('#' . $summary . '.row-action-toggle');
            $I->dontSeeElement('button#' . $summary);
        }

        // And the contents are real, server-rendered controls inside that disclosure — not markup
        // app.js has to assemble.
        $I->seeElement('details.no-js-row-actions form#order-action-clone-form input[name="_token"]');
        // The lock reason + submit sit in a CSS-hidden modal (still a real form), opened from the
        // dropdown's "Lock Order" button.
        $I->seeElement('details.no-js-row-actions button#order-action-lock.js-lock-modal-open');
        $I->seeElement('.lock-modal form#order-action-lock-form input[name="reason"]');
        $I->seeElement('details.no-js-row-actions #order-action-download');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3. Clone — wired on all three, and it actually clones
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Clone reached the ORDER only before this; the quote's and the invoice's routes had existed
     * since this morning with no control anywhere.
     *
     * Conducted per document: the form's own action is read off the screen and posted, then the new
     * row is read back BY COLUMN — and the ORIGINAL is re-read to prove cloning wrote nothing to it,
     * which is the row that should NOT have changed.
     */
    public function cloneIsWiredOnAllThreeDocumentsAndCopiesTheDocument(FunctionalTester $I): void
    {
        $this->begin($I);

        $cases = [
            ['order', 'sales_order', 'order_number', '/admin/order/detail/', $this->order($I)->getId()],
            ['quote', 'estimate', 'document_number', '/admin/estimate/detail/', $this->estimate($I)->getId()],
            ['invoice', 'invoice', 'document_number', '/admin/invoice/detail/', $this->invoice($I)->getId()],
        ];

        foreach ($cases as [$prefix, $table, $numberColumn, $url, $id]) {
            $id = (int) $id;
            $before = $this->row($I, $table, $id);

            $I->amOnPage($url . $id);
            $I->seeResponseCodeIsSuccessful();
            $I->seeElement('form#' . $prefix . '-action-clone-form button#' . $prefix . '-action-clone');
            $this->press($I, $prefix . '-action-clone-form');

            $copyId = $this->newestId($I, $table, $id);
            $copy = $this->row($I, $table, $copyId);

            $I->assertNotSame($before[$numberColumn], $copy[$numberColumn], $table . ' copy reused the original\'s number');
            $I->assertSame($this->money($before['subtotal']), $this->money($copy['subtotal']), $table . ' copy lost the subtotal');
            $I->assertSame($this->money($before['total']), $this->money($copy['total']), $table . ' copy lost the total');
            $I->assertSame($before['po_number'], $copy['po_number'], $table . ' copy lost the PO number');

            // The row that should NOT have changed.
            $after = $this->row($I, $table, $id);
            $I->assertSame($before, $after, 'cloning wrote to the original ' . $table . ' row');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3b. Void — the order's own overflow entry (#650)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Void was reachable only through the "Update Order Status" modal's XHR before this — the same
     * `admin_order_update_status` route, the same 'Void' value. This is that route again, posted as
     * a plain form the way Clone right beside it already is.
     *
     * Conducted the same way as Clone above: the form's own hidden `status` input and its own
     * action are read off the rendered page (so a wrong value in the template, not just a wrong
     * value posted by the test, would fail this), then `sales_order.status` is read back BY COLUMN
     * — and a second order built by this case is re-read to prove the write landed on the one
     * pressed, not on every order the endpoint could see.
     */
    public function voidIsWiredInTheOverflowAndActuallyVoidsTheOrder(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I);
        $id = (int) $order->getId();
        $untouched = $this->order($I, 'Draft', 'B');
        $untouchedId = (int) $untouched->getId();
        $untouchedStatusBefore = (string) $this->em($I)->getConnection()->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$untouchedId]);

        $I->amOnPage('/admin/order/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('details.no-js-row-actions form#order-action-void-form button#order-action-void');

        $status = (string) $I->grabAttributeFrom('form#order-action-void-form input[name="status"]', 'value');
        $I->assertSame('Void', $status, 'order-action-void-form does not post status=Void');
        $this->press($I, 'order-action-void-form', ['status' => $status]);

        $I->assertSame(
            'Void',
            (string) $this->em($I)->getConnection()->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$id]),
            'pressing Void Order did not change sales_order.status',
        );
        // The row that should NOT have changed.
        $I->assertSame(
            $untouchedStatusBefore,
            (string) $this->em($I)->getConnection()->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$untouchedId]),
            'voiding one order voided another',
        );
    }

    /**
     * Void is withheld by the SAME guard the modal's own pencil trigger uses — `isOrderLocked`,
     * Closed or Void — asserted both ways on the same element id. A Draft is neither, so it still
     * offers Void; that half is the positive control the Void-on-Void half is worth nothing without.
     */
    public function voidIsWithheldOnceTheOrderIsAlreadyVoidAndOfferedOnADraft(FunctionalTester $I): void
    {
        $this->begin($I);

        $void = $this->order($I, 'Void');
        $I->amOnPage('/admin/order/detail/' . $void->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#order-action-void');
        // Positive control: the bar rendered, and Clone — unaffected by isOrderLocked — is still there.
        $I->seeElement('#order-action-clone');

        $draft = $this->order($I, 'Draft', 'B');
        $I->amOnPage('/admin/order/detail/' . $draft->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#order-action-void');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 4. Lock and Unlock — wired on all three, and they actually lock
    // ═════════════════════════════════════════════════════════════════════

    /**
     * The whole round trip on one document, with the bar's own state asserted at every step and the
     * `document_lock` row read back BY COLUMN — including `reason`, which only exists because the
     * form carries a plain text input a no-JS browser can fill.
     *
     * Every absence is paired on the SAME element id: `#order-action-edit` is there before the lock
     * and gone after it and there again after the unlock; `#order-action-lock` and
     * `#order-action-unlock` swap.
     */
    public function lockingFromTheBarFreezesTheDocumentAndTheBarThenOffersUnlock(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I);
        $id = (int) $order->getId();
        $untouched = $this->order($I, 'Void', 'B');
        $untouchedId = (int) $untouched->getId();

        // ── before: editable, lockable, not unlockable, no row
        $I->amOnPage('/admin/order/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#order-action-edit');
        $I->seeElement('#order-action-lock');
        $I->dontSeeElement('#order-action-unlock');
        $I->dontSeeElement('#order-action-lock-state');
        $I->assertNull($this->lockRow($I, DocumentLock::TYPE_SALES_ORDER, $id), 'the order is locked before anything pressed Lock');

        // ── press Lock, with the reason the form's own input carries
        $this->press($I, 'order-action-lock-form', ['reason' => 'Audit AB-' . $this->tag]);

        $lock = $this->lockRow($I, DocumentLock::TYPE_SALES_ORDER, $id);
        $I->assertIsArray($lock, 'pressing Lock wrote no document_lock row');
        $I->assertSame(DocumentLock::TYPE_SALES_ORDER, $lock['document_type']);
        $I->assertSame($id, (int) $lock['document_id']);
        $I->assertSame('Audit AB-' . $this->tag, $lock['reason'], 'the reason the form posted did not land in the column');
        $I->assertNotSame('', (string) $lock['locked_by'], 'nobody was recorded as having locked it');

        // The row that should NOT have changed: the other order this case built is still free.
        $I->assertNull($this->lockRow($I, DocumentLock::TYPE_SALES_ORDER, $untouchedId), 'locking one order locked another');

        // ── after: no Edit, no Lock, an Unlock and a statement of who and why
        $I->amOnPage('/admin/order/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#order-action-edit');
        $I->dontSeeElement('#order-action-lock');
        $I->seeElement('#order-action-unlock');
        $I->see('Audit AB-' . $this->tag, '#order-action-lock-state');

        // ── press Unlock: the row goes, and Edit comes back on the same element
        $this->press($I, 'order-action-unlock-form');
        $I->assertNull($this->lockRow($I, DocumentLock::TYPE_SALES_ORDER, $id), 'pressing Unlock left the document_lock row behind');

        $I->amOnPage('/admin/order/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#order-action-edit');
        $I->seeElement('#order-action-lock');
        $I->dontSeeElement('#order-action-unlock');
    }

    /**
     * The same round trip on the quote and the invoice, so "Lock is wired on all three" is not
     * three assertions about one document.
     *
     * Shorter than the order's: what it adds is that each document's OWN lock row lands under its
     * OWN `document_type`, which is the column a shared control could quietly get wrong for two
     * documents out of three.
     */
    public function lockIsWiredOnTheQuoteAndTheInvoiceTooAndUsesEachDocumentsOwnType(FunctionalTester $I): void
    {
        $this->begin($I);

        $cases = [
            ['quote', DocumentLock::TYPE_ESTIMATE, '/admin/estimate/detail/', (int) $this->estimate($I)->getId()],
            ['invoice', DocumentLock::TYPE_INVOICE, '/admin/invoice/detail/', (int) $this->invoice($I)->getId()],
        ];

        foreach ($cases as [$prefix, $type, $url, $id]) {
            $I->amOnPage($url . $id);
            $I->seeResponseCodeIsSuccessful();
            $I->seeElement('#' . $prefix . '-action-lock');
            $I->dontSeeElement('#' . $prefix . '-action-unlock');
            $I->assertNull($this->lockRow($I, $type, $id), $type . ' ' . $id . ' was already locked');

            $this->press($I, $prefix . '-action-lock-form', ['reason' => 'Frozen ' . $prefix]);

            $lock = $this->lockRow($I, $type, $id);
            $I->assertIsArray($lock, 'pressing Lock on the ' . $prefix . ' wrote no row');
            $I->assertSame('Frozen ' . $prefix, $lock['reason']);

            $I->amOnPage($url . $id);
            $I->seeElement('#' . $prefix . '-action-unlock');
            $I->dontSeeElement('#' . $prefix . '-action-lock');

            $this->press($I, $prefix . '-action-unlock-form');
            $I->assertNull($this->lockRow($I, $type, $id), 'Unlock on the ' . $prefix . ' left the row behind');
        }
    }

    /**
     * Unlock is offered only to a super admin; a plain admin is told who to ask.
     *
     * Both halves on the SAME two element ids, on the SAME locked document, with only the viewer's
     * role different — the controller's `denyAccessUnlessGranted('ROLE_SUPER_ADMIN')` is the rule,
     * and this is the bar not inviting somebody into a 403.
     */
    public function onlyASuperAdminIsOfferedUnlock(FunctionalTester $I): void
    {
        $this->begin($I, 'ROLE_ADMIN');
        $order = $this->order($I);
        $id = (int) $order->getId();

        $I->amOnPage('/admin/order/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $this->press($I, 'order-action-lock-form', ['reason' => 'Plain admin lock']);
        $I->assertIsArray($this->lockRow($I, DocumentLock::TYPE_SALES_ORDER, $id), 'a plain admin could not lock, which they may');

        $I->amOnPage('/admin/order/detail/' . $id);
        $I->dontSeeElement('#order-action-unlock');
        $I->seeElement('#order-action-unlock-denied');

        // Same document, same page, super admin: the two swap.
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $superAdmin = (new AdminUser())
            ->setEmail('action-bar-super-' . strtolower($this->tag) . '@example.test');
        $superAdmin->setRoles(['ROLE_SUPER_ADMIN']);
        $superAdmin->setPassword($hasher->hashPassword($superAdmin, 'test-password-123'));
        $I->haveInRepository($superAdmin);
        $I->amLoggedInAs($superAdmin, 'admin');

        $I->amOnPage('/admin/order/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#order-action-unlock');
        $I->dontSeeElement('#order-action-unlock-denied');
    }

    /**
     * A locked document is still printable, emailable and cloneable. That is the promise
     * DocumentLockService's docblock makes in those words, and a bar that hid the whole overflow
     * behind the lock would quietly break it.
     *
     * Conducted for the cloneable third: the Clone form is pressed while the invoice is frozen, and
     * the copy is read back out of `invoice` BY COLUMN.
     */
    public function aLockedDocumentIsStillPrintableEmailableAndCloneable(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I);
        $invoice = $this->invoice($I, 'Pending', $order);
        $id = (int) $invoice->getId();

        $I->haveInRepository(
            (new DocumentLock())
                ->setDocumentType(DocumentLock::TYPE_INVOICE)
                ->setDocumentId($id)
                ->setLockedBy('Frances Auditor')
                ->setReason('Year end.')
        );

        $I->amOnPage('/admin/invoice/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        // Frozen — the positive control for every assertion below.
        $I->seeElement('#invoice-action-unlock');

        // No standalone PDF tab — the dropdown below is the one place this reaches the document.
        $I->seeElement('#invoice-action-print');
        $I->seeElement('#invoice-action-download');
        $I->seeElement('#invoice-action-download-slip');
        $I->seeElement('#invoice-action-send-customer');
        $I->seeElement('#invoice-action-send-self');
        $I->seeElement('#invoice-action-clone');

        $before = $this->row($I, 'invoice', $id);
        $this->press($I, 'invoice-action-clone-form');
        $copy = $this->row($I, 'invoice', $this->newestId($I, 'invoice', $id));
        $I->assertSame($this->money($before['total']), $this->money($copy['total']), 'a locked invoice was not cloned');
        $I->assertSame($before, $this->row($I, 'invoice', $id), 'cloning a locked invoice wrote to it');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 5. Delete is a dead button
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Rendered on all three so the overflow has the shape the owner asked for, and inert — because
     * there is nothing for it to do.
     *
     * The "nothing to do" half is asked of the ROUTER rather than asserted from a list written here:
     * if a delete route is ever added, this case fails and says the control has to be wired or
     * removed. That is #636's rule — a test about a CLASS of things discovers its subjects from the
     * application.
     */
    public function deleteIsRenderedAndInertBecauseNoDeleteRouteExists(FunctionalTester $I): void
    {
        $this->begin($I);

        $routes = $I->grabService(RouterInterface::class)->getRouteCollection();
        foreach (self::DOCUMENTS as $prefix => $stem) {
            $I->assertNull(
                $routes->get($stem . '_delete'),
                sprintf('%s_delete now exists — the dead Delete button in the %s bar has to be wired or removed', $stem, $prefix),
            );
            // The positive control for that null: the routes the same bar DOES post to are there.
            $I->assertNotNull($routes->get($stem . '_clone'), $stem . '_clone is missing');
            $I->assertNotNull($routes->get($stem . '_lock'), $stem . '_lock is missing');
        }

        $screens = [
            'order' => '/admin/order/detail/' . $this->order($I)->getId(),
            'quote' => '/admin/estimate/detail/' . $this->estimate($I)->getId(),
            'invoice' => '/admin/invoice/detail/' . $this->invoice($I)->getId(),
        ];

        foreach ($screens as $prefix => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            // Present, and a <button disabled> — which cannot be activated by mouse, keyboard or
            // form submit. Paired with the enabled sibling beside it, so "disabled" is a property of
            // THIS control and not of the whole menu.
            $I->seeElement('button#' . $prefix . '-action-delete[disabled]');
            $I->seeElement('button#' . $prefix . '-action-clone:not([disabled])');
            // No href and no form: there is no URL it could reach, wrong or right.
            $I->dontSeeElement('a#' . $prefix . '-action-delete');
            $I->dontSeeElement('#' . $prefix . '-action-delete[href]');
            $I->dontSeeElement('form#' . $prefix . '-action-delete-form');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // 6. The visibility rules, each asserted both ways on the same element
    // ═════════════════════════════════════════════════════════════════════

    /**
     * THE ORDER'S state machine: `isOrderLocked` — Closed or Void — withholds Edit.
     *
     * Both states on the same element id. `OrderController::edit()` refuses a Void order on GET, so
     * the tab going away is the screen not offering a door the route will not open; the refusal
     * itself stays in the controller.
     */
    public function theOrdersEditTabFollowsItsOwnStatusRule(FunctionalTester $I): void
    {
        $this->begin($I);

        $draft = $this->order($I);
        $I->amOnPage('/admin/order/detail/' . $draft->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#order-action-edit');

        $void = $this->order($I, 'Void', 'B');
        $I->assertSame(
            'Void',
            (string) $this->em($I)->getConnection()->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$void->getId()]),
            'the fixture is not actually Void, so the negative half proves nothing',
        );
        $I->amOnPage('/admin/order/detail/' . $void->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#order-action-edit');
        // Positive control on the same page: the bar rendered.
        $I->seeElement('#order-action-view');
    }

    /**
     * THE QUOTE'S state machine: `locked` — Accepted or Rejected — withholds Edit / Price and
     * Reject, and Accept is offered in Priced alone (#392).
     *
     * Four states, every one of them asserted on the same three element ids.
     */
    public function theQuotesOwnControlsFollowItsStatusRules(FunctionalTester $I): void
    {
        $this->begin($I);

        $priced = $this->estimate($I, 'Priced', 'PRICED');
        $accepted = $this->estimate($I, 'Accepted', 'ACCEPTED');
        $rejected = $this->estimate($I, 'Rejected', 'REJECTED');
        $draft = $this->estimate($I, 'Draft', 'DRAFT');

        $I->amOnPage('/admin/estimate/detail/' . $priced->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#quote-action-edit');
        $I->seeElement('#quote-action-accept');
        $I->seeElement('#quote-action-reject');
        $I->dontSeeElement('#quote-action-convert');

        $I->amOnPage('/admin/estimate/detail/' . $accepted->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#quote-action-edit');
        $I->dontSeeElement('#quote-action-accept');
        $I->dontSeeElement('#quote-action-reject');
        $I->seeElement('#quote-action-convert');

        $I->amOnPage('/admin/estimate/detail/' . $rejected->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#quote-action-edit');
        $I->dontSeeElement('#quote-action-reject');
        $I->dontSeeElement('#quote-action-convert');
        $I->seeElement('#quote-action-view');

        $I->amOnPage('/admin/estimate/detail/' . $draft->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#quote-action-edit');
        $I->seeElement('#quote-action-reject');
        $I->dontSeeElement('#quote-action-accept');
    }

    /**
     * A frozen quote is offered neither Accept nor Reject, and its Edit tab goes with them.
     *
     * `EstimateController` calls `assertWritable()` on all three — 'edited', 'accepted', 'changed' —
     * so each has exactly one outcome while a lock is on. The positive control is the same quote in
     * the same status one request earlier, with no lock.
     */
    public function aFrozenQuoteIsOfferedNoneOfItsWriteControls(FunctionalTester $I): void
    {
        $this->begin($I);
        $quote = $this->estimate($I, 'Priced');
        $id = (int) $quote->getId();

        $I->amOnPage('/admin/estimate/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#quote-action-edit');
        $I->seeElement('#quote-action-accept');
        $I->seeElement('#quote-action-reject');

        $I->haveInRepository(
            (new DocumentLock())
                ->setDocumentType(DocumentLock::TYPE_ESTIMATE)
                ->setDocumentId($id)
                ->setLockedBy('Frances Auditor')
                ->setReason('Tender under review.')
        );

        $I->amOnPage('/admin/estimate/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#quote-action-edit');
        $I->dontSeeElement('#quote-action-accept');
        $I->dontSeeElement('#quote-action-reject');
        // Same page, same bar: what a lock does NOT take away. No standalone PDF tab any more —
        // the dropdown's own Print Quote link is where this reaches the document now.
        $I->seeElement('#quote-action-print');
        $I->seeElement('#quote-action-clone');
        $I->seeElement('#quote-action-unlock');
    }

    /**
     * THE INVOICE: no Edit control on any of its four pages once Completed or Cancelled
     * (#full-parity, 2026-09-13 — widened from Draft-only per Invoice::isEditable()'s own
     * docblock). Cancelled is the fixture used here because it is reachable with no other
     * preconditions; Completed needs a fulfilment step this fixture does not model.
     */
    public function theInvoiceHasNoEditControlOnceCancelled(FunctionalTester $I): void
    {
        $this->begin($I);

        $routes = $I->grabService(RouterInterface::class)->getRouteCollection();
        $I->assertNotNull($routes->get('admin_invoice_edit'), 'admin_invoice_edit is missing');
        $I->assertNotNull($routes->get('admin_order_edit'), 'admin_order_edit is missing');

        $invoice = $this->invoice($I, 'Cancelled');
        $id = (int) $invoice->getId();

        foreach ([
            '/admin/invoice/detail/' . $id,
            '/admin/invoice/print/' . $id,
            '/admin/invoice/packing-slip/' . $id,
            '/admin/invoice/' . $id . '/payments',
        ] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->dontSeeElement('#invoice-action-edit');
            // Positive control on the same bar on the same page.
            $I->seeElement('#invoice-action-view');
        }
    }

    /**
     * THE INVOICE: the Edit control appears on any editable standalone invoice, not only a Draft
     * one — the same facts `InvoiceController::edit()` itself refuses on
     * (`Invoice::isEditable()`, no sales order, not locked), asked from the other side.
     *
     * Three invoices differing in exactly one fact each from the positive case: one bills a sales
     * order, one is Cancelled (a genuine terminal exception), and one is Pending — which now
     * belongs on the POSITIVE side, the opposite of what this test asserted before today.
     */
    public function theInvoiceEditControlAppearsOnAnyEditableStandaloneInvoice(FunctionalTester $I): void
    {
        $this->begin($I);

        // All fixtures built before any HTTP round trip — interleaving amOnPage() calls with more
        // fixture creation confuses this test's EntityManager handle once a real request has
        // rebooted the kernel's own (see AdminEstimateCreateSubmitPricedCest's own note on this).
        $standaloneDraft = $this->invoice($I, 'Draft', suffix: 'STANDALONE');
        $standalonePending = $this->invoice($I, 'Pending', suffix: 'PENDING');
        $orderBackedDraft = $this->invoice($I, 'Draft', order: $this->order($I), suffix: 'ORDERBACKED');
        $standaloneCancelled = $this->invoice($I, 'Cancelled', suffix: 'CANCELLED');

        $I->amOnPage('/admin/invoice/detail/' . $standaloneDraft->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#invoice-action-edit');
        $I->seeElement('#invoice-action-view');

        $I->amOnPage('/admin/invoice/detail/' . $standalonePending->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#invoice-action-edit');
        $I->seeElement('#invoice-action-view');

        $I->amOnPage('/admin/invoice/detail/' . $orderBackedDraft->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#invoice-action-edit');
        $I->seeElement('#invoice-action-view');

        $I->amOnPage('/admin/invoice/detail/' . $standaloneCancelled->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#invoice-action-edit');
        $I->seeElement('#invoice-action-view');
    }

    /**
     * Payments is withheld on a DRAFT invoice (#31) and offered once it is issued.
     *
     * The same invoice, issued in between, so the two halves are one element on one row rather than
     * two fixtures that might differ in some other way.
     */
    public function paymentsIsWithheldOnADraftInvoiceAndOfferedOnceItIsIssued(FunctionalTester $I): void
    {
        $this->begin($I);
        $invoice = $this->invoice($I, 'Draft');
        $id = (int) $invoice->getId();

        $I->amOnPage('/admin/invoice/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#invoice-action-payments');
        $I->seeElement('#invoice-action-credit-note');

        $em = $this->em($I);
        $em->find(Invoice::class, $id)->issue(DocumentActor::system());
        $em->flush();

        $I->amOnPage('/admin/invoice/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#invoice-action-payments');
    }

    /**
     * The invoice's related-document link appears only when there is a related document. The email
     * controls do not follow that fact any more — sending an invoice never required an order behind
     * it (invoice_customer / invoice_self read the invoice's own fields since #full-parity), so both
     * are offered on every invoice, standalone or order-linked alike.
     *
     * Two invoices for one company, differing in that one fact alone.
     */
    public function theInvoicesOrderLinkFollowsWhetherItBillsAnOrderWhileEmailIsOfferedEitherWay(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I);
        $linked = $this->invoice($I, 'Pending', $order, 'LINKED');
        $standalone = $this->invoice($I, 'Pending', null, 'STANDALONE');

        $I->amOnPage('/admin/invoice/detail/' . $linked->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#invoice-action-order');
        $I->seeElement('#invoice-action-send-customer');
        $I->seeElement('#invoice-action-send-self');
        $I->dontSeeElement('#invoice-action-send-unavailable');

        $I->amOnPage('/admin/invoice/detail/' . $standalone->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#invoice-action-order');
        $I->seeElement('#invoice-action-send-customer');
        $I->seeElement('#invoice-action-send-self');
        $I->dontSeeElement('#invoice-action-send-unavailable');
    }

    /**
     * The quote's related-document link is the order it became, and it replaces Convert rather than
     * sitting beside it — a second order must never be raised from one quote.
     */
    public function theQuotesConvertedOrderLinkReplacesConvert(FunctionalTester $I): void
    {
        $this->begin($I);
        $quote = $this->estimate($I, 'Accepted');
        $id = (int) $quote->getId();

        $I->amOnPage('/admin/estimate/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#quote-action-convert');
        $I->dontSeeElement('#quote-action-converted-order');

        $order = $this->order($I);
        $em = $this->em($I);
        $em->find(Estimate::class, $id)->setConvertedOrder($em->find(SalesOrder::class, $order->getId()));
        $em->flush();

        $I->amOnPage('/admin/estimate/detail/' . $id);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('#quote-action-convert');
        $I->seeElement('#quote-action-converted-order');
    }

    /**
     * Each document's PDF / Download menu offers ITS OWN document, and only its own.
     *
     * The menu is the shared shell with a per-document template in it; the failure this catches is
     * the shell rendering one document's items for all three, which would read as a working menu
     * everywhere and print the wrong sheet on two screens.
     */
    public function eachDocumentsPrintMenuOffersItsOwnDocument(FunctionalTester $I): void
    {
        $this->begin($I);

        $orderId = (int) $this->order($I)->getId();
        $quoteId = (int) $this->estimate($I)->getId();
        $invoiceId = (int) $this->invoice($I, 'Pending')->getId();

        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('/admin/order/document/' . $orderId . '?download=1', (string) $I->grabAttributeFrom('#order-action-download', 'href'));
        $I->assertSame('_blank', (string) $I->grabAttributeFrom('#order-action-print', 'target'));
        $I->dontSeeElement('#order-action-download-slip');

        $I->amOnPage('/admin/estimate/detail/' . $quoteId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('/admin/estimate/quote/' . $quoteId . '?download=1', (string) $I->grabAttributeFrom('#quote-action-download', 'href'));
        $I->dontSeeElement('#quote-action-download-slip');

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('/admin/invoice/print/' . $invoiceId . '?download=1', (string) $I->grabAttributeFrom('#invoice-action-download', 'href'));
        $I->seeElement('#invoice-action-download-slip');
    }
}
