<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * One line, every surface that prints it, one answer (#624).
 *
 * ## The defect
 *
 * A line stored as `2.5000` printed **"3"** on the sales order PDF, **"2.5"** on the order detail
 * screen and **"3"** in the confirmation email the customer received. Three renderings of one
 * stored number, none of them agreeing, each fixed on its own screen and each fix leaving the other
 * two wrong — because the formatting decision was taken separately in every template and there was
 * no one place that owned it.
 *
 * `App\Service\DisplayNumber` is that place now, and this file is the proof that every surface
 * reaches it. The same document is driven through the admin detail screen, its printed PDF, the
 * packing slip, the customer's own screen and the email, and the SAME cell is read on each: they
 * agree, or this fails.
 *
 * ## How these are written (#624, #627)
 *
 * Conducted. The fixtures are built here, the real screens are driven, and the stored column is
 * re-read from the database afterwards rather than trusted from an entity fetched beforehand — a
 * display change must not write anything, and the only way to know is to look.
 *
 * No assertion is a bare number. `see('2.5')` matches '12.5', '2.50' and '2.5000' alike, which on a
 * document made entirely of figures is most of the page, so every figure is grabbed from an element
 * addressed by its own id and compared with `assertSame`.
 *
 * Every case carries a positive control on the SAME element as the thing it is asserting the
 * absence of: the fractional line reads "2.5" and the whole line beside it reads "3" — not "3.0",
 * not "3.0000", not "3.00" — so "the table did not render" cannot pass as "the rounding is gone",
 * and neither can "the filter prints the column scale verbatim".
 *
 * No state is kept on $this: Codeception reuses one Cest instance across every method in the file.
 */
final class OneNumberFormattingRuleAcrossEverySurfaceCest
{
    /**
     * The line that used to round to 3, and the whole line beside it that must not gain decimals.
     *
     * `2.5000` and `3.0000` are how `NUMERIC(14,4)` hands the values back while the entities are
     * still in the identity map. The price is `0.917000`: a real sub-cent unit price — the one
     * `AdminSalesLineQuantityAndPriceRulesCest::anOrderAndAQuoteWithSubCentLineFractionsReachTheSameGrandTotal`
     * posts — which `number_format(2)` printed as `$0.92`, a figure that does not multiply back to
     * the line's own subtotal.
     */
    private const FRACTIONAL_QTY = '2.5000';
    private const WHOLE_QTY = '3.0000';
    private const SUB_CENT_PRICE = '0.917000';

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('one-formatting-rule@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ── The order: screen, document, packing slip ───────────────────────────────────────────────

    /**
     * The admin order detail screen and the sales order PDF print the same quantity.
     *
     * The PDF is where the defect lived: `sales_order.html.twig:199` was
     * `{{ line.quantityEntered|number_format(0) }}` while the detail screen beside it had already
     * been conformed by hand. Both now go through `|qty`, and this asserts they agree rather than
     * asserting either one on its own — a test reading one side would not have caught the split.
     */
    public function theOrderScreenAndItsPrintedDocumentAgreeOnAFractionalQuantity(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Formatting Rule Order Co');
        $order = $this->makeOrder($I, $company);
        [$fractional, $whole] = $this->linesOf($order);

        // ── The screen ─────────────────────────────────────────────────────────────────────────
        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $screenFractional = $this->cell($I, '#order-line-qty-' . $fractional->getId());
        $screenWhole = $this->cell($I, '#order-line-qty-' . $whole->getId());
        $screenPrice = $this->cell($I, '#order-line-price-' . $fractional->getId());

        // ── The printed document ───────────────────────────────────────────────────────────────
        $I->amOnPage('/admin/order/document/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $documentFractional = $this->cell($I, '#doc-line-qty-' . $fractional->getId());
        $documentWhole = $this->cell($I, '#doc-line-qty-' . $whole->getId());

        // The defect, and it is stated as the two surfaces agreeing rather than as either figure.
        $I->assertSame(
            $screenFractional,
            $documentFractional,
            'the order screen and the sales order PDF must print one quantity, not two',
        );

        // What they agree ON. "3" used to be what the PDF said of a line of two and a half.
        $I->assertSame('2.5', $documentFractional, 'a line of 2.5 prints as 2.5 and is not rounded to 3');

        // Positive control, same column, same table, same render: a whole quantity keeps no
        // decimals at all. Without it, a filter that printed the column scale verbatim — "2.5000"
        // and "3.0000" — would satisfy the assertion above and be just as wrong.
        $I->assertSame($screenWhole, $documentWhole, 'and they agree on a whole one too');
        $I->assertSame('3', $documentWhole, 'a whole quantity shows no decimal point');

        // The other rule, on the same row: the screen's price cell agrees with the document's.
        $I->assertSame(
            $screenPrice,
            $this->cell($I, '#doc-line-price-' . $fractional->getId()),
            'and one unit price, not two',
        );

        // ── Nothing was written ────────────────────────────────────────────────────────────────
        $stored = $this->connection($I)->fetchAllKeyValue(
            'SELECT id, quantity FROM sales_order_line WHERE order_id = ?',
            [$order->getId()],
        );
        // Compared as figures rather than as strings: SQLite's numeric affinity hands a
        // `NUMERIC(14,4)` column back as `2.5`, not `2.5000`, so the spelling that comes out of the
        // database is not the spelling that went in and never was. What must not have moved is the
        // VALUE.
        $I->assertSame(
            (float) self::FRACTIONAL_QTY,
            (float) $stored[$fractional->getId()],
            'displaying a figure must not restate the column it came from',
        );
        $I->assertSame((float) self::WHOLE_QTY, (float) $stored[$whole->getId()]);
    }

    /**
     * The sub-cent unit price survives the round trip to the page.
     *
     * `$0.917` is what the line is priced at and what its subtotal is computed from. Printed as
     * `$0.92` — which is what `number_format(2)` did — the line stops explaining its own arithmetic:
     * 2.5 x $0.92 is $2.30 against a stored subtotal of $2.29.
     */
    public function theOrderDocumentPrintsAUnitPriceAtThePrecisionItIsStoredAt(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Formatting Rule Price Co');
        $order = $this->makeOrder($I, $company);
        [$fractional, $whole] = $this->linesOf($order);

        $I->amOnPage('/admin/order/document/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            '$0.917',
            $this->cell($I, '#doc-line-price-' . $fractional->getId()),
            'a sub-cent unit price keeps its third place rather than being rounded to the cent',
        );

        // Positive control on the same column: a plain cent price still floors at two places, so
        // the rule has not become "print whatever the column holds".
        $I->assertSame(
            '$4.00',
            $this->cell($I, '#doc-line-price-' . $whole->getId()),
            'and a whole-dollar price is still money, with its two decimals',
        );

        $stored = $this->connection($I)->fetchOne(
            'SELECT price FROM sales_order_line WHERE id = ?',
            [$fractional->getId()],
        );
        $I->assertSame(
            (float) self::SUB_CENT_PRICE,
            (float) $stored,
            'the price column is untouched by showing it',
        );
    }

    /**
     * The packing slip is a third rendering of the same line and used to round like the PDF.
     *
     * `packing_slip.html.twig` carried the defect TWICE — once on the entered quantity and once on
     * the base quantity beside it — which matters more here than anywhere else: this is the document
     * somebody counts a pallet against, and a line of two and a half cases printed as "3" is a
     * short-pick waiting to be blamed on the warehouse.
     */
    public function thePackingSlipAgreesWithTheInvoiceItShipsAgainst(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Formatting Rule Packing Co');
        $invoice = $this->makeInvoice($I, $company);
        [$fractional, $whole] = $this->linesOf($invoice);

        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $printedFractional = $this->cell($I, '#doc-line-qty-' . $fractional->getId());
        $printedWhole = $this->cell($I, '#doc-line-qty-' . $whole->getId());

        $I->amOnPage('/admin/invoice/packing-slip/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            $printedFractional,
            $this->cell($I, '#doc-line-qty-' . $fractional->getId()),
            'the packing slip counts what the invoice billed',
        );
        $I->assertSame('2.5', $this->cell($I, '#doc-line-qty-' . $fractional->getId()));

        // Positive control on the same column of the same table.
        $I->assertSame($printedWhole, $this->cell($I, '#doc-line-qty-' . $whole->getId()));
        $I->assertSame('3', $this->cell($I, '#doc-line-qty-' . $whole->getId()));

        $stored = $this->connection($I)->fetchAllKeyValue(
            'SELECT id, quantity FROM invoice_line WHERE invoice_id = ?',
            [$invoice->getId()],
        );
        $I->assertSame((float) self::FRACTIONAL_QTY, (float) $stored[$fractional->getId()]);
    }

    // ── The customer's own screens ──────────────────────────────────────────────────────────────

    /**
     * The buyer sees the figure the seller sees.
     *
     * `customer/order/detail.html.twig:218` was `|number_format(0, '.', '')` — the same rounding
     * with the separator spelled out — so a customer checking a part-case order against their own
     * paperwork read a different quantity from the one on the seller's screen.
     */
    public function theCustomerOrderScreenPrintsTheSameQuantityTheAdminSees(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Formatting Rule Customer Co');
        $order = $this->makeOrder($I, $company);
        [$fractional, $whole] = $this->linesOf($order);

        $I->amOnPage('/admin/order/document/' . $order->getId());
        $adminFractional = $this->cell($I, '#doc-line-qty-' . $fractional->getId());

        $this->loginAsCustomerOf($I, $company);
        $I->amOnPage('/orders/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $customerFractional = $this->cell($I, '#customer-line-qty-' . $fractional->getId());
        $I->assertSame($adminFractional, $customerFractional, 'both sides of the sale read one quantity');
        $I->assertSame('2.5', $customerFractional);

        // Positive control on the same column.
        $I->assertSame('3', $this->cell($I, '#customer-line-qty-' . $whole->getId()));

        // And the price cell beside it, which is the other rule.
        $I->assertSame(
            '$0.917',
            $this->cell($I, '#customer-line-price-' . $fractional->getId()),
            'the customer is shown the rate their subtotal was computed from',
        );
    }

    // ── The email ───────────────────────────────────────────────────────────────────────────────

    /**
     * The confirmation email is the third disagreeing copy from the defect report.
     *
     * It is also the surface that fails LOUDEST if the formatter is unreachable: an email body
     * stored in `email_template` renders through `App\Twig\Sandbox\UserTemplateSecurityPolicy`,
     * which allowlists filters by name, and the layout it is wrapped in includes
     * `emails/_order_summary.html.twig`. The first run of this work adopted the filters in that
     * partial without adding them to the policy and the order confirmation stopped being sent at
     * all — green everywhere except here.
     */
    public function theOrderConfirmationEmailPrintsTheSameQuantityAsTheDocument(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Formatting Rule Email Co');
        $order = $this->makeOrder($I, $company);
        [$fractional, $whole] = $this->linesOf($order);

        $I->amOnPage('/admin/order/document/' . $order->getId());
        $documentFractional = $this->cell($I, '#doc-line-qty-' . $fractional->getId());

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $token = $I->csrfToken();
        $I->stopFollowingRedirects();
        $I->sendFormPostRequest('/admin/order/update-status/' . $order->getId(), [
            '_token' => $token,
            'status' => 'Approved',
            'notify_client' => '1',
        ]);
        $I->seeEmailIsSent();
        $I->startFollowingRedirects();

        $body = $this->lastEmailHtml($I);

        $I->assertSame(
            'x ' . $documentFractional,
            $this->cellIn($body, 'email-line-qty-' . $fractional->getId()),
            'the email and the document print one quantity',
        );
        $I->assertSame('x 2.5', $this->cellIn($body, 'email-line-qty-' . $fractional->getId()));

        // Positive control on the same element in the same email.
        $I->assertSame('x 3', $this->cellIn($body, 'email-line-qty-' . $whole->getId()));
    }

    // ── The quote, whose price may legitimately be absent ───────────────────────────────────────

    /**
     * An unpriced quote line still says TBD and never `$0.00`.
     *
     * This is the rule the filters had to survive rather than replace (#254, #255). `|price` renders
     * null as the empty string, so the template's own `is not null` guard still decides, and a line
     * nobody has quoted yet cannot be read as one that is free.
     */
    public function anUnpricedQuoteLineSaysTbdAndNotZero(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Formatting Rule Quote Co');
        $estimate = $this->makeEstimateWithAnUnpricedLine($I, $company);
        [$priced, $unpriced] = $this->linesOf($estimate);

        $I->amOnPage('/admin/estimate/quote/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            'TBD',
            $this->cell($I, '#doc-line-price-' . $unpriced->getId()),
            'a null price is "not quoted yet", which is not a number and certainly not zero',
        );

        // Positive control on the SAME column: the priced line beside it renders its figure, so
        // "TBD" cannot be the table failing to render prices at all.
        $I->assertSame('$0.917', $this->cell($I, '#doc-line-price-' . $priced->getId()));

        // And the quantity rule holds on a quote exactly as on an order.
        $I->assertSame('2.5', $this->cell($I, '#doc-line-qty-' . $priced->getId()));
        $I->assertSame('3', $this->cell($I, '#doc-line-qty-' . $unpriced->getId()));

        $storedPrice = $this->connection($I)->fetchOne(
            'SELECT price FROM estimate_line WHERE id = ?',
            [$unpriced->getId()],
        );
        $I->assertNull($storedPrice, 'the column is still null: nothing about showing TBD filled it in');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('ONR-' . uniqid())
            ->setPrimaryEmail('ap-' . uniqid() . '@one-rule.example')
            ->setPhoneNumber('+1 604 555 0177');
        $I->haveInRepository($company);

        return $company;
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);

        if ($existing instanceof ProductCore) {
            return $existing;
        }

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('One Rule ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    /**
     * Two lines: one at two and a half, one at three. Both figures spelled at the column's scale,
     * because that is what the entity holds until the row has been round-tripped through SQLite.
     */
    private function makeOrder(FunctionalTester $I, Company $company): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ONR-SO-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSubtotal('14.29')
            ->setTax('0.00')
            ->setTotal('14.29');

        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product($I, 'ONR-FRACTIONAL'))
                ->setName('Half a case')
                ->setSku('ONR-FRACTIONAL')
                ->setQuantity(self::FRACTIONAL_QTY)
                ->setPrice(self::SUB_CENT_PRICE)
                ->setSubtotal('2.29'),
        );
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product($I, 'ONR-WHOLE'))
                ->setName('Three whole ones')
                ->setSku('ONR-WHOLE')
                ->setQuantity(self::WHOLE_QTY)
                ->setPrice('4.000000')
                ->setSubtotal('12.00'),
        );

        $I->haveInRepository($order);

        return $order;
    }

    private function makeEstimateWithAnUnpricedLine(FunctionalTester $I, Company $company): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('ONR-EST-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('2.29')
            ->setTax('0.00')
            ->setTotal('2.29');
        $estimate->setStatus('Priced', DocumentActor::system());

        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($this->product($I, 'ONR-FRACTIONAL'))
                ->setName('Half a case, quoted')
                ->setSku('ONR-FRACTIONAL')
                ->setQuantity(self::FRACTIONAL_QTY)
                ->setPrice(self::SUB_CENT_PRICE)
                ->setSubtotal('2.29'),
        );
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($this->product($I, 'ONR-WHOLE'))
                ->setName('Three whole ones, not yet quoted')
                ->setSku('ONR-WHOLE')
                ->setQuantity(self::WHOLE_QTY)
                ->setPrice(null)
                ->setSubtotal(null),
        );

        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function loginAsCustomerOf(FunctionalTester $I, Company $company): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())
            ->setEmail('buyer-' . uniqid() . '@one-rule.example')
            ->setFirstName('Bea')
            ->setLastName('Buyer')
            ->setCompany($company);
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        $I->amLoggedInAs($user, 'main');
    }

    /**
     * An invoice carrying the same two lines. Built directly rather than raised through the order:
     * what is under test is how the packing slip PRINTS a line, and routing the fixture through the
     * invoicing service would put its own rounding between the column and the page.
     */
    private function makeInvoice(FunctionalTester $I, Company $company): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('ONR-INV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal('14.29')
            ->setTax('0.00')
            ->setTotal('14.29');

        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($this->product($I, 'ONR-FRACTIONAL'))
                ->setName('Half a case')
                ->setSku('ONR-FRACTIONAL')
                ->setQuantity(self::FRACTIONAL_QTY)
                ->setPrice(self::SUB_CENT_PRICE)
                ->setSubtotal('2.29'),
        );
        $invoice->addLine(
            (new InvoiceLine())
                ->setProduct($this->product($I, 'ONR-WHOLE'))
                ->setName('Three whole ones')
                ->setSku('ONR-WHOLE')
                ->setQuantity(self::WHOLE_QTY)
                ->setPrice('4.000000')
                ->setSubtotal('12.00'),
        );

        $I->haveInRepository($invoice);

        return $invoice;
    }

    // ── Cell readers ────────────────────────────────────────────────────────────────────────────

    /**
     * One element, addressed by its own id, with its whitespace collapsed.
     *
     * Never `see()`: this whole file is numbers, and `see('2.5')` matches '12.5' and '2.50' as
     * happily as the figure it names (#627).
     */
    private function cell(FunctionalTester $I, string $selector): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $I->grabTextFrom($selector)));
    }

    /** The same reading, against an email body rather than the current page. */
    private function cellIn(string $html, string $id): string
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $element = $document->getElementById($id);

        if ($element === null) {
            throw new \RuntimeException(sprintf('The email has no element with id "%s".', $id));
        }

        return trim((string) preg_replace('/\s+/', ' ', $element->textContent));
    }

    private function lastEmailHtml(FunctionalTester $I): string
    {
        $message = $I->grabLastSentEmail();

        return (string) $message->getHtmlBody();
    }

    /**
     * @return list<SalesOrderLine|EstimateLine|InvoiceLine>
     */
    private function linesOf(SalesOrder|Estimate|Invoice $document): array
    {
        return array_values($document->getLines()->toArray());
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
