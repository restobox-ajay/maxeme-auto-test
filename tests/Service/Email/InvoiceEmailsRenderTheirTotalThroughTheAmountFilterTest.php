<?php

declare(strict_types=1);

namespace App\Tests\Service\Email;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Service\Email\EmailTemplateRenderer;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The two invoice emails actually render, and the figure they put in front of the customer is right.
 *
 * ## Why a fixture test was not enough
 *
 * ShippedEmailTemplatesMatchTheChainTest compares two pieces of text. It cannot tell whether either
 * of them RENDERS. When 495eaf78 ("One place decides what a number looks like") moved these two
 * bodies from `|number_format(2)` to `|amount`, the question that mattered was not whether the
 * files agreed with the migration chain — it was whether
 * `|amount` exists at all by the time an email is built, which is a different and much less obvious
 * thing:
 *
 *   - An email body is compiled through {@see \App\Twig\SandboxedTemplateRenderer}, not rendered as
 *     an ordinary view, and {@see \App\Twig\Sandbox\UserTemplateSecurityPolicy} REFUSES any filter
 *     not on its allowlist. A filter that works on every admin screen can be forbidden here.
 *   - Email is the one output built outside a web request — from a controller action, but also
 *     from console commands and the messenger worker — so "the extension is registered" is a claim
 *     about the whole environment, not about one page.
 *   - {@see \App\Service\Email\EmailNotifier} swallows Throwables at the send site. A template that
 *     throws does not 500; it sends nothing, quietly. #379/#381 are six email templates that sent
 *     zero mail for months because the sandbox refused three property reads.
 *
 * So the invariant to pin is end to end: give the real renderer a real invoice with a real total,
 * and read the money out of the finished HTML. A failure here is an email that does not go out, or
 * one that goes out with the wrong number on it — neither of which a text comparison can see.
 *
 * The assertions deliberately name the FIGURE and not the filter. `|amount` and `number_format(2)`
 * render money identically, which is why that commit could adopt it without a copy change. Pinning
 * the output rather than the spelling is what lets the next change to how money prints be judged
 * on what a customer sees.
 *
 * ## Invoice-first, order optional (Version20260919180000)
 *
 * Every fixture here supplies `invoice` and never `order` — the shipped bodies read the invoice's
 * own fields (`invoice.total`, `invoice.company.name`, `invoice.documentNumber`,
 * `invoice.documentDate`) as of the same change that made a standalone invoice (no sales order
 * behind it) actually emailable instead of refused. `testTheCustomerCopyStillNamesTheOrderWhenOneExists`
 * is the one case that also supplies `order`, proving the optional half still renders when present.
 */
final class InvoiceEmailsRenderTheirTotalThroughTheAmountFilterTest extends DoctrineIntegrationTestCase
{
    private EmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = self::getContainer()->get(EmailTemplateRenderer::class);
    }

    /**
     * The customer's copy. 1234.5 in the column, "$1,234.50" in the inbox.
     *
     * The stored value is deliberately NOT already in display form: `total` is decimal(14,2) read
     * back as a string, and a template that printed it raw would say "$1234.5" — which is the
     * assertion below that proves formatting happened at all rather than the figure passing
     * through untouched.
     */
    public function testTheCustomerInvoiceEmailPrintsItsGrandTotalAsMoney(): void
    {
        $rendered = $this->renderer->render('invoice_customer', [
            'invoice' => $this->invoice('1234.5'),
            'order_url' => 'https://shop.example/order/1',
        ]);

        self::assertNotNull($rendered, 'invoice_customer must resolve from the shipped catalogue with no row present');

        self::assertStringContainsString(
            '<strong>$1,234.50</strong>',
            $rendered->body,
            'the grand total renders as money: two decimals, thousands separated',
        );
        self::assertStringNotContainsString(
            '$1234.5<',
            $rendered->body,
            'and not as the raw column value, which is what an unapplied filter would leave behind',
        );

        // The rest of the email is here too, so a body that rendered its total and nothing else —
        // a truncated or half-swallowed template — does not pass on the one assertion above.
        self::assertStringContainsString('INV-1001', $rendered->body, 'the invoice number is in the body');
        self::assertStringContainsString('Northfield Tire &amp; Auto', $rendered->body, 'and the company name');
        self::assertStringContainsString('Invoice #INV-1001', $rendered->subject, 'the subject renders too');
    }

    /**
     * No `order` key in the context at all — the exact shape a standalone invoice's send() call
     * supplies. `order_url` is absent too, so the "View Order Details" button must not appear.
     */
    public function testTheCustomerInvoiceEmailRendersFullyWithNoOrderBehindIt(): void
    {
        $rendered = $this->renderer->render('invoice_customer', [
            'invoice' => $this->invoice('99.00'),
        ]);

        self::assertNotNull($rendered);
        self::assertStringContainsString('<strong>$99.00</strong>', $rendered->body);
        self::assertStringNotContainsString('href="#"', $rendered->body, 'no order_url means no dead link, not a blank one');
        self::assertStringNotContainsString('View Order Details', $rendered->body, 'the button itself is gone, not just its target');
    }

    /**
     * The one case that also supplies `order` — proving the optional half still renders when an
     * order really is behind the invoice, the far more common case in practice.
     */
    public function testTheCustomerCopyStillNamesTheOrderWhenOneExists(): void
    {
        $company = (new Company())->setName('Northfield Tire & Auto');
        $order = (new SalesOrder())->setOrderNumber('SO-1001')->setCompany($company)->setDocumentDate('2026-09-12')->setTotal('1234.5');

        $rendered = $this->renderer->render('invoice_customer', [
            'invoice' => $this->invoice('1234.5', $company),
            'order' => $order,
            'order_url' => 'https://shop.example/order/1',
        ]);

        self::assertNotNull($rendered);
        self::assertStringContainsString('href="https://shop.example/order/1"', $rendered->body, 'order_url renders the button when it is present');
        self::assertStringContainsString('View Order Details', $rendered->body);
    }

    /** The internal copy of the same document, changed in the same commit and just as capable of not sending. */
    public function testTheInternalInvoiceEmailPrintsItsTotalAsMoney(): void
    {
        $rendered = $this->renderer->render('invoice_self', [
            'invoice' => $this->invoice('1234.5'),
            'admin_url' => 'https://admin.example/invoice/1',
        ]);

        self::assertNotNull($rendered, 'invoice_self must resolve from the shipped catalogue with no row present');
        self::assertStringContainsString('<strong>$1,234.50</strong>', $rendered->body);
        self::assertStringNotContainsString('$1234.5<', $rendered->body);
    }

    /**
     * The two ends of the rule, on the emails rather than on the service.
     *
     * A third decimal is ROUNDED to the cent rather than printed or truncated, and a whole number is
     * PADDED to two places rather than left bare — the two ways a money figure most often comes out
     * wrong, and both of them things a customer would read as a different amount owed.
     */
    public function testTheTotalIsRoundedToTheCentAndPaddedToTwoPlaces(): void
    {
        $rounded = $this->renderer->render('invoice_customer', ['invoice' => $this->invoice('1234.567')]);
        self::assertNotNull($rounded);
        self::assertStringContainsString('<strong>$1,234.57</strong>', $rounded->body, 'a third decimal rounds to the cent');

        $whole = $this->renderer->render('invoice_customer', ['invoice' => $this->invoice('90')]);
        self::assertNotNull($whole);
        self::assertStringContainsString('<strong>$90.00</strong>', $whole->body, 'a whole amount still shows both places');
    }

    /**
     * An invoice whose total is genuinely absent renders "$0.00" and never an empty `$`.
     *
     * `|amount` returns the empty string for null — that is the rule that keeps an unpriced quote
     * line saying TBD instead of claiming to be free — so these bodies pair it with `|default(0)`,
     * and this is the test that stops that guard being dropped as redundant. "$" with nothing after
     * it, on an invoice, is worse than a wrong number: it reads as a broken email.
     */
    public function testAnAbsentTotalStillRendersAnAmount(): void
    {
        $rendered = $this->renderer->render('invoice_customer', ['invoice' => $this->invoice(null)]);

        self::assertNotNull($rendered);
        self::assertStringContainsString('<strong>$0.00</strong>', $rendered->body);
    }

    private function invoice(?string $total, ?Company $company = null): Invoice
    {
        $company ??= (new Company())->setName('Northfield Tire & Auto');

        return (new Invoice())
            ->setDocumentNumber('INV-1001')
            ->setCompany($company)
            ->setDocumentDate('2026-09-12')
            ->setTotal($total);
    }
}
