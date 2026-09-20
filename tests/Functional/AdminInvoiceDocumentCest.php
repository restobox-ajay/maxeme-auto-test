<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\EmailTemplate;
use App\Entity\ProductCore;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The invoice document and the emails that carry it.
 *
 * Was AdminSalesOrderInvoiceDocumentCest: every one of these used to be driven through
 * /admin/order/invoice/{id}, because an order printed itself as an invoice. #539 stage 6 splits
 * them, so the same assertions now run against /admin/invoice/print/{id} and
 * /admin/invoice/send/{id} — the file moved with the document rather than being rewritten, since
 * what it covers (issue #35 item 6, and the seller-identity and email-link fixes from #351/#223)
 * is unchanged by which entity the page hangs off.
 *
 * Clone stays an ORDER action and is exercised through the order's own document page.
 */
final class AdminInvoiceDocumentCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-order-invoice-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /**
     * An approved order with one issued invoice covering the whole of it.
     *
     * Both documents, because since #539 stage 6 they are two different pages: the invoice is what
     * this file is about, and the order is what several of these tests still need an id for.
     *
     * @return array{0: SalesOrder, 1: Invoice}
     */
    private function makeInvoicedOrder(FunctionalTester $I): array
    {
        $order = $this->makeOrder($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $invoice = (new Invoice())
            ->setCompany($order->getCompany())
            ->setDocumentNumber('INVDOC-INV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setInvoiceDate('2026-08-20')
            ->setPaymentMethod($order->getPaymentMethod())
            ->setPaymentTerm($order->getPaymentTerm())
            ->setSpecialInstructions($order->getSpecialInstructions())
            ->setSubtotal($order->getSubtotal())
            ->setTax($order->getTax())
            ->setTotal($order->getTotal());
        $order->addInvoice($invoice);

        foreach ($order->getLines() as $line) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setSalesOrderLine($line)
                    ->setName($line->getName())
                    ->setSku($line->getSku())
                    ->setQuantity($line->getQuantity())
                    ->setPrice($line->getPrice())
                    ->setSubtotal($line->getSubtotal()),
            );
        }

        $em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $em->flush();

        return [$order, $invoice];
    }

    private function makeOrder(FunctionalTester $I): SalesOrder
    {
        $company = (new Company())
            ->setName('Invoice Doc Test Co')
            ->setCode('INVDOC-' . uniqid())
            ->setPrimaryEmail('buyer@invoice-doc.example');
        $I->haveInRepository($company);

        $product = (new ProductCore())->setSku('INVDOC-SKU-1')->setName('Invoice Doc Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('INVDOC-' . uniqid())
            ->setPaymentMethod('Pay Upon Delivery')
            ->setPaymentTerm('Net 15')
            ->setSpecialInstructions('Ring the bell twice on arrival.')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Invoice Doc Test Product')
                ->setSku('INVDOC-SKU-1')
                ->setQuantity('2')
                ->setPrice('20.00')
                ->setSubtotal('40.00')
        );
        $I->haveInRepository($order);

        // A live order, which since #539 stage 2 is approve() on a persisted Draft. It carries no
        // invoices, so the derived status settles at Approved.
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    public function invoiceShowsThePaymentMethodAndTheCustomerNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Pay Upon Delivery');
        $I->see('Ring the bell twice on arrival.');
        $I->dontSee('Happy Days');
        $I->dontSee('ADMIN CONSOLE', '.invoice-document');
    }

    /**
     * The invoice-page header used to print "ADMIN CONSOLE" next to the app name — an internal
     * label with no business on a document a customer might receive. Same wording also leaked
     * into the "invoice to self" email button ("Open in Admin Console"), including the copy
     * stored in the `invoice_self` EmailTemplate DB row that OrderController::sendInvoice()
     * actually renders at send time (see migrations/Version20260729000000.php).
     */
    public function sendingInvoiceToSelfDoesNotMentionAdminConsole(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $I->haveInRepository(
            (new EmailTemplate())
                ->setCode('invoice_self')
                ->setModule('Invoice (Internal)')
                ->setSentTo('Admin')
                ->setSubject('Internal: Invoice for Order #{{ order.orderNumber|default(\'\') }}')
                ->setBody(<<<'TWIG'
{% extends 'emails/layout.html.twig' %}

{% block title %}Internal: Invoice for Order #{{ order.orderNumber }}{% endblock %}

{% block body %}
    <h2 style="margin-top: 0; color: #0f172a;">Internal Invoice Copy</h2>
    <p>This is an internal copy of the invoice generated for order <span class="highlight">#{{ order.orderNumber }}</span>.</p>

    <div style="text-align: center;">
        <a href="{{ admin_url|default('#') }}" class="button">Review Order</a>
    </div>
{% endblock %}
TWIG)
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $token = $I->grabAttributeFrom(
            '//form[.//input[@name="type" and @value="self"]]//input[@name="_token"]',
            'value'
        );

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/invoice/send/' . $invoice->getId(), ['_token' => $token, 'type' => 'self']);

        $I->seeEmailIsSent();
        $I->assertEmailHtmlBodyNotContains('Open in Admin Console');
        $I->assertEmailHtmlBodyContains('Review Order');
    }

    /**
     * The button in that same email was built with generateUrl(), whose default is a bare path.
     * "/admin/order/invoice/12" resolves against whatever the mail client happens to be showing,
     * i.e. nothing — the link goes nowhere (#223). AdminUrlGenerator anchors it to ADMIN_HOST, the
     * way every other admin link sent by email already is.
     */
    public function theInvoiceEmailLinksToTheOrderWithAnAbsoluteUrl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $I->haveInRepository(
            (new EmailTemplate())
                ->setCode('invoice_self')
                ->setModule('Invoice (Internal)')
                ->setSentTo('Admin')
                ->setSubject('Internal: Invoice for Order #{{ order.orderNumber|default(\'\') }}')
                ->setBody(<<<'TWIG'
{% extends 'emails/layout.html.twig' %}

{% block body %}
    <div style="text-align: center;">
        <a href="{{ admin_url|default('#') }}" class="button">Review Order</a>
    </div>
{% endblock %}
TWIG)
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $token = $I->grabAttributeFrom(
            '//form[.//input[@name="type" and @value="self"]]//input[@name="_token"]',
            'value'
        );

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/invoice/send/' . $invoice->getId(), ['_token' => $token, 'type' => 'self']);

        $I->seeEmailIsSent();
        $I->assertEmailHtmlBodyContains('href="http://admin.localhost/admin/invoice/print/' . $invoice->getId() . '"');
        $I->assertEmailHtmlBodyNotContains('href="#"');
    }

    /**
     * #351 item 1: "Your Invoice for Order #SO5 — the link goes to the wrong place."
     *
     * The customer copy's only call to action is the buyer's own view of that order —
     * customer_order_detail, /orders/{id} on CUSTOMER_HOST, which is where the "Download Invoice"
     * button lives. sendInvoice() used to hand admin_url to this template as well as to the internal
     * copy, so an edited body could put a /admin/... link in a buyer's inbox: a host they have no
     * account on, behind a ROLE_ADMIN access rule. Only the internal copy gets admin_url now.
     */
    public function theCustomerInvoiceEmailLinksToTheBuyersOwnOrderPageNotTheAdminPanel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $I->haveInRepository(
            (new EmailTemplate())
                ->setCode('invoice_customer')
                ->setModule('Invoice (Customer)')
                ->setSentTo('Customer')
                ->setSubject('Invoice for Order #{{ order.orderNumber|default(\'\') }}')
                ->setBody(<<<'TWIG'
{% extends 'emails/layout.html.twig' %}

{% block body %}
    <div style="text-align: center;">
        <a href="{{ order_url|default('#') }}" class="button">View Order Details</a>
    </div>
    <div style="text-align: center;">
        <a href="{{ admin_url|default('#') }}" class="button">Should Not Resolve</a>
    </div>
{% endblock %}
TWIG)
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $token = $I->grabAttributeFrom(
            '//form[.//input[@name="type" and @value="customer"]]//input[@name="_token"]',
            'value'
        );

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/invoice/send/' . $invoice->getId(), ['_token' => $token, 'type' => 'customer']);

        $I->seeEmailIsSent();
        // CUSTOMER_HOST, not the admin host this request was made on.
        $I->assertEmailHtmlBodyContains('href="http://127.0.0.1/orders/' . $order->getId() . '"');
        $I->assertEmailHtmlBodyNotContains('/admin/');
        // Sanity that the assertion above is not passing because nothing rendered.
        $I->assertEmailHtmlBodyContains('View Order Details');
    }

    /**
     * The From: is the sending identity, not one of the shipped placeholder domains (#351 item 6).
     * This path builds and sends its Email by hand, so it is the end-to-end proof that
     * AppSettings::applyFromAddress() reaches a real send.
     *
     * sales_email is set here and deliberately expected *not* to appear: since #474 it is a
     * contact address the invoice template prints, and nothing to do with who the mail is from.
     * The sender resolves to MAILER_FROM, which .env.test sets for the suite.
     */
    public function theInvoiceEmailIsNotSentFromAPlaceholderDomain(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->persist(
            (new AppSetting())->setSettingKey('sales_email')->setName('sales_email')
                ->setSettingValue('sales@thestore.example')
        );
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->haveInRepository(
            (new EmailTemplate())
                ->setCode('invoice_customer')
                ->setModule('Invoice (Customer)')
                ->setSentTo('Customer')
                ->setSubject('Invoice for Order #{{ order.orderNumber|default(\'\') }}')
                ->setBody("{% extends 'emails/layout.html.twig' %}\n{% block body %}<p>Invoice attached.</p>{% endblock %}")
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $token = $I->grabAttributeFrom(
            '//form[.//input[@name="type" and @value="customer"]]//input[@name="_token"]',
            'value'
        );

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/invoice/send/' . $invoice->getId(), ['_token' => $token, 'type' => 'customer']);

        $I->seeEmailIsSent();
        $from = $I->grabLastSentEmail()->getFrom()[0]->getAddress();
        $I->assertSame('no-reply@wholesale.example', $from);
        $I->assertNotSame('sales@thestore.example', $from);
        $I->assertStringNotContainsString('example.test', $from);
    }

    public function cloningAnOrderKeepsItsPaymentMethodAndTerm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);
        $originalNumber = $order->getOrderNumber();

        // Clone is an ORDER action, so its button is on the order's own document page rather than
        // on the invoice's (#539 stage 6).
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/order/document/' . $order->getId());
        $token = $I->grabAttributeFrom('form[action$="/admin/order/clone/' . $order->getId() . '"] input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/order/clone/' . $order->getId(), ['_token' => $token]);

        /** @var list<SalesOrder> $clones */
        $clones = $I->grabEntitiesFromRepository(SalesOrder::class, ['paymentMethod' => 'Pay Upon Delivery']);
        $clone = null;
        foreach ($clones as $candidate) {
            if ($candidate->getOrderNumber() !== $originalNumber) {
                $clone = $candidate;
            }
        }

        $I->assertNotNull($clone, 'The clone should exist and should have kept the payment method.');
        $I->assertSame('Pay Upon Delivery', $clone->getPaymentMethod());
        $I->assertSame('Net 15', $clone->getPaymentTerm());
    }

    /**
     * The seller contact block on all four documents used to read
     * app_setting('company_phone', order.company.phoneNumber) — a default whose fallback is the
     * *buyer*. With no company_phone/sales_email configured, the "from" block printed the
     * customer's own phone number and email address as if they were ours, three lines below a
     * comment saying "never fall back to some other company's name".
     */
    public function theSellerBlockNeverFallsBackToTheBuyersContactDetails(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $buyerPhone = '+1 604 555 7788';
        $buyerEmail = 'do-not-print-me@buyer.example';

        $company = (new Company())
            ->setName('Seller Fallback Test Co')
            ->setCode('SELLFB-' . uniqid())
            ->setPhoneNumber($buyerPhone)
            ->setPrimaryEmail($buyerEmail);
        $I->haveInRepository($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SELLFB-' . uniqid())
            ->setTotal('10.00');
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $entityManagerForInvoice = $I->grabService(EntityManagerInterface::class);
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber('SELLFB-INV-' . uniqid())
            ->setDocumentDate('2026-08-20')
            ->setTotal('10.00');
        $order->addInvoice($invoice);
        $entityManagerForInvoice->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $entityManagerForInvoice->flush();

        // Blank out every seller contact setting the template consults. sales_email and
        // company_phone go through the form; app_email is not on that screen, so it is cleared
        // directly. Another test in this suite writes these keys, and the suite shares one
        // database connection across the run.
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/settings/company-information');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/settings/company-information', [
            '_token' => $token,
            'company_phone' => '',
            'sales_email' => '',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $appEmail = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'app_email']);
        if ($appEmail instanceof AppSetting) {
            $appEmail->setSettingValue(null);
            $entityManager->flush();
        }
        $I->grabService(AppSettings::class)->clearCache();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();

        // .inv-meta-col is the seller header block only; the buyer's own details legitimately
        // appear further down under Bill To / Ship To.
        $I->dontSee($buyerPhone, '.inv-meta-col');
        $I->dontSee($buyerEmail, '.inv-meta-col');
    }

    public function companyInformationScreenSavesTheSellerIdentityGstNumberAndPaymentNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/settings/company-information');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/settings/company-information', [
            '_token' => $token,
            'company_name' => 'Configured Seller Ltd.',
            'company_address' => '18 Wharf Street',
            'company_city' => 'Victoria',
            'company_state' => 'BC',
            'company_postal_code' => 'V8W 1T3',
            'company_country' => 'Canada',
            'company_phone' => '+1 250 555 0199',
            'sales_email' => 'sales@configured-seller.example',
            'website_url' => 'https://configured-seller.example',
            'company_gst_number' => '12345 6789 RT0001',
            'invoice_payment_note' => 'Pay by e-transfer to ar@configured-seller.example.',
        ]);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Configured Seller Ltd.');
        $I->see('12345 6789 RT0001');
        $I->see('Pay by e-transfer to ar@configured-seller.example.');
    }

    /**
     * The invoice_footer_note AppSetting (configured via /admin/settings/{id}/update) was never
     * referenced by invoice.html.twig, so it silently never rendered on the invoice page/PDF
     * (the same template covers both, plus browser "print").
     */
    public function invoiceShowsTheConfiguredFooterNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        [$order, $invoice] = $this->makeInvoicedOrder($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $setting = $em->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'invoice_footer_note']);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey('invoice_footer_note')->setName('Invoice Footer Note');
            $em->persist($setting);
        }
        $setting->setSettingValue('Goods sold are not returnable after 30 days.');
        $em->flush();
        $I->grabService(AppSettings::class)->clearCache();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Goods sold are not returnable after 30 days.');
    }
}
