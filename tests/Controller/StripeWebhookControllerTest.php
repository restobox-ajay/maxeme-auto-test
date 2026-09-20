<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Service\StripeOrderPaymentApplier;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PaymentStripeBundle\Controller\StripeWebhookController;
use PaymentStripeBundle\Service\StripeConfigProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webhook is an unauthenticated, internet-facing endpoint whose only defence is the
 * Stripe-Signature check — so these tests care most about what it REFUSES. Signatures are computed
 * here with the same HMAC scheme Stripe uses, so no Stripe account, network or browser is involved.
 *
 * "Nothing moved" is asserted on both documents since #539: payment releases the card INVOICE from
 * On Hold, and the order's status is derived from its invoices, so a refused event must leave the
 * invoice On Hold and the order exactly where it was.
 */
final class StripeWebhookControllerTest extends DoctrineIntegrationTestCase
{
    private const SECRET = 'whsec_test_secret_for_unit_tests';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Wholesale')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    private function setWebhookSecret(?string $secret): void
    {
        $setting = (new AppSetting())
            ->setSettingKey(StripeConfigProvider::KEY_WEBHOOK_SECRET_TEST)
            ->setName('Stripe Webhook Signing Secret (Test)')
            ->setSettingValue($secret);

        $this->em->persist($setting);
        $this->em->flush();

        self::getContainer()->get(AppSettings::class)->clearCache();
    }

    private function controller(): StripeWebhookController
    {
        return new StripeWebhookController(
            self::getContainer()->get(StripeConfigProvider::class),
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(StripeOrderPaymentApplier::class),
            new NullLogger(),
        );
    }

    /**
     * An approved order carrying the card invoice checkout really raises: issued, but On Hold until
     * the money lands.
     */
    private function order(string $total = '125.00'): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setSubtotal($total)
            ->setTotal($total);

        $order->addLine(
            (new SalesOrderLine())
                ->setName('Widget')->setSku('WIDGET-1')
                ->setQuantity('1.00')->setPrice($total)->setSubtotal($total),
        );

        $this->em->persist($order);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        self::getContainer()->get(OrderInvoicingService::class)
            ->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::AwaitingPayment);
        $this->em->flush();

        // Guards every "nothing moved" case from passing vacuously against an order that was never
        // live in the first place.
        self::assertTrue($order->getStatusEnum()?->isApprovedOrLater() ?? false);

        return $order;
    }

    private function invoiceOf(SalesOrder $order): Invoice
    {
        $invoice = $order->getInvoices()->first();
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    /** Neither document moved: the invoice is still awaiting payment and the order still says so. */
    private function assertNothingMoved(SalesOrder $order, Invoice $invoice, string $orderStatusBefore): void
    {
        $this->em->refresh($order);
        $this->em->refresh($invoice);

        self::assertSame('On Hold', $invoice->getStatus());
        self::assertSame($orderStatusBefore, $order->getStatus());
        self::assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());
        self::assertCount(0, $invoice->getApplications());
    }

    /** @param array<string, mixed> $intentOverrides */
    private function payload(SalesOrder $order, array $intentOverrides = [], string $type = 'payment_intent.succeeded'): string
    {
        return json_encode([
            'id' => 'evt_' . uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => 'pi_' . uniqid(),
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 12500,
                'amount_received' => 12500,
                'currency' => 'cad',
                'metadata' => [
                    'order_id' => (string) $order->getId(),
                    'company_id' => (string) $this->company->getId(),
                ],
            ], $intentOverrides)],
        ], JSON_THROW_ON_ERROR);
    }

    /** Reproduces Stripe's signature scheme: t=<unix>,v1=<hmac_sha256("<unix>.<payload>", secret)>. */
    private function sign(string $payload, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp . '.' . $payload, $secret));
    }

    private function post(string $payload, ?string $signature): Response
    {
        $request = Request::create(
            '/webhook/stripe',
            'POST',
            [],
            [],
            [],
            $signature !== null ? ['HTTP_STRIPE_SIGNATURE' => $signature] : [],
            $payload,
        );

        return ($this->controller())($request);
    }

    public function testCorrectlySignedEventMarksTheOrderPaid(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $payload = $this->payload($order);

        $response = $this->post($payload, $this->sign($payload));
        $this->em->refresh($order);
        $this->em->refresh($invoice);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(InvoicePaymentStatus::Paid, $invoice->getPaymentStatus());
        self::assertSame('Pending', $invoice->getStatus());
        // Derived from the invoice set rather than written by the webhook: fully invoiced, and that
        // invoice now paid.
        self::assertSame(SalesOrderStatus::Closed->value, $order->getStatus());
        self::assertCount(1, $invoice->getApplications());
    }

    public function testForgedSignatureIsRejectedAndChangesNothing(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order);

        // Correct scheme, wrong secret — i.e. an attacker who knows the format but not the key.
        $response = $this->post($payload, $this->sign($payload, 'whsec_not_the_real_secret'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testUnsignedRequestIsRejected(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();

        $response = $this->post($this->payload($order), null);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testPayloadTamperedAfterSigningIsRejected(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order);
        $signature = $this->sign($payload);

        // Same signature, body swapped for one claiming a different order.
        $tampered = $this->payload($this->order());

        $response = $this->post($tampered, $signature);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testStaleTimestampIsRejected(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order);

        // Outside Stripe's replay tolerance — a captured-and-replayed delivery.
        $response = $this->post($payload, $this->sign($payload, self::SECRET, time() - 86400));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testWithNoConfiguredSecretEverythingIsRefused(): void
    {
        $this->setWebhookSecret(null);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order);

        // Fails closed: without a secret the endpoint would otherwise be an unauthenticated
        // "mark this order paid" API. 500 also makes Stripe retry once configured.
        $response = $this->post($payload, $this->sign($payload));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testSignedEventWhoseAmountDoesNotMatchIsRefused(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order, ['amount_received' => 100, 'amount' => 100]);

        $response = $this->post($payload, $this->sign($payload));

        // Acknowledged so Stripe stops retrying something that can never succeed, but neither
        // document may move.
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testRedeliveryOfTheSameIntentDoesNotDoubleRecord(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $payload = $this->payload($order);

        // Stripe retries until it gets a 2xx, so the same intent arriving twice is routine.
        $this->post($payload, $this->sign($payload));
        $second = $this->post($payload, $this->sign($payload));
        $this->em->refresh($order);
        $this->em->refresh($invoice);

        self::assertSame(Response::HTTP_OK, $second->getStatusCode());
        self::assertCount(1, $invoice->getApplications());
        // The release happened once; a redelivery must not attempt On Hold -> Pending again.
        self::assertSame('Pending', $invoice->getStatus());
    }

    public function testUnrelatedEventTypesAreAcknowledgedAndIgnored(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order, [], 'payment_intent.payment_failed');

        $response = $this->post($payload, $this->sign($payload));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testEventWithoutOrderIdMetadataIsIgnored(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order, ['metadata' => ['company_id' => (string) $this->company->getId()]]);

        $response = $this->post($payload, $this->sign($payload));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testEventNamingAnUnknownOrderIsIgnored(): void
    {
        $this->setWebhookSecret(self::SECRET);
        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order, ['metadata' => ['order_id' => '999999', 'company_id' => (string) $this->company->getId()]]);

        $response = $this->post($payload, $this->sign($payload));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }

    public function testEventForAnotherCompanysOrderIsRefused(): void
    {
        $this->setWebhookSecret(self::SECRET);

        $otherCompany = (new Company())->setName('Rival Ltd')->setCode('RIVAL');
        $this->em->persist($otherCompany);
        $this->em->flush();

        $order = $this->order();
        $invoice = $this->invoiceOf($order);
        $status = $order->getStatus();
        $payload = $this->payload($order, ['metadata' => [
            'order_id' => (string) $order->getId(),
            'company_id' => (string) $otherCompany->getId(),
        ]]);

        $response = $this->post($payload, $this->sign($payload));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNothingMoved($order, $invoice, $status);
    }
}
