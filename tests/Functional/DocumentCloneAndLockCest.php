<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AbstractDocumentAddress;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Exception\DocumentLocked;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Clone and Lock for the three sell-side documents — conducted per #624, asserted per #627.
 *
 * Every case drives the REAL routes with plain form POSTs carrying a CSRF token scraped off a page,
 * creates its own data, and reads `estimate`, `sales_order`, `invoice`, their line tables, their log
 * tables and `document_lock` back out of SQLite BY COLUMN after every POST. A refusal that flashes a
 * message while the row moves anyway would pass a test that only read the page, and a clone that
 * flashed success while copying nothing would pass one that only read the flash.
 *
 * ## Driven by route, not by button
 *
 * Deliberately, and it is not a shortcut. The controls for both actions are wired by a separate
 * change against the five sell-side templates, so at this commit only `admin_order_clone` has a
 * button anywhere. Posting the route directly with a scraped token is what #624 asks for in any
 * case — it is what a browser does when the button is pressed — and it proves the half that matters:
 * the behaviour is complete without the markup, so wiring the buttons cannot be what makes it work.
 *
 * ## #627, in every case
 *
 * Nothing calls `see()` on a bare number or word. 'Draft', 'Void', 'Cancelled' and every money
 * figure appear all over these pages in status chips, filter dropdowns and totals, so each is
 * asserted as the COLUMN it landed in. The two text assertions name `.flash-message[data-type=...]`
 * plus a phrase that appears nowhere else in the application.
 *
 * Every absence is paired with a positive control on the same table and usually the same query: the
 * clone's empty timeline is asserted beside the original's non-empty one read by the same helper,
 * the clone's null `converted_order_id` beside the original's non-null one, and the lock refusals
 * are each preceded by the same POST succeeding on the same row while it was unlocked.
 */
final class DocumentCloneAndLockCest
{
    /**
     * The Cest instance is reused across every method in this file, so anything held on `$this`
     * would leak from one case into the next. Both properties are reset by begin(), which every
     * case calls first, and they exist only to make that statement checkable.
     */
    private string $tag = '';

    private ?int $companyId = null;

    private ?int $productId = null;

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** Fresh per case. $role is the ONE role the admin carries; AdminUser::getRoles() adds ROLE_ADMIN. */
    private function begin(FunctionalTester $I, string $role = 'ROLE_SUPER_ADMIN'): void
    {
        $this->tag = strtoupper(substr(uniqid(), -6));
        $this->companyId = null;
        $this->productId = null;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('clone-lock-' . strtolower($this->tag) . '@example.test');
        $admin->setRoles([$role]);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
        // A browser always sends one, and DocumentLockedSubscriber redirects a refused write back to
        // it (never onward to an attacker-supplied one). Without it a refusal that reaches the
        // subscriber rather than a local catch would land on '/', which is the storefront.
        $I->haveHttpHeader('Referer', 'http://admin.localhost/admin/order');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        return $I->grabService('doctrine.orm.entity_manager');
    }

    /**
     * A document cannot be raised for a company with no active fulfillment region since #237.
     *
     * Memoised BY ID, not by object, and re-found on every call. A case that builds an order AND an
     * invoice must build both for the SAME customer — newestId() below finds the clone by company,
     * and `company.code` is unique besides — but every `amOnPage()` in between reboots the kernel
     * and with it the EntityManager, so an object held across a request is detached and persisting
     * a document against it fails with "A new entity was found through the relationship
     * 'App\Entity\SalesOrder#company'".
     */
    private function company(FunctionalTester $I): Company
    {
        if ($this->companyId !== null) {
            return $this->em($I)->find(Company::class, $this->companyId);
        }

        $company = (new Company())
            ->setName('Clone Lock Co ' . $this->tag)
            ->setCode('CL-' . $this->tag);
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);
        $this->companyId = $company->getId();

        return $company;
    }

    /** Memoised the same way, for the same reason, and because `product_core.sku` is unique. */
    private function product(FunctionalTester $I): ProductCore
    {
        if ($this->productId !== null) {
            return $this->em($I)->find(ProductCore::class, $this->productId);
        }

        $product = (new ProductCore())
            ->setSku('CL-' . $this->tag)
            ->setName('Clone Lock Widget ' . $this->tag)
            ->setUnit('EA')
            ->setWeight('1.000')
            ->setSalesTaxCode('E')
            ->setCostPrice('30.00')
            ->setDefaultPrice('50.00')
            ->setOriginalPrice('50.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        // An issued invoice and an approved order both HOLD stock, and #326 refuses a save into a
        // reserving state that is not covered.
        $I->haveStockFor($product);
        $this->productId = $product->getId();

        return $product;
    }

    /**
     * An order with two lines, a charge row, a frozen shipping address and a PO number — enough
     * that "the copy carries the lines and money by column" has several columns to be wrong about.
     */
    private function order(FunctionalTester $I, string $status = 'Draft'): SalesOrder
    {
        $company = $this->company($I);
        $product = $this->product($I);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('CLONE-SO-' . $this->tag)
            ->setPoNumber('PO-' . $this->tag)
            ->setUserName('Priya Raman')
            ->setSpecialInstructions('Deliver to the loading bay, not reception.')
            ->setShippingMethod('Ground')
            ->setFulfillmentRegion('Main')
            ->setPaymentMethod('Net Terms')
            ->setPaymentTerm('Net 30')
            ->setFeeLines(self::CHARGE_ROWS)
            ->setSubtotal('250.00')
            ->setTax('0.00')
            ->setTotal('265.00');
        $order->setDocumentDate('2026-03-04');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setQuantity('3.00')->setUnit('EA')->setTaxCode('E')
                ->setCost('30.00')->setPrice('50.00')->setSubtotal('150.00')->setSortOrder(0)
        );
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setQuantity('2.00')->setUnit('EA')->setTaxCode('E')
                ->setCost('30.00')->setPrice('50.00')->setSubtotal('100.00')->setSortOrder(1)
        );
        $order->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setFirstName('Dana')->setLastName('Okafor')
            ->setAddressLine1('14 Dock Road')->setCity('Surrey')->setProvince('BC')
            ->setPostalCode('V3S 0A1')->setCountry('CA');

        match ($status) {
            'Draft' => null,
            'Approved' => $order->setStatus('Approved', DocumentActor::system(), 'Order approved.'),
            // Two writes in one arm: the gate takes one target at a time, so reaching Void means
            // approving and then voiding. Evaluated left to right.
            'Void' => [
                $order->setStatus('Approved', DocumentActor::system(), 'Order approved.'),
                $order->setStatus(
                    'Void',
                    DocumentActor::system(),
                    sprintf('Order voided (was %s): Customer changed their mind.', $order->getStatus()),
                ),
            ],
        };

        $I->haveInRepository($order);

        return $order;
    }

    private function estimate(FunctionalTester $I, string $status = 'Draft'): Estimate
    {
        $company = $this->company($I);
        $product = $this->product($I);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('CLONE-QO-' . $this->tag);
        $estimate
            ->setPoNumber('PO-' . $this->tag)
            ->setUserName('Priya Raman')
            ->setSpecialInstructions('Quoted for the March tender.')
            ->setShippingMethod('Ground')
            ->setFulfillmentRegion('Main')
            ->setFeeLines(self::CHARGE_ROWS)
            ->setSubtotal('250.00')
            ->setTax('0.00')
            ->setTotal('265.00')
            ->setDocumentDate('2026-03-04');
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setQuantity('3.00')->setUnit('EA')->setTaxCode('E')
                ->setCost('30.00')->setPrice('50.00')->setSubtotal('150.00')->setSortOrder(0)
        );
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setQuantity('2.00')->setUnit('EA')->setTaxCode('E')
                ->setCost('30.00')->setPrice('50.00')->setSubtotal('100.00')->setSortOrder(1)
        );
        $estimate->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setFirstName('Dana')->setLastName('Okafor')
            ->setAddressLine1('14 Dock Road')->setCity('Surrey')->setProvince('BC')
            ->setPostalCode('V3S 0A1')->setCountry('CA');
        $estimate->setStatus($status, DocumentActor::system());

        $I->haveInRepository($estimate);

        return $estimate;
    }

    /** A STANDALONE invoice — no order behind it, which is what a clone produces and is legal since #539 stage 5. */
    private function invoice(FunctionalTester $I, string $status = 'Draft'): Invoice
    {
        $company = $this->company($I);
        $product = $this->product($I);

        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('CLONE-INV-' . $this->tag);
        $invoice
            ->setPoNumber('PO-' . $this->tag)
            ->setUserName('Priya Raman')
            ->setSpecialInstructions('Counter sale.')
            ->setShippingMethod('Ground')
            ->setFulfillmentRegion('Main')
            ->setFeeLines(self::CHARGE_ROWS)
            ->setSubtotal('250.00')
            ->setTax('0.00')
            ->setTotal('265.00')
            ->setDocumentDate('2026-03-04');
        $invoice->setPaymentMethod('Net Terms')->setPaymentTerm('Net 30');
        $invoice->setInvoiceDate('2026-03-04')->setDueDate('2026-04-03');
        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setQuantity('3.00')->setUnit('EA')->setTaxCode('E')
                ->setCost('30.00')->setPrice('50.00')->setSubtotal('150.00')->setSortOrder(0)
        );
        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($product)->setName($product->getName())->setSku((string) $product->getSku())
                ->setQuantity('2.00')->setUnit('EA')->setTaxCode('E')
                ->setCost('30.00')->setPrice('50.00')->setSubtotal('100.00')->setSortOrder(1)
        );
        $invoice->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setFirstName('Dana')->setLastName('Okafor')
            ->setAddressLine1('14 Dock Road')->setCity('Surrey')->setProvince('BC')
            ->setPostalCode('V3S 0A1')->setCountry('CA');

        match ($status) {
            'Draft' => null,
            'Pending' => $invoice->issue(DocumentActor::system()),
            'Cancelled' => $invoice->issue(DocumentActor::system())
                ->setStatus('Cancelled', DocumentActor::system(), 'Invoice cancelled: Wrong customer.'),
        };

        $I->haveInRepository($invoice);

        return $invoice;
    }

    /** A `created_at` no clone written today could have. */
    private const LONG_AGO = '2020-01-01 00:00:00';

    /** One shipping row and one handling row, in the shape FeeLineSnapshot decodes. */
    private const CHARGE_ROWS = '[{"slug":"shipping","label":"Ground freight","type":"shipping","quantity":1,"amount":10},'
        . '{"slug":"handling","label":"Handling","type":"fee","quantity":1,"amount":5}]';

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Conducting
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A real form POST with a token scraped off a real page.
     *
     * The token id is global in this application (`Csrf::ID === 'submit'`) and `base.html.twig`
     * renders it into `<meta name="csrf-token">` on every admin page, so the value scraped off the
     * order list is byte-for-byte the one `csrf_field()` puts in every admin form. That is what lets
     * a route whose button has not been built yet be driven exactly as a browser drives it — and it
     * is a scrape off a rendered page, not a token minted inside the test, so a change that broke
     * CSRF generation would break these cases too.
     */
    private function post(FunctionalTester $I, string $uri, array $params = []): void
    {
        $I->amOnPage('/admin/order');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->csrfToken();
        $I->assertNotSame('', $token, 'no CSRF token could be scraped off the admin order list');

        $I->sendFormPostRequest($uri, ['_token' => $token] + $params);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Reading columns back
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A money or quantity column as a comparable string.
     *
     * SQLite stores NUMERIC loosely: a column written as '250.00' reads back as '250', and one
     * written as '50.000000' reads back as '50'. Comparing the raw strings would make every one of
     * these assertions a test of SQLite's affinity rules rather than of what was copied. Normalising
     * both sides to two decimals compares the FIGURE, which is the thing that must survive a clone.
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** @return array<string, mixed> */
    private function row(FunctionalTester $I, string $table, int $id): array
    {
        $row = $this->em($I)->getConnection()->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$id]);
        $I->assertIsArray($row, sprintf('%s #%d does not exist', $table, $id));

        return $row;
    }

    /** @return list<array<string, mixed>> the document's lines, in sort order */
    private function lines(FunctionalTester $I, string $table, string $fk, int $id): array
    {
        return $this->em($I)->getConnection()->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY sort_order ASC, id ASC', $table, $fk),
            [$id],
        );
    }

    private function count(FunctionalTester $I, string $table, string $column, int $id): int
    {
        return (int) $this->em($I)->getConnection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column),
            [$id],
        );
    }

    /**
     * The narrative-timeline row count for one document — what used to be `estimate_log` /
     * `sales_order_log` / `invoice_log`'s own row count, now the `actor_type = 'document'` subset
     * of `audit_log` for that entity.
     */
    private function narrativeLogCount(FunctionalTester $I, string $entityType, int $entityId): int
    {
        return (int) $this->em($I)->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type = ? AND entity_id = ? AND actor_type = 'document'",
            [$entityType, $entityId],
        );
    }

    private function newestId(FunctionalTester $I, string $table, int $excludingId): int
    {
        $id = (int) $this->em($I)->getConnection()->fetchOne(
            sprintf('SELECT MAX(id) FROM %s WHERE company_id = ? AND id <> ?', $table),
            [$this->companyId, $excludingId],
        );
        $I->assertGreaterThan(0, $id, sprintf('no second %s row exists for this case\'s company — nothing was cloned', $table));

        return $id;
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

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 1. The copy carries the lines and the money, and NONE of the forbidden fields.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Cloning a quote that has been CONVERTED, so `converted_order_id` is non-null on the original
     * and the "the link is null on the copy" assertion has a positive control on the same column.
     */
    public function cloningAQuoteCarriesWhatItWasAndNoneOfWhatHappenedToIt(FunctionalTester $I): void
    {
        $this->begin($I);
        $estimate = $this->estimate($I, 'Priced');
        $estimateId = (int) $estimate->getId();

        // Give the original the three things a clone must NOT carry: a timeline, a converted order,
        // and a status that is not Draft. Written through the entities so the row is exactly what
        // the application would have produced.
        $em = $this->em($I);
        $order = $this->order($I, 'Approved');
        $estimate->setConvertedOrder($order);
        $estimate->setStatus('Accepted', DocumentActor::system());
        $estimate->queueActivityLogEntry()->setUserName('Priya')->setComment('Customer asked for a bigger discount.')->setType('Note');
        $em->flush();

        // Backdated so "the copy's created_at is its own moment" is a real assertion rather than a
        // coin toss: `created_at` has second precision, and an original and a copy raised inside one
        // test are otherwise written in the same second.
        $em->getConnection()->executeStatement('UPDATE estimate SET created_at = ? WHERE id = ?', [self::LONG_AGO, $estimateId]);

        $before = $this->row($I, 'estimate', $estimateId);
        $I->assertSame(self::LONG_AGO, (string) $before['created_at'], 'the original quote under test was not backdated');
        $I->assertNotNull($before['converted_order_id'], 'the original quote under test was not converted');
        // Three rows, and each is written by the application: the fixture's own Draft -> Priced
        // move, the Priced -> Accepted move above, and the note. The first two are the status seam's
        // — setStatus() writes a row per real move — and they are as much "what happened to it" as
        // the note is, so a copy must carry none of the three.
        $I->assertSame(3, $this->narrativeLogCount($I, 'Estimate', $estimateId), 'the original quote under test has no timeline');

        $this->post($I, '/admin/estimate/clone/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();

        $copyId = $this->newestId($I, 'estimate', $estimateId);
        $copy = $this->row($I, 'estimate', $copyId);

        // ── What it WAS, by column.
        $I->assertSame($before['company_id'], $copy['company_id'], 'estimate.company_id');
        $I->assertSame('PO-' . $this->tag, (string) $copy['po_number'], 'estimate.po_number');
        $I->assertSame('Priya Raman', (string) $copy['user_name'], 'estimate.user_name');
        $I->assertSame('Quoted for the March tender.', (string) $copy['special_instructions'], 'estimate.special_instructions');
        $I->assertSame('Ground', (string) $copy['shipping_method'], 'estimate.shipping_method');
        $I->assertSame('Main', (string) $copy['fulfillment_region'], 'estimate.fulfillment_region');
        $I->assertSame($before['fee_lines'], $copy['fee_lines'], 'estimate.fee_lines — the charge rows, which is where shipping lives');
        $I->assertSame($before['company_snapshot'], $copy['company_snapshot'], 'estimate.company_snapshot');
        $I->assertSame($this->money($before['subtotal']), $this->money($copy['subtotal']), 'estimate.subtotal');
        $I->assertSame($this->money($before['tax']), $this->money($copy['tax']), 'estimate.tax');
        $I->assertSame($this->money($before['total']), $this->money($copy['total']), 'estimate.total');
        $I->assertSame('265.00', $this->money($copy['total']), 'estimate.total is the figure the fixture raised, not merely equal to a wrong original');

        // ── What HAPPENED to it, by column. Each paired with the original reading the opposite.
        $I->assertNotSame((string) $before['document_number'], (string) $copy['document_number'], 'the copy drew a fresh document number');
        $I->assertSame('Draft', (string) $copy['status'], 'estimate.status on the copy');
        $I->assertSame('Accepted', (string) $before['status'], 'estimate.status on the original — the positive control for the line above');
        $I->assertNull($copy['converted_order_id'], 'estimate.converted_order_id on the copy');
        $I->assertSame(0, $this->narrativeLogCount($I, 'Estimate', $copyId), 'estimate_log rows on the copy');
        $I->assertSame(3, $this->narrativeLogCount($I, 'Estimate', $estimateId), 'estimate_log rows on the original — the positive control for the line above');
        $I->assertSame(1, (int) $copy['version'], 'estimate.version on the copy starts at 1');
        $I->assertNotSame(self::LONG_AGO, (string) $copy['created_at'], 'estimate.created_at is the copy\'s own moment');
        $I->assertSame(self::LONG_AGO, (string) $this->row($I, 'estimate', $estimateId)['created_at'], 'estimate.created_at on the original — the positive control for the line above');
        // The calendar date is the copy's, not the original's March date: a clone of a March quote
        // dated March would be overdue the day it was made.
        $I->assertNotSame('2026-03-04', (string) $copy['document_date'], 'estimate.document_date on the copy');
        $I->assertSame('2026-03-04', (string) $before['document_date'], 'estimate.document_date on the original — the positive control for the line above');

        // ── The lines, by column, in order.
        $original = $this->lines($I, 'estimate_line', 'estimate_id', $estimateId);
        $copied = $this->lines($I, 'estimate_line', 'estimate_id', $copyId);
        $I->assertCount(2, $copied, 'estimate_line rows on the copy');
        foreach ([0, 1] as $i) {
            $I->assertSame($original[$i]['product_id'], $copied[$i]['product_id'], sprintf('estimate_line[%d].product_id', $i));
            $I->assertSame($this->money($original[$i]['quantity']), $this->money($copied[$i]['quantity']), sprintf('estimate_line[%d].quantity', $i));
            $I->assertSame($this->money($original[$i]['price']), $this->money($copied[$i]['price']), sprintf('estimate_line[%d].price', $i));
            $I->assertSame($this->money($original[$i]['subtotal']), $this->money($copied[$i]['subtotal']), sprintf('estimate_line[%d].subtotal', $i));
            $I->assertSame((string) $original[$i]['unit'], (string) $copied[$i]['unit'], sprintf('estimate_line[%d].unit', $i));
            $I->assertSame((string) $original[$i]['tax_code'], (string) $copied[$i]['tax_code'], sprintf('estimate_line[%d].tax_code', $i));
            $I->assertSame((int) $original[$i]['sort_order'], (int) $copied[$i]['sort_order'], sprintf('estimate_line[%d].sort_order', $i));
            $I->assertNotSame((int) $original[$i]['id'], (int) $copied[$i]['id'], sprintf('estimate_line[%d] is its own row', $i));
        }

        // ── The frozen address snapshot, copied rather than re-resolved.
        $address = $this->em($I)->getConnection()->fetchAssociative(
            'SELECT address_line1, city, province, first_name FROM estimate_address WHERE estimate_id = ? AND type = ?',
            [$copyId, AbstractDocumentAddress::TYPE_SHIPPING],
        );
        $I->assertIsArray($address, 'the copy has no shipping address snapshot');
        $I->assertSame('14 Dock Road', (string) $address['address_line1'], 'estimate_address.address_line1 on the copy');
        $I->assertSame('BC', (string) $address['province'], 'estimate_address.province on the copy');
        $I->assertSame('Dana', (string) $address['first_name'], 'estimate_address.first_name on the copy');

        // The one text assertion, on its own element, naming a phrase that appears nowhere else.
        $I->see('came across exactly as they were', '.flash-message[data-type="success"]');
    }

    public function cloningAnOrderCarriesWhatItWasAndNoneOfWhatHappenedToIt(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I, 'Approved');
        $orderId = (int) $order->getId();

        $before = $this->row($I, 'sales_order', $orderId);
        // approve() wrote a timeline row, which is the positive control for the copy's empty one.
        $I->assertSame(1, $this->narrativeLogCount($I, 'SalesOrder', $orderId), 'the original order under test has no timeline');

        $this->post($I, '/admin/order/clone/' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        $copyId = $this->newestId($I, 'sales_order', $orderId);
        $copy = $this->row($I, 'sales_order', $copyId);

        $I->assertSame($before['company_id'], $copy['company_id'], 'sales_order.company_id');
        $I->assertSame('PO-' . $this->tag, (string) $copy['po_number'], 'sales_order.po_number');
        $I->assertSame('Deliver to the loading bay, not reception.', (string) $copy['special_instructions'], 'sales_order.special_instructions');
        $I->assertSame('Net Terms', (string) $copy['payment_method'], 'sales_order.payment_method');
        $I->assertSame('Net 30', (string) $copy['payment_term'], 'sales_order.payment_term');
        $I->assertSame('Main', (string) $copy['fulfillment_region'], 'sales_order.fulfillment_region');
        $I->assertSame($before['fee_lines'], $copy['fee_lines'], 'sales_order.fee_lines');
        $I->assertSame($this->money($before['subtotal']), $this->money($copy['subtotal']), 'sales_order.subtotal');
        $I->assertSame($this->money($before['total']), $this->money($copy['total']), 'sales_order.total');
        $I->assertSame('265.00', $this->money($copy['total']), 'sales_order.total is the figure the fixture raised, not merely equal to a wrong original');

        $I->assertNotSame((string) $before['order_number'], (string) $copy['order_number'], 'the copy drew a fresh order number');
        $I->assertSame('Draft', (string) $copy['status'], 'sales_order.status on the copy');
        $I->assertSame('Approved', (string) $before['status'], 'sales_order.status on the original — the positive control for the line above');
        $I->assertSame(0, $this->narrativeLogCount($I, 'SalesOrder', $copyId), 'sales_order_log rows on the copy');
        $I->assertSame(1, $this->narrativeLogCount($I, 'SalesOrder', $orderId), 'sales_order_log rows on the original — the positive control for the line above');
        $I->assertSame(1, (int) $copy['version'], 'sales_order.version on the copy starts at 1');
        $I->assertSame(0, $this->count($I, 'order_inventory_reservation', 'order_id', $copyId), 'the copy holds no inventory reservation');

        $original = $this->lines($I, 'sales_order_line', 'order_id', $orderId);
        $copied = $this->lines($I, 'sales_order_line', 'order_id', $copyId);
        $I->assertCount(2, $copied, 'sales_order_line rows on the copy');
        foreach ([0, 1] as $i) {
            $I->assertSame($original[$i]['product_id'], $copied[$i]['product_id'], sprintf('sales_order_line[%d].product_id', $i));
            $I->assertSame($this->money($original[$i]['quantity']), $this->money($copied[$i]['quantity']), sprintf('sales_order_line[%d].quantity', $i));
            $I->assertSame($this->money($original[$i]['price']), $this->money($copied[$i]['price']), sprintf('sales_order_line[%d].price', $i));
            $I->assertSame($this->money($original[$i]['subtotal']), $this->money($copied[$i]['subtotal']), sprintf('sales_order_line[%d].subtotal', $i));
            $I->assertSame($this->money($original[$i]['cost']), $this->money($copied[$i]['cost']), sprintf('sales_order_line[%d].cost', $i));
            $I->assertSame((int) $original[$i]['sort_order'], (int) $copied[$i]['sort_order'], sprintf('sales_order_line[%d].sort_order', $i));
            $I->assertSame(0, $this->count($I, 'sales_order_line_stock_override', 'order_line_id', (int) $copied[$i]['id']), sprintf('sales_order_line[%d] carries no stock override', $i));
        }

        $address = $this->em($I)->getConnection()->fetchAssociative(
            'SELECT address_line1, province FROM sales_order_address WHERE order_id = ? AND type = ?',
            [$copyId, AbstractDocumentAddress::TYPE_SHIPPING],
        );
        $I->assertIsArray($address, 'the copy has no shipping address snapshot');
        $I->assertSame('14 Dock Road', (string) $address['address_line1'], 'sales_order_address.address_line1 on the copy');
    }

    /**
     * The invoice case also proves the order link is NOT carried, which is the copy's one genuinely
     * dangerous field: a cloned invoice that kept `sales_order_id` would double its order's invoiced
     * quantity the moment it was saved.
     */
    public function cloningAnInvoiceCarriesWhatItWasAndNeitherItsOrderNorItsMoney(FunctionalTester $I): void
    {
        $this->begin($I);
        $invoice = $this->invoice($I, 'Pending');
        $invoiceId = (int) $invoice->getId();

        // Link it to an order and take a payment, so the two "null on the copy" assertions each have
        // a positive control on the same column of the same table.
        $em = $this->em($I);
        $order = $this->order($I, 'Approved');
        $invoice->setSalesOrder($order);
        $em->flush();

        $before = $this->row($I, 'invoice', $invoiceId);
        $I->assertNotNull($before['sales_order_id'], 'the original invoice under test is not linked to an order');
        $I->assertGreaterThan(0, $this->narrativeLogCount($I, 'Invoice', $invoiceId), 'the original invoice under test has no timeline');

        $this->post($I, '/admin/invoice/clone/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();

        $copyId = $this->newestId($I, 'invoice', $invoiceId);
        $copy = $this->row($I, 'invoice', $copyId);

        $I->assertSame($before['company_id'], $copy['company_id'], 'invoice.company_id');
        $I->assertSame('PO-' . $this->tag, (string) $copy['po_number'], 'invoice.po_number');
        $I->assertSame('Net 30', (string) $copy['payment_term'], 'invoice.payment_term');
        $I->assertSame('Net Terms', (string) $copy['payment_method'], 'invoice.payment_method');
        $I->assertSame($before['fee_lines'], $copy['fee_lines'], 'invoice.fee_lines');
        $I->assertSame($this->money($before['subtotal']), $this->money($copy['subtotal']), 'invoice.subtotal');
        $I->assertSame($this->money($before['total']), $this->money($copy['total']), 'invoice.total');
        $I->assertSame('265.00', $this->money($copy['total']), 'invoice.total is the figure the fixture raised, not merely equal to a wrong original');

        $I->assertNotSame((string) $before['document_number'], (string) $copy['document_number'], 'the copy drew a fresh document number');
        $I->assertSame('Draft', (string) $copy['status'], 'invoice.status on the copy');
        $I->assertSame('Pending', (string) $before['status'], 'invoice.status on the original — the positive control for the line above');
        $I->assertNull($copy['sales_order_id'], 'invoice.sales_order_id on the copy');
        $I->assertSame('Not Paid', (string) $copy['payment_status'], 'invoice.payment_status on the copy');
        $I->assertNull($copy['invoice_date'], 'invoice.invoice_date on the copy');
        $I->assertSame('2026-03-04', (string) $before['invoice_date'], 'invoice.invoice_date on the original — the positive control for the line above');
        $I->assertNull($copy['due_date'], 'invoice.due_date on the copy');
        $I->assertSame('2026-04-03', (string) $before['due_date'], 'invoice.due_date on the original — the positive control for the line above');
        $I->assertSame(0, $this->narrativeLogCount($I, 'Invoice', $copyId), 'invoice_log rows on the copy');
        $I->assertSame(0, $this->count($I, 'invoice_payment_application', 'invoice_id', $copyId), 'invoice_payment_application rows on the copy');
        $I->assertSame(1, (int) $copy['version'], 'invoice.version on the copy starts at 1');
        $I->assertSame(0, $this->count($I, 'invoice_inventory_reservation', 'invoice_id', $copyId), 'the copy holds no inventory reservation');

        $original = $this->lines($I, 'invoice_line', 'invoice_id', $invoiceId);
        $copied = $this->lines($I, 'invoice_line', 'invoice_id', $copyId);
        $I->assertCount(2, $copied, 'invoice_line rows on the copy');
        foreach ([0, 1] as $i) {
            $I->assertSame($original[$i]['product_id'], $copied[$i]['product_id'], sprintf('invoice_line[%d].product_id', $i));
            $I->assertSame($this->money($original[$i]['quantity']), $this->money($copied[$i]['quantity']), sprintf('invoice_line[%d].quantity', $i));
            $I->assertSame($this->money($original[$i]['price']), $this->money($copied[$i]['price']), sprintf('invoice_line[%d].price', $i));
            $I->assertSame($this->money($original[$i]['subtotal']), $this->money($copied[$i]['subtotal']), sprintf('invoice_line[%d].subtotal', $i));
            $I->assertNull($copied[$i]['sales_order_line_id'], sprintf('invoice_line[%d].sales_order_line_id on the copy', $i));
        }

        $I->see('The copy bills no sales order', '.flash-message[data-type="success"]');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 2. A VOID / cancelled / rejected document clones. The main use case.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * "This one was wrong, make another." A terminal status is exactly when somebody reaches for
     * Clone, so every one of the three has to answer — and each is its own case rather than three
     * sections of one, because a fixture built after a case's first HTTP request is built against a
     * rebooted kernel's EntityManager and fails with "A new entity was found through the
     * relationship 'App\Entity\CompanyFulfillmentRegion#company'".
     */
    public function aVoidOrderIsStillClonedAndTheCopyIsALiveDraft(FunctionalTester $I): void
    {
        $this->begin($I);

        $order = $this->order($I, 'Void');
        $orderId = (int) $order->getId();
        $I->assertSame('Void', (string) $this->row($I, 'sales_order', $orderId)['status'], 'the order under test is not void');

        $this->post($I, '/admin/order/clone/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $orderCopyId = $this->newestId($I, 'sales_order', $orderId);
        $I->assertSame('Draft', (string) $this->row($I, 'sales_order', $orderCopyId)['status'], 'a clone of a void order is not a Draft');
        $I->assertCount(2, $this->lines($I, 'sales_order_line', 'order_id', $orderCopyId), 'a clone of a void order lost its lines');
        $I->assertSame('265.00', $this->money($this->row($I, 'sales_order', $orderCopyId)['total']), 'sales_order.total on the clone of a void order');
        // The original stays void. Cloning is not a resurrection.
        $I->assertSame('Void', (string) $this->row($I, 'sales_order', $orderId)['status'], 'cloning moved the void order\'s own status');

    }

    public function aCancelledInvoiceIsStillClonedAndTheCopyIsALiveDraft(FunctionalTester $I): void
    {
        $this->begin($I);
        $invoice = $this->invoice($I, 'Cancelled');
        $invoiceId = (int) $invoice->getId();
        $I->assertSame('Cancelled', (string) $this->row($I, 'invoice', $invoiceId)['status'], 'the invoice under test is not cancelled');

        $this->post($I, '/admin/invoice/clone/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $invoiceCopyId = $this->newestId($I, 'invoice', $invoiceId);
        $I->assertSame('Draft', (string) $this->row($I, 'invoice', $invoiceCopyId)['status'], 'a clone of a cancelled invoice is not a Draft');
        $I->assertCount(2, $this->lines($I, 'invoice_line', 'invoice_id', $invoiceCopyId), 'a clone of a cancelled invoice lost its lines');
        $I->assertSame('Cancelled', (string) $this->row($I, 'invoice', $invoiceId)['status'], 'cloning moved the cancelled invoice\'s own status');

    }

    public function aRejectedQuoteIsStillClonedAndTheCopyIsALiveDraft(FunctionalTester $I): void
    {
        $this->begin($I);
        $estimate = $this->estimate($I, 'Rejected');
        $estimateId = (int) $estimate->getId();
        $I->assertSame('Rejected', (string) $this->row($I, 'estimate', $estimateId)['status'], 'the quote under test is not rejected');

        $this->post($I, '/admin/estimate/clone/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();
        $estimateCopyId = $this->newestId($I, 'estimate', $estimateId);
        $I->assertSame('Draft', (string) $this->row($I, 'estimate', $estimateCopyId)['status'], 'a clone of a rejected quote is not a Draft');
        $I->assertCount(2, $this->lines($I, 'estimate_line', 'estimate_id', $estimateCopyId), 'a clone of a rejected quote lost its lines');
        $I->assertSame('Rejected', (string) $this->row($I, 'estimate', $estimateId)['status'], 'cloning moved the rejected quote\'s own status');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 3. Cloning does not touch the original. The row that must not change.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * The cheap half of #624, and the one that catches the real defects: the whole original row,
     * every column, compared before and after — plus its lines, its timeline and the copy's own
     * existence as proof something did happen.
     */
    public function cloningChangesNotOneColumnOfTheOriginal(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I, 'Approved');
        $orderId = (int) $order->getId();

        $before = $this->row($I, 'sales_order', $orderId);
        $linesBefore = $this->lines($I, 'sales_order_line', 'order_id', $orderId);
        $logsBefore = $this->narrativeLogCount($I, 'SalesOrder', $orderId);

        $this->post($I, '/admin/order/clone/' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        // The positive control: something definitely happened, so an "unchanged" verdict below is
        // about a clone that ran rather than about a POST that did nothing at all.
        $copyId = $this->newestId($I, 'sales_order', $orderId);
        $I->assertNotSame($orderId, $copyId, 'the clone is a different row');

        $after = $this->row($I, 'sales_order', $orderId);
        // Compared as whole rows by COLUMN ORDER, so a column added to sales_order later is covered
        // by this assertion on the day it is added rather than on the day somebody remembers it.
        $I->assertSame(array_values($before), array_values($after), 'cloning rewrote a column on the original order');
        $I->assertSame($linesBefore, $this->lines($I, 'sales_order_line', 'order_id', $orderId), 'cloning rewrote the original order\'s lines');
        $I->assertSame($logsBefore, $this->narrativeLogCount($I, 'SalesOrder', $orderId), 'cloning wrote a timeline row on the original order');
        $I->assertSame(1, (int) $after['version'], 'sales_order.version moved on the original, so something wrote to its row');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 4. A locked document refuses every write path.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Order: the save route, the status route, a line change through the service layer, and delete.
     *
     * Each refusal is preceded by the SAME post succeeding on the SAME row while it was unlocked, so
     * an absence cannot pass on a typo'd URL or a form the screen never accepted.
     */
    public function aLockedOrderRefusesEveryWritePath(FunctionalTester $I): void
    {
        $this->begin($I);
        $order = $this->order($I, 'Draft');
        $orderId = (int) $order->getId();

        // ── POSITIVE CONTROL, unlocked: the status route moves the column.
        $this->post($I, '/admin/order/update-status/' . $orderId, ['status' => 'Approved']);
        $I->assertSame('Approved', (string) $this->row($I, 'sales_order', $orderId)['status'], 'the status route did not work before the lock');

        // ── Lock it, through the real route, and read the row back.
        $this->post($I, '/admin/order/lock/' . $orderId, ['reason' => 'Under audit until Friday.']);
        $lock = $this->lockRow($I, 'sales_order', $orderId);
        $I->assertIsArray($lock, 'document_lock has no row for the locked order');
        $I->assertSame('Under audit until Friday.', (string) $lock['reason'], 'document_lock.reason');
        $I->assertNotSame('', (string) $lock['locked_by'], 'document_lock.locked_by');

        $frozen = $this->row($I, 'sales_order', $orderId);

        // ── WRITE PATH 1: the status route. Void is a legal move from Approved, so only the lock
        //    can be refusing it.
        $this->post($I, '/admin/order/update-status/' . $orderId, ['status' => 'Void']);
        $I->assertSame('Approved', (string) $this->row($I, 'sales_order', $orderId)['status'], 'a locked order was voided');

        // ── WRITE PATH 2: the edit screen, on GET. It must not even open.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeCurrentUrlEquals('/admin/order/detail/' . $orderId);
        $I->see('Unlock it — the control is on the document\'s own page', '.flash-message[data-type="error"]');

        // ── WRITE PATH 3: the edit screen's save. Posted with a changed PO number and ONE line row
        //    where the order has two, so a save that went through would be visible in two columns —
        //    the header's po_number and the line count.
        $orderLines = $this->lines($I, 'sales_order_line', 'order_id', $orderId);
        $this->post($I, '/admin/order/edit/' . $orderId, [
            'save_mode' => 'save',
            'company_id' => (string) $this->companyId,
            'po_number' => 'REWRITTEN-' . $this->tag,
            'line_product_id' => [(string) $orderLines[0]['product_id']],
            'line_qty' => ['99'],
            'line_price' => ['1.00'],
        ]);
        $I->assertSame((string) $frozen['po_number'], (string) $this->row($I, 'sales_order', $orderId)['po_number'], 'a locked order\'s po_number was rewritten');
        $I->assertCount(2, $this->lines($I, 'sales_order_line', 'order_id', $orderId), 'a locked order\'s line count changed');
        $I->assertSame((string) $orderLines[0]['quantity'], (string) $this->lines($I, 'sales_order_line', 'order_id', $orderId)[0]['quantity'], 'a locked order\'s line quantity moved');

        // ── WRITE PATH 4: billing it, which writes the ORDER's derived status even though the POST
        //    addresses a different document.
        $this->post($I, '/admin/invoice/create?order_id=' . $orderId, [
            'lines' => [['id' => (string) $orderLines[0]['id'], 'quantity' => '1']],
        ]);
        $I->assertSame(0, $this->count($I, 'invoice', 'sales_order_id', $orderId), 'a locked order was invoiced');

        // ── WRITE PATH 5 and 6: the service layer and deletion, neither of which has a route.
        //
        //    This is the case the whole design rests on. Nothing here goes through a controller at
        //    all: the entity is changed and flushed exactly as a console command, a subscriber or a
        //    route written next month would do it, and the refusal comes from
        //    DocumentLockFlushGuard inside onFlush. An enumerated list of controller guards would
        //    pass every assertion above and fail both of these.
        $em = $this->em($I);
        $em->clear();
        $reloaded = $em->find(SalesOrder::class, $orderId);
        $reloaded->setPoNumber('SERVICE-LAYER-' . $this->tag);
        $refusedTheHeader = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refusedTheHeader = true;
        }
        $I->assertTrue($refusedTheHeader, 'a flush that rewrote a locked order\'s header was not refused');
        $I->assertSame((string) $frozen['po_number'], (string) $this->row($I, 'sales_order', $orderId)['po_number'], 'a locked order\'s po_number was rewritten through the service layer');

        $em->clear();
        $reloaded = $em->find(SalesOrder::class, $orderId);
        $em->remove($reloaded);
        $refusedTheDelete = false;
        try {
            $em->flush();
        } catch (DocumentLocked $locked) {
            $refusedTheDelete = true;
            $I->assertStringContainsString('cannot be deleted', $locked->getMessage(), 'the delete refusal names the wrong act');
        }
        $I->assertTrue($refusedTheDelete, 'a locked order was deleted');
        $em->clear();
        $I->assertSame(
            (string) $frozen['order_number'],
            (string) $this->row($I, 'sales_order', $orderId)['order_number'],
            'the locked order is gone from sales_order',
        );

        // ── And the whole row, column by column, is exactly what it was before the first attempt.
        $I->assertSame(array_values($frozen), array_values($this->row($I, 'sales_order', $orderId)), 'a column of the locked order moved');
    }

    /** Quote: the save route, accept, convert and the status endpoint. */
    public function aLockedQuoteRefusesEveryWritePath(FunctionalTester $I): void
    {
        $this->begin($I);
        $estimate = $this->estimate($I, 'Priced');
        $estimateId = (int) $estimate->getId();

        // ── POSITIVE CONTROL, unlocked: the status endpoint moves the column.
        $this->post($I, '/admin/estimate/update-status/' . $estimateId, ['status' => 'Submitted']);
        $I->assertSame('Submitted', (string) $this->row($I, 'estimate', $estimateId)['status'], 'the quote status endpoint did not work before the lock');
        $this->post($I, '/admin/estimate/update-status/' . $estimateId, ['status' => 'Priced']);
        $I->assertSame('Priced', (string) $this->row($I, 'estimate', $estimateId)['status'], 'the quote could not be put back to Priced');

        $this->post($I, '/admin/estimate/lock/' . $estimateId, ['reason' => 'Pricing under review.']);
        $I->assertIsArray($this->lockRow($I, 'estimate', $estimateId), 'document_lock has no row for the locked quote');

        $frozen = $this->row($I, 'estimate', $estimateId);

        // ── WRITE PATH 1: the status endpoint.
        $this->post($I, '/admin/estimate/update-status/' . $estimateId, ['status' => 'Submitted']);
        $I->assertSame('Priced', (string) $this->row($I, 'estimate', $estimateId)['status'], 'a locked quote changed status');

        // ── WRITE PATH 2: the edit screen, on GET.
        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $I->seeCurrentUrlEquals('/admin/estimate/detail/' . $estimateId);
        $I->see('Unlock it — the control is on the document\'s own page', '.flash-message[data-type="error"]');

        // ── WRITE PATH 3: the edit screen's save.
        $this->post($I, '/admin/estimate/edit/' . $estimateId, [
            'save_mode' => 'save',
            'po_number' => 'REWRITTEN-' . $this->tag,
            'line_qty' => ['99'],
        ]);
        $I->assertSame((string) $frozen['po_number'], (string) $this->row($I, 'estimate', $estimateId)['po_number'], 'a locked quote\'s po_number was rewritten');

        // ── WRITE PATH 4: accept.
        $this->post($I, '/admin/estimate/accept/' . $estimateId);
        $I->assertSame('Priced', (string) $this->row($I, 'estimate', $estimateId)['status'], 'a locked quote was accepted');

        // ── WRITE PATH 5: convert to order, which writes converted_order_id onto this row.
        $this->post($I, '/admin/estimate/convert/' . $estimateId);
        $I->assertNull($this->row($I, 'estimate', $estimateId)['converted_order_id'], 'a locked quote was converted to an order');

        // ── WRITE PATH 6: a LINE, changed through the service layer with no controller in sight.
        //
        //    This is the case the whole design rests on. Nothing here goes through a controller at
        //    all: the entity is changed and flushed exactly as a console command, a subscriber or a
        //    route written next month would do it, and the refusal comes from
        //    DocumentLockFlushGuard inside onFlush. An enumerated list of controller guards would
        //    pass every assertion above and fail this one.
        $quantityBefore = (string) $this->lines($I, 'estimate_line', 'estimate_id', $estimateId)[0]['quantity'];
        $em = $this->em($I);
        $em->clear();
        $line = $em->getRepository(EstimateLine::class)->findOneBy(['estimate' => $estimateId]);
        $line->setQuantity('99.00');
        $refused = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refused = true;
        }
        $I->assertTrue($refused, 'a line change on a locked quote was not refused');
        $em->clear();
        $I->assertSame($quantityBefore, (string) $this->lines($I, 'estimate_line', 'estimate_id', $estimateId)[0]['quantity'], 'a locked quote\'s line quantity moved');

        $I->assertSame(array_values($frozen), array_values($this->row($I, 'estimate', $estimateId)), 'a column of the locked quote moved');
    }

    /** Invoice: the action endpoint, link, unlink, payments and payment delete. */
    public function aLockedInvoiceRefusesEveryWritePath(FunctionalTester $I): void
    {
        $this->begin($I);
        $invoice = $this->invoice($I, 'Draft');
        $invoiceId = (int) $invoice->getId();
        // Built NOW, before the first request: an entity created after one is created against a
        // rebooted kernel's EntityManager and cannot be persisted against this case's company.
        $linkTarget = (int) $this->order($I, 'Approved')->getId();

        // ── POSITIVE CONTROL, unlocked: the action endpoint issues it.
        $this->post($I, '/admin/invoice/' . $invoiceId . '/action/issue');
        $I->assertSame('Pending', (string) $this->row($I, 'invoice', $invoiceId)['status'], 'the invoice action endpoint did not work before the lock');

        $this->post($I, '/admin/invoice/lock/' . $invoiceId, ['reason' => 'Sent to the customer, do not touch.']);
        $I->assertIsArray($this->lockRow($I, 'invoice', $invoiceId), 'document_lock has no row for the locked invoice');

        $frozen = $this->row($I, 'invoice', $invoiceId);

        // ── WRITE PATH 1: the action endpoint. Cancel is a legal move from Pending.
        $this->post($I, '/admin/invoice/' . $invoiceId . '/action/cancel', ['reason' => 'Changed my mind.']);
        $I->assertSame('Pending', (string) $this->row($I, 'invoice', $invoiceId)['status'], 'a locked invoice was cancelled');
        $I->see('cannot be cancelled', '.flash-message[data-type="error"]');
        $I->see('Unlock it — the control is on the document\'s own page', '.flash-message[data-type="error"]');

        // ── WRITE PATH 2: linking it to an order.
        $this->post($I, '/admin/invoice/' . $invoiceId . '/link', ['order_id' => (string) $linkTarget]);
        $I->assertNull($this->row($I, 'invoice', $invoiceId)['sales_order_id'], 'a locked invoice was linked to an order');

        // ── WRITE PATH 3: recording a payment against it.
        $this->post($I, '/admin/invoice/' . $invoiceId . '/payments', [
            'amount' => '100.00',
            'method' => 'Cheque',
            'comment' => 'Cheque 4471.',
        ]);
        $I->assertSame(0, $this->count($I, 'invoice_payment_application', 'invoice_id', $invoiceId), 'a payment was recorded against a locked invoice');
        $I->assertSame((string) $frozen['payment_status'], (string) $this->row($I, 'invoice', $invoiceId)['payment_status'], 'a locked invoice\'s payment_status moved');

        // ── WRITE PATH 4: a LINE, through the service layer — no controller, no route, which is the
        //    case an enumerated list of controller guards would miss entirely.
        $priceBefore = (string) $this->lines($I, 'invoice_line', 'invoice_id', $invoiceId)[0]['price'];
        $em = $this->em($I);
        $em->clear();
        $line = $em->getRepository(InvoiceLine::class)->findOneBy(['invoice' => $invoiceId]);
        $line->setPrice('1.00');
        $refused = false;
        try {
            $em->flush();
        } catch (DocumentLocked) {
            $refused = true;
        }
        $I->assertTrue($refused, 'a line change on a locked invoice was not refused');
        $em->clear();
        $I->assertSame($priceBefore, (string) $this->lines($I, 'invoice_line', 'invoice_id', $invoiceId)[0]['price'], 'a locked invoice\'s line price moved');

        $I->assertSame(array_values($frozen), array_values($this->row($I, 'invoice', $invoiceId)), 'a column of the locked invoice moved');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 5. A locked document can still be printed, emailed and cloned.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Without this case, case 4 would pass on a change that broke the documents entirely.
     *
     * Emailing is the load-bearing one: `Invoice::recordSent()` writes an `InvoiceLog` row, so an
     * over-broad guard that froze the timeline as well would refuse the send while every refusal
     * assertion above stayed green.
     */
    public function aLockedDocumentIsStillPrintedEmailedAndCloned(FunctionalTester $I): void
    {
        $this->begin($I);

        // Raised against an order, unlike every other invoice in this file. Not incidental: the
        // shipped invoice emails describe the order the goods were ordered on, so
        // InvoiceController::send() refuses a STANDALONE invoice outright — "is not raised against
        // an order, and the shipped invoice emails describe one". An invoice that cannot be emailed
        // at all could not prove that a LOCKED one still can.
        $order = $this->order($I, 'Approved');
        $invoice = $this->invoice($I, 'Draft');
        $invoice->setSalesOrder($order);
        $this->em($I)->flush();
        $invoice->issue(DocumentActor::system());
        $this->em($I)->flush();
        $invoiceId = (int) $invoice->getId();

        $this->post($I, '/admin/invoice/lock/' . $invoiceId);
        $I->assertIsArray($this->lockRow($I, 'invoice', $invoiceId), 'document_lock has no row for the locked invoice');

        // ── PRINT. The document screen and the packing slip both render.
        $I->amOnPage('/admin/invoice/print/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('CLONE-INV-' . $this->tag, 'body');
        $I->amOnPage('/admin/invoice/packing-slip/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();

        // ── EMAIL. The send writes an InvoiceLog row, which is precisely what the lock must not
        //    freeze — so the proof is the row count going UP on a locked document.
        $logsBefore = $this->narrativeLogCount($I, 'Invoice', $invoiceId);
        $this->post($I, '/admin/invoice/send/' . $invoiceId, ['type' => 'self']);
        $I->assertGreaterThan(
            $logsBefore,
            $this->narrativeLogCount($I, 'Invoice', $invoiceId),
            'sending a locked invoice wrote no timeline row, so either the send or the log exemption is broken',
        );
        $I->assertIsArray($this->lockRow($I, 'invoice', $invoiceId), 'sending released the lock');

        // ── CLONE. The copy exists, carries the lines, and is a Draft.
        $this->post($I, '/admin/invoice/clone/' . $invoiceId);
        $copyId = $this->newestId($I, 'invoice', $invoiceId);
        $I->assertSame('Draft', (string) $this->row($I, 'invoice', $copyId)['status'], 'a locked invoice\'s clone is not a Draft');
        $I->assertCount(2, $this->lines($I, 'invoice_line', 'invoice_id', $copyId), 'a locked invoice\'s clone lost its lines');

        // ── And the COPY is not itself locked. A lock is held against a document id, and the copy
        //    has a new one — the reason the flag lives beside the document rather than on it.
        $I->assertNull($this->lockRow($I, 'invoice', $copyId), 'the clone of a locked invoice was born locked');
        $I->assertIsArray($this->lockRow($I, 'invoice', $invoiceId), 'cloning released the original\'s lock — the positive control for the line above');

        // ── The original did not move.
        $I->assertSame('Pending', (string) $this->row($I, 'invoice', $invoiceId)['status'], 'the locked original moved while being printed, emailed and cloned');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // 6. Unlock restores editing, and not everybody may do it.
    // ═════════════════════════════════════════════════════════════════════════════════════════

    public function unlockingRestoresEditingAndOnlyASuperAdminMayDoIt(FunctionalTester $I): void
    {
        // ── A PLAIN admin locks it. Locking is anybody's.
        $this->begin($I, 'ROLE_ADMIN');
        $order = $this->order($I, 'Draft');
        $orderId = (int) $order->getId();

        $this->post($I, '/admin/order/lock/' . $orderId, ['reason' => 'Checking with the customer.']);
        $I->assertIsArray($this->lockRow($I, 'sales_order', $orderId), 'a plain admin could not lock an order');

        // ── The same plain admin cannot unlock it. The row is the assertion, not the status code:
        //    a 403 that had already deleted the row would pass a test that only read the response.
        $this->post($I, '/admin/order/unlock/' . $orderId);
        $I->assertIsArray($this->lockRow($I, 'sales_order', $orderId), 'a plain admin unlocked an order');

        // ── And the order is still frozen, proved by the write rather than by the flag.
        $this->post($I, '/admin/order/update-status/' . $orderId, ['status' => 'Approved']);
        $I->assertSame('Draft', (string) $this->row($I, 'sales_order', $orderId)['status'], 'the order was not actually frozen');

        // ── A SUPER ADMIN unlocks it. Same row, same route, different role.
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $boss = (new AdminUser())->setEmail('clone-lock-boss-' . strtolower($this->tag) . '@example.test');
        $boss->setRoles(['ROLE_SUPER_ADMIN']);
        $boss->setPassword($hasher->hashPassword($boss, 'test-password-123'));
        $I->haveInRepository($boss);
        $I->amLoggedInAs($boss, 'admin');

        $this->post($I, '/admin/order/unlock/' . $orderId);
        $I->assertNull($this->lockRow($I, 'sales_order', $orderId), 'document_lock still holds a row after Unlock');

        // ── Editing works again, proved by a column moving on the same row that refused a moment ago.
        $this->post($I, '/admin/order/update-status/' . $orderId, ['status' => 'Approved']);
        $I->assertSame('Approved', (string) $this->row($I, 'sales_order', $orderId)['status'], 'unlocking did not restore editing');

        // ── And the edit screen opens again.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/order/edit/' . $orderId);
    }
}
