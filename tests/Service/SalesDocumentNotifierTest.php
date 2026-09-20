<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\EmailTemplate;
use App\Entity\UnitOfMeasure;
use App\Service\DocumentActor;
use App\Service\AdminUrlGenerator;
use App\Service\AppSettings;
use App\Service\CustomerUrlGenerator;
use App\Service\EmailNotifier;
use App\Service\OrderTaxBreakdownService;
use App\Service\Region;
use App\Service\SalesDocumentNotifier;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\Email\EmailTemplateRenderer;
use App\Service\Email\EmailTemplateResolver;
use App\Service\Email\ShippedEmailTemplateCatalogue;
use App\Twig\SandboxedTemplateRenderer;
use Twig\Environment;

/**
 * Uses a real EmailNotifier (real Twig rendering) with a mocked MailerInterface, plus the
 * container's real CustomerUrlGenerator/AdminUrlGenerator/OrderTaxBreakdownService — same
 * "keep Doctrine/Twig real, stub only true I/O boundaries" rationale as EmailNotifierTest —
 * so these tests exercise the actual context-building (special-instruction parsing, fee/tax
 * line pass-through, admin_url inclusion) and confirm the rendered email content, not just
 * that send() was called.
 *
 * setUp() persists an EmailTemplate row per code, with the body copied verbatim from the
 * shipped templates/emails/*.html.twig fallback view, specifically so EmailNotifier::send()
 * takes the DB-body branch and renders through the real SandboxedTemplateRenderer instead of
 * falling back to the unsandboxed $twig->render($fallbackView, ...) path (SchemaTool creates
 * an empty email_template table — it does not run the seed migration). Without this, every
 * test in this file exercises code the sandbox never actually sees, which is exactly how a
 * property/function the sandbox blocks (#206, #379, #381) shipped undetected three times:
 * CI was rendering the unsandboxed fallback, production was rendering the sandboxed DB body.
 */
final class SalesDocumentNotifierTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setTradeName('Acme Trading Co')->setCode('ACME')->setPrimaryEmail('billing@acme.test');
        $this->em->persist($this->company);

        // Exactly the codes the seed migration (Version20260803150000) inserts. quote_message,
        // quote_rejected_customer and quote_accepted_short_of_stock_admin are deliberately NOT
        // seeded there, so in production those three always take EmailNotifier's unsandboxed
        // $fallbackView branch — seeding them here would test something production doesn't do.
        foreach ([
            'quote_request_received' => 'Quote request received: {{ estimate.documentNumber|default(\'\') }}',
            'quote_request_admin' => 'New quote request: {{ estimate.documentNumber|default(\'\') }}',
            'quote_provided' => 'Your quote is ready: {{ estimate.documentNumber|default(\'\') }}',
            'quote_approved_admin' => 'Quote approved: {{ estimate.documentNumber|default(\'\') }}',
            'quote_declined_admin' => 'Quote declined: {{ estimate.documentNumber|default(\'\') }}',
            'order_received' => 'Order received: {{ order.orderNumber|default(\'\') }}',
            'order_received_admin' => 'New order received: {{ order.orderNumber|default(\'\') }}',
        ] as $code => $subject) {
            $this->em->persist($this->realEmailTemplate($code, $subject));
        }

        $this->em->flush();
    }

    private function realEmailTemplate(string $code, string $subject): EmailTemplate
    {
        $body = file_get_contents(dirname(__DIR__, 2) . "/templates/emails/{$code}.html.twig");
        self::assertNotFalse($body, "No fallback view for template code \"{$code}\" to seed the test DB from.");

        return (new EmailTemplate())->setCode($code)->setModule('test')->setSubject($subject)->setBody($body);
    }

    /** @return array{0: SalesDocumentNotifier, 1: \ArrayObject<int, Email>} */
    private function notifierCapturingSentEmails(): array
    {
        $sent = new \ArrayObject();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use ($sent): void {
            $sent[] = $email;
        });

        $emailNotifier = new EmailNotifier(
            $this->em,
            $mailer,
            self::getContainer()->get(Environment::class),
            new EmailTemplateRenderer(new EmailTemplateResolver($this->em, self::getContainer()->get(ShippedEmailTemplateCatalogue::class)), self::getContainer()->get(SandboxedTemplateRenderer::class)),
            self::getContainer()->get(AppSettings::class),
        );
        $notifier = new SalesDocumentNotifier(
            $emailNotifier,
            self::getContainer()->get(CustomerUrlGenerator::class),
            self::getContainer()->get(AdminUrlGenerator::class),
            self::getContainer()->get(OrderTaxBreakdownService::class),
            self::getContainer()->get(Region::class),
        );

        return [$notifier, $sent];
    }

    private function newEstimate(): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber('EST' . ++$this->sequence)
            ->setSpecialInstructions('Expected delivery time: Next Tuesday | Leave at the back gate | Order phone number: 555-1234')
            // A "fee" line (rendered from the plain fee_lines context array) and a "shipping"
            // line (rendered from estimate.shippingLines, which returns FeeLine objects
            // directly — the branch that stayed broken after CompanyIdentity/TaxLine were
            // fixed, since this fixture never exercised it; see UserTemplateSecurityPolicy).
            ->setFeeLines(json_encode([
                ['label' => 'Rush Fee', 'amount' => 12.5, 'type' => 'fee'],
                ['label' => 'Ground Shipping', 'amount' => 9.99, 'type' => 'shipping'],
            ]))
            ->setTaxLines(json_encode([
                'lines' => [['label' => 'GST', 'rate' => 0.05, 'amount' => 10.0]],
                'total' => 10.0,
            ]))
            ->setShippingMethod('Ground');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $this->em->persist($estimate);
        $this->em->flush();

        return $estimate;
    }

    private function newOrder(): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . ++$this->sequence);
        // Notifications go out for orders that were actually placed, i.e. approved ones (#539
        // stage 2 removed setStatus; Draft is the constructor default).
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        return $order;
    }

    public function testQuoteRequestReceivedSendsToCompanyRecipientsWithParsedContext(): void
    {
        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $estimate = $this->newEstimate();
        $notifier->quoteRequestReceived($estimate);

        self::assertCount(1, $sent);
        $email = $sent[0];
        self::assertStringContainsString('EST' . $this->sequence, $email->getSubject());
        self::assertCount(1, $email->getTo());
        self::assertSame('billing@acme.test', $email->getTo()[0]->getAddress());

        $body = (string) $email->getHtmlBody();
        self::assertStringContainsString('Next Tuesday', $body); // parsed expected_delivery
        self::assertStringContainsString('Leave at the back gate', $body); // visible special_instructions
        self::assertStringNotContainsString('555-1234', $body); // phone prefix filtered out of special_instructions
        self::assertStringContainsString('Rush Fee', $body); // fee_lines rendered
        // Each asserts a class the sandbox once refused outright (#206, #379, #381):
        // CompanyIdentity (estimate.companyIdentity.tradeName), TaxLine (tax_lines loop) and
        // FeeLine via estimate.shippingLines specifically, not the pre-decoded fee_lines array.
        self::assertStringContainsString('Acme Trading Co', $body);
        self::assertStringContainsString('GST', $body);
        self::assertStringContainsString('Ground Shipping', $body);
    }

    public function testQuoteRequestAdminSendsToActiveAdminsWithAdminUrl(): void
    {
        $this->em->persist((new AdminUser())->setEmail('admin@example.com')->setPassword('x'));
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $estimate = $this->newEstimate();
        $notifier->quoteRequestAdmin($estimate);

        self::assertCount(1, $sent);
        $email = $sent[0];
        self::assertSame('admin@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('href="https://', (string) $email->getHtmlBody());
    }

    public function testQuoteProvidedSendsToCompanyRecipients(): void
    {
        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $notifier->quoteProvided($this->newEstimate());

        self::assertCount(1, $sent);
        self::assertSame('billing@acme.test', $sent[0]->getTo()[0]->getAddress());
    }

    public function testQuoteApprovedAdminIncludesConvertedOrderInContext(): void
    {
        $this->em->persist((new AdminUser())->setEmail('admin@example.com')->setPassword('x'));
        $order = $this->newOrder();
        $this->em->persist($order);
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $notifier->quoteApprovedAdmin($this->newEstimate(), $order);

        self::assertCount(1, $sent);
        $body = (string) $sent[0]->getHtmlBody();
        self::assertStringContainsString($order->getOrderNumber(), $body);
    }

    public function testQuoteDeclinedAdminSendsToActiveAdmins(): void
    {
        $this->em->persist((new AdminUser())->setEmail('admin@example.com')->setPassword('x'));
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $notifier->quoteDeclinedAdmin($this->newEstimate());

        self::assertCount(1, $sent);
        self::assertSame('admin@example.com', $sent[0]->getTo()[0]->getAddress());
    }

    public function testQuoteRejectedCustomerSendsToCompanyRecipients(): void
    {
        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $estimate = $this->newEstimate();
        $notifier->quoteRejectedCustomer($estimate);

        self::assertCount(1, $sent);
        self::assertSame('billing@acme.test', $sent[0]->getTo()[0]->getAddress());
        self::assertStringContainsString($estimate->getDocumentNumber(), (string) $sent[0]->getSubject());
    }

    /** #270: the quote's "notify customer" checkbox marked the log notified and sent nothing. */
    public function testQuoteMessageSendsTheAdminsMessageToTheCompanyRecipients(): void
    {
        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $estimate = $this->newEstimate();
        $notifier->quoteMessage($estimate, "Your pallets ship Monday.\nTracking to follow.");

        self::assertCount(1, $sent);
        self::assertSame('billing@acme.test', $sent[0]->getTo()[0]->getAddress());
        self::assertStringContainsString($estimate->getDocumentNumber(), (string) $sent[0]->getSubject());

        $body = (string) $sent[0]->getHtmlBody();
        self::assertStringContainsString('Your pallets ship Monday.', $body);
        // nl2br, so the second sentence survives the render rather than being run together.
        self::assertStringContainsString('Tracking to follow.', $body);
    }

    public function testOrderReceivedSendsBothCustomerAndAdminEmailsWithAdminUrlOnlyOnAdminCopy(): void
    {
        $this->em->persist((new AdminUser())->setEmail('admin@example.com')->setPassword('x'));
        $order = $this->newOrder();
        $this->em->persist($order);
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $notifier->orderReceived($order);

        self::assertCount(2, $sent);

        $customerEmail = $sent[0];
        self::assertSame('billing@acme.test', $customerEmail->getTo()[0]->getAddress());
        self::assertStringNotContainsString('Review Order', (string) $customerEmail->getHtmlBody());

        $adminEmail = $sent[1];
        self::assertSame('admin@example.com', $adminEmail->getTo()[0]->getAddress());
        self::assertStringContainsString('Review Order', (string) $adminEmail->getHtmlBody());
        self::assertStringContainsString($order->getOrderNumber(), (string) $adminEmail->getHtmlBody());
    }

    /** #411: order emails need the same product/fee/tax detail table quote emails already render. */
    public function testOrderReceivedIncludesFullOrderSummaryTable(): void
    {
        $order = $this->newOrder()
            ->setSpecialInstructions('Expected delivery time: Next Tuesday | Leave at the back gate | Order phone number: 555-1234')
            ->setFeeLines(json_encode([
                ['label' => 'Rush Fee', 'amount' => 12.5, 'type' => 'fee'],
                ['label' => 'Ground Shipping', 'amount' => 9.99, 'type' => 'shipping'],
            ]))
            ->setTaxLines(json_encode([
                'lines' => [['label' => 'GST', 'rate' => 0.05, 'amount' => 10.0]],
                'total' => 10.0,
            ]))
            ->setShippingMethod('Ground');
        $line = (new SalesOrderLine())->setName('Widget')->setSku('WID-1')->setQuantity('3.00')->setSubtotal('30.00');
        $order->addLine($line);
        $this->em->persist($order);
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $notifier->orderReceived($order);

        self::assertCount(1, $sent);
        $body = (string) $sent[0]->getHtmlBody();
        self::assertStringContainsString('Products', $body);
        self::assertStringContainsString('Widget', $body); // line item rendered
        self::assertStringContainsString('WID-1', $body);
        self::assertStringContainsString('Next Tuesday', $body); // parsed expected_delivery
        self::assertStringContainsString('Leave at the back gate', $body); // visible special_instructions
        self::assertStringNotContainsString('555-1234', $body); // phone prefix filtered out
        self::assertStringContainsString('Rush Fee', $body); // fee_lines rendered
        self::assertStringContainsString('Ground Shipping', $body); // shippingLines rendered
        self::assertStringContainsString('GST', $body); // tax_lines rendered
        self::assertStringContainsString('Grand Total', $body);
    }

    /**
     * `EA` and `BOX-EM-12`, the two terms the summary partials have to tell apart.
     *
     * @return array{0: UnitOfMeasure, 1: UnitOfMeasure}
     */
    private function twoTerms(): array
    {
        $each = (new UnitOfMeasure())->setCode('EA')->setName('Each')->setFamily(UnitOfMeasure::FAMILY_QUANTITY)->setFactorToFamilyBase('1')->setRoundingPrecision('1');
        $box = (new UnitOfMeasure())->setCode('BOX-EM-12')->setName('Box of 12')->setFamily(UnitOfMeasure::FAMILY_QUANTITY)->setFactorToFamilyBase('12')->setRoundingPrecision('1');
        $this->em->persist($each);
        $this->em->persist($box);
        $this->em->flush();

        return [$each, $box];
    }

    /**
     * The order email prints the term the line was ordered in (#659).
     *
     * This is the partial that leaves the building. Until now the only test rendering it used a line
     * entered in base units, so the `{% if line.unitOfMeasure %}` branch — the whole point of the
     * column — was never rendered at all, and a partial still reaching for the retired
     * `packagingUnit` would have thrown here and nowhere else.
     *
     * It matters that this renders the DB body through the SANDBOX, which is what production does:
     * `line.unitOfMeasure` hands the template a `UnitOfMeasure` ENTITY, and an object the policy does
     * not recognise is refused silently, because `EmailNotifier::send()` swallows every Throwable.
     * That is exactly how `CompanyIdentity`, `TaxLine` and `FeeLine` each sent zero emails while the
     * suite stayed green (#379, #381) — the fallback view is unsandboxed, so CI rendered a different
     * template from the one the customer received.
     */
    public function testTheOrderSummaryPrintsTheTermTheLineWasOrderedIn(): void
    {
        [$each, $box] = $this->twoTerms();
        $order = $this->newOrder();

        $boxed = (new SalesOrderLine())->setName('Boxed Widget')->setSku('EM-BOXED')->setPrice('0.50')->setSubtotal('240.00');
        $boxed->setEnteredQuantity('40', $box, $each);
        $order->addLine($boxed);
        $order->addLine((new SalesOrderLine())->setName('Plain Widget')->setSku('EM-PLAIN')->setQuantity('7.00')->setSubtotal('22.75'));
        $this->em->persist($order);
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();
        $notifier->orderReceived($order);

        $body = (string) $sent[0]->getHtmlBody();
        self::assertStringContainsString('Boxed Widget (EM-BOXED), BOX-EM-12', $body, 'the term is named beside the line');
        // Not see('40'): the same page carries $240.00 and a 12 in the term itself. The cell is
        // matched whole, closing tag included, so 480 cannot satisfy it.
        self::assertStringContainsString('>x 40</td>', $body, 'the quantity is in boxes, the unit beside it');
        self::assertStringNotContainsString('>x 480</td>', $body, 'and never the resolved base figure');
        self::assertStringNotContainsString('CASE(12)', $body, 'the bracketed pack size is gone');

        // The row that must not have changed: a base-unit line names no term and reads as it always
        // did. The trailing comma is the whole assertion — it is what the unit branch would add.
        self::assertStringContainsString('Plain Widget (EM-PLAIN)</td>', $body);
        self::assertStringContainsString('>x 7</td>', $body);
    }

    /** The same two cells on the quote summary, which is a separate partial with its own loop. */
    public function testTheQuoteSummaryPrintsTheTermTheLineWasQuotedIn(): void
    {
        [$each, $box] = $this->twoTerms();
        $estimate = $this->newEstimate();

        $boxed = (new EstimateLine())->setName('Boxed Widget')->setSku('EM-Q-BOXED')->setPrice('0.50')->setSubtotal('240.00');
        $boxed->setEnteredQuantity('40', $box, $each);
        $estimate->addLine($boxed);
        $estimate->addLine((new EstimateLine())->setName('Plain Widget')->setSku('EM-Q-PLAIN')->setQuantity('7.00')->setSubtotal('22.75'));
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();
        $notifier->quoteProvided($estimate);

        $body = (string) $sent[0]->getHtmlBody();
        self::assertStringContainsString('Boxed Widget (EM-Q-BOXED), BOX-EM-12', $body);
        self::assertStringContainsString('>x 40</td>', $body);
        self::assertStringNotContainsString('>x 480</td>', $body);
        self::assertStringNotContainsString('CASE(12)', $body);

        self::assertStringContainsString('Plain Widget (EM-Q-PLAIN)</td>', $body);
        self::assertStringContainsString('>x 7</td>', $body);
    }

    public function testOrderReceivedFallsBackToProvidedEmailWhenCompanyHasNoRecipient(): void
    {
        $bareCompany = (new Company())->setName('Bare Co')->setCode('BARE');
        $this->em->persist($bareCompany);
        $order = (new SalesOrder())->setCompany($bareCompany)->setOrderNumber('ORD-BARE-' . ++$this->sequence);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        [$notifier, $sent] = $this->notifierCapturingSentEmails();

        $notifier->orderReceived($order, 'fallback@example.com');

        self::assertCount(1, $sent); // no active admins persisted in this test -> admin copy has no recipients, never sent
        self::assertSame('fallback@example.com', $sent[0]->getTo()[0]->getAddress());
    }
}
