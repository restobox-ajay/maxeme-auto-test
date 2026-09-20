<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Tests\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use WooCommerceBundle\Controller\Webhook\WooCommerceWebhookController;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceOrderImport;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Repository\WooCommerceOrderImportRepository;
use WooCommerceBundle\Repository\WooCommerceProductMappingRepository;
use WooCommerceBundle\Service\WooCommerceOrderImportService;

/**
 * The webhook is an unauthenticated, internet-facing endpoint whose only defence is the
 * X-WC-Webhook-Signature check — same reasoning and same direct-controller-invocation shape as
 * StripeWebhookControllerTest, and for the same reason: PHPBrowser posts form fields, not a raw
 * signed JSON body, so this drives the controller directly with a hand-built Request instead.
 *
 * Payload shapes here match WooCommerce's REST API v3 order object (confirmed against
 * woocommerce/woocommerce's own controller source, not assumed): billing/shipping objects,
 * line_items with product_id/variation_id/sku/quantity/price/total, and the
 * X-WC-Webhook-Signature = base64(hmac_sha256(raw body, secret)) scheme WC_Webhook computes.
 */
final class WooCommerceWebhookControllerTest extends DoctrineIntegrationTestCase
{
    private const SECRET = 'whsec_test_secret_for_unit_tests';

    private WooCommerceConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = (new WooCommerceConnection())
            ->setName('Main Store')
            ->setSlug('main-store')
            ->setStoreUrl('https://store.example.com')
            ->setConsumerKey('ck_1')
            ->setConsumerSecret('cs_1')
            ->setWebhookSecret(self::SECRET)
            ->setActive(true);
        $this->em->persist($this->connection);
        $this->em->flush();
    }

    private function controller(): WooCommerceWebhookController
    {
        return new WooCommerceWebhookController(
            self::getContainer()->get(BundleStatusRepository::class),
            self::getContainer()->get(WooCommerceConnectionRepository::class),
            self::getContainer()->get(WooCommerceOrderImportService::class),
            new NullLogger(),
        );
    }

    private function sign(string $payload, string $secret = self::SECRET): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }

    private function post(string $slug, string $payload, ?string $signature): Response
    {
        $request = Request::create(
            '/webhook/woocommerce/' . $slug,
            'POST',
            [],
            [],
            [],
            $signature !== null ? ['HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature] : [],
            $payload,
        );

        return ($this->controller())($slug, $request, $this->em);
    }

    /** @param list<array<string, mixed>> $lineItems */
    private function orderPayload(int $wooOrderId, array $lineItems, array $overrides = []): string
    {
        return json_encode(array_merge([
            'id' => $wooOrderId,
            'number' => (string) $wooOrderId,
            'status' => 'processing',
            'currency' => 'CAD',
            'total' => '110.00',
            'total_tax' => '10.00',
            'payment_method_title' => 'Credit Card (Stripe)',
            'billing' => [
                'first_name' => 'Jamie',
                'last_name' => 'Rivera',
                'company' => 'Rivera Hardware',
                'email' => 'jamie@riverahardware.example',
                'phone' => '555-0100',
                'address_1' => '12 Main St',
                'city' => 'Vancouver',
                'state' => 'BC',
                'postcode' => 'V6B 1A1',
                'country' => 'CA',
            ],
            'shipping' => [
                'first_name' => 'Jamie',
                'last_name' => 'Rivera',
                'address_1' => '12 Main St',
                'city' => 'Vancouver',
                'state' => 'BC',
                'postcode' => 'V6B 1A1',
                'country' => 'CA',
            ],
            'line_items' => $lineItems,
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    public function testCorrectlySignedNewOrderCreatesOrderInvoiceAndCompany(): void
    {
        $payload = $this->orderPayload(5001, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 2, 'price' => 50, 'subtotal' => '100.00', 'total' => '100.00',
        ]]);

        $response = $this->post('main-store', $payload, $this->sign($payload));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['imported']);

        $import = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5001);
        self::assertInstanceOf(WooCommerceOrderImport::class, $import);
        self::assertSame(WooCommerceOrderImport::STATUS_IMPORTED, $import->getStatus());
        self::assertNotNull($import->getSalesOrder());
        self::assertNotNull($import->getInvoice());
        self::assertSame('WooCommerce', $import->getSalesOrder()->getSource());
        self::assertSame('100.00', $import->getSalesOrder()->getSubtotal());
        self::assertSame('110.00', $import->getInvoice()->getTotal());

        $company = $import->getCompany();
        self::assertInstanceOf(Company::class, $company);
        self::assertSame('Rivera Hardware', $company->getName());

        $product = self::getContainer()->get(WooCommerceProductMappingRepository::class)
            ->findOneForConnectionAndSku($this->connection, 'WIDGET-1');
        self::assertNotNull($product);
        self::assertTrue($product->isFlagged(), 'auto-created product must be flagged for review');
        self::assertTrue($product->getProduct()->isPrivate());
        self::assertSame(900, $product->getWooProductId());
        self::assertNull($product->getWooVariationId());
        self::assertSame(900, $product->pushTargetId());
    }

    public function testAnInvalidSignatureIsRejectedAndNothingIsCreated(): void
    {
        $payload = $this->orderPayload(5002, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]]);

        $response = $this->post('main-store', $payload, 'not-a-real-signature');

        self::assertSame(400, $response->getStatusCode());
        self::assertNull(self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5002));
    }

    public function testAMissingSignatureIsRejected(): void
    {
        $payload = $this->orderPayload(5003, []);

        self::assertSame(400, $this->post('main-store', $payload, null)->getStatusCode());
    }

    public function testTheWebhookPingBodyIsAcknowledgedAndIgnored(): void
    {
        $ping = 'webhook_id=42';
        $response = $this->post('main-store', $ping, $this->sign($ping));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('ping', $body['ignored']);
    }

    /** A redelivered webhook for the same Woo order must not mint a second order. */
    public function testARedeliveredWebhookIsIdempotent(): void
    {
        $payload = $this->orderPayload(5004, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]]);
        $signature = $this->sign($payload);

        $this->post('main-store', $payload, $signature);
        $this->post('main-store', $payload, $signature);

        $orders = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(\App\Entity\SalesOrder::class)
            ->findBy(['source' => 'WooCommerce']);
        self::assertCount(1, $orders, 'a redelivered webhook must not create a second order');
    }

    /** The same SKU on a second order resolves via the mapping, not a second product. */
    public function testARepeatSkuOnASecondOrderReusesTheMappedProduct(): void
    {
        $first = $this->orderPayload(5005, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]]);
        $this->post('main-store', $first, $this->sign($first));

        $second = $this->orderPayload(5006, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 3, 'price' => 50, 'subtotal' => '150.00', 'total' => '150.00',
        ]]);
        $this->post('main-store', $second, $this->sign($second));

        $products = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(ProductCore::class)
            ->findBy(['sku' => 'WIDGET-1']);
        self::assertCount(1, $products, 'a repeat SKU must resolve to the same product, not mint a second one');
    }

    /** No SKU at all: keyed by Woo's own product_id so a repeat order still reuses the product. */
    public function testALineWithNoSkuIsKeyedByWooProductIdAndReusedOnRepeat(): void
    {
        $first = $this->orderPayload(5007, [[
            'id' => 1, 'name' => 'Unlabelled Widget', 'product_id' => 777, 'variation_id' => 0,
            'sku' => '', 'quantity' => 1, 'price' => 20, 'subtotal' => '20.00', 'total' => '20.00',
        ]]);
        $this->post('main-store', $first, $this->sign($first));

        $second = $this->orderPayload(5008, [[
            'id' => 1, 'name' => 'Unlabelled Widget', 'product_id' => 777, 'variation_id' => 0,
            'sku' => '', 'quantity' => 2, 'price' => 20, 'subtotal' => '40.00', 'total' => '40.00',
        ]]);
        $this->post('main-store', $second, $this->sign($second));

        $products = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(ProductCore::class)
            ->findBy(['sku' => 'main-store-wp777']);
        self::assertCount(1, $products, 'both orders reference the same Woo product_id and must resolve to one product');
    }

    /** An existing catalogue product with the same SKU is matched outright, not shadowed by a new one. */
    public function testASkuMatchingAnExistingProductIsUsedInsteadOfAutoCreating(): void
    {
        $existing = (new ProductCore())->setSku('EXISTING-1')->setName('Existing Catalogue Item');
        $this->em->persist($existing);
        $this->em->flush();

        $payload = $this->orderPayload(5009, [[
            'id' => 1, 'name' => 'Whatever Woo Calls It', 'product_id' => 42, 'variation_id' => 0,
            'sku' => 'EXISTING-1', 'quantity' => 1, 'price' => 15, 'subtotal' => '15.00', 'total' => '15.00',
        ]]);
        $this->post('main-store', $payload, $this->sign($payload));

        $mapping = self::getContainer()->get(WooCommerceProductMappingRepository::class)
            ->findOneForConnectionAndSku($this->connection, 'EXISTING-1');
        self::assertNotNull($mapping);
        self::assertFalse($mapping->isFlagged(), 'a SKU that matched an existing product outright is not flagged');
        self::assertSame($existing->getId(), $mapping->getProduct()->getId());

        $products = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(ProductCore::class)
            ->findBy(['sku' => 'EXISTING-1']);
        self::assertCount(1, $products);
    }

    public function testADeactivatedConnectionsEndpointRefusesTheRequest(): void
    {
        $this->connection->setActive(false);
        $this->em->flush();

        $payload = $this->orderPayload(5010, []);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->post('main-store', $payload, $this->sign($payload));
    }

    /** A declined/failed payment must not be invoiced — Woo hasn't actually collected the money. */
    public function testAFailedOrderIsSkippedNotInvoiced(): void
    {
        $payload = $this->orderPayload(5011, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'failed']);

        $response = $this->post('main-store', $payload, $this->sign($payload));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertFalse($body['imported']);
        self::assertSame(WooCommerceOrderImport::STATUS_SKIPPED, $body['status']);

        $import = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5011);
        self::assertInstanceOf(WooCommerceOrderImport::class, $import);
        self::assertTrue($import->isSkipped());
        self::assertNull($import->getSalesOrder());
        self::assertNull($import->getInvoice());
        self::assertNull($import->getCompany());
        self::assertNotNull($import->getErrorMessage());

        self::assertCount(0, self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(\App\Entity\SalesOrder::class)->findBy(['source' => 'WooCommerce']));
    }

    /** @return list<array{string}> */
    public static function nonInvoiceableStatusProvider(): array
    {
        return [
            ['pending'], ['on-hold'], ['cancelled'], ['refunded'], ['trash'], ['checkout-draft'],
        ];
    }

    #[DataProvider('nonInvoiceableStatusProvider')]
    public function testEveryNonInvoiceableStatusIsSkipped(string $status): void
    {
        $wooOrderId = 5100 + crc32($status) % 1000;

        $payload = $this->orderPayload($wooOrderId, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => $status]);

        $this->post('main-store', $payload, $this->sign($payload));

        $import = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, $wooOrderId);
        self::assertInstanceOf(WooCommerceOrderImport::class, $import);
        self::assertTrue($import->isSkipped(), sprintf('status "%s" must be skipped, not invoiced', $status));
    }

    /** A redelivery that now carries an invoiceable status converts a previously-Skipped row instead of staying stuck. */
    public function testARedeliveryThatBecomesInvoiceableConvertsTheSkippedRow(): void
    {
        $pending = $this->orderPayload(5012, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'pending']);
        $this->post('main-store', $pending, $this->sign($pending));

        $stillPending = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5012);
        self::assertTrue($stillPending->isSkipped());

        $processing = $this->orderPayload(5012, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'processing']);
        $response = $this->post('main-store', $processing, $this->sign($processing));

        self::assertTrue(json_decode((string) $response->getContent(), true)['imported']);

        $converted = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5012);
        self::assertSame(WooCommerceOrderImport::STATUS_IMPORTED, $converted->getStatus());
        self::assertNotNull($converted->getSalesOrder());
        self::assertNotNull($converted->getInvoice());
        self::assertSame($stillPending->getId(), $converted->getId(), 'the same row is updated in place, not duplicated');
    }

    /**
     * An already-Imported row's own status is never rewritten by a later delivery — the sale
     * genuinely happened. A refund is reflected by a credit note bolted onto the row instead (see
     * the tests below), never by un-invoicing the original order.
     */
    public function testAnAlreadyImportedRowsStatusIsNeverRewritten(): void
    {
        $payload = $this->orderPayload(5013, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'processing']);
        $this->post('main-store', $payload, $this->sign($payload));

        $refunded = $this->orderPayload(5013, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'refunded']);
        $this->post('main-store', $refunded, $this->sign($refunded));

        $import = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5013);
        self::assertSame(WooCommerceOrderImport::STATUS_IMPORTED, $import->getStatus(), 'an imported order is never un-invoiced by a later status change here');
    }

    /** A full refund on an already-imported order raises, issues, and refunds a real credit note against the invoice. */
    public function testAFullRefundOnAnImportedOrderRaisesAndRefundsACreditNote(): void
    {
        $paid = $this->orderPayload(5014, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 2, 'price' => 50, 'subtotal' => '100.00', 'total' => '100.00',
        ]], ['status' => 'processing', 'total' => '110.00', 'total_tax' => '10.00']);
        $this->post('main-store', $paid, $this->sign($paid));

        $imports = self::getContainer()->get(WooCommerceOrderImportRepository::class);
        $import = $imports->findOneForConnectionAndWooOrderId($this->connection, 5014);
        self::assertNull($import->getCreditMemo());
        $invoice = $import->getInvoice();

        $refunded = $this->orderPayload(5014, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 2, 'price' => 50, 'subtotal' => '100.00', 'total' => '100.00',
        ]], ['status' => 'refunded', 'total' => '110.00', 'total_tax' => '10.00']);
        $this->post('main-store', $refunded, $this->sign($refunded));

        $import = $imports->findOneForConnectionAndWooOrderId($this->connection, 5014);
        self::assertSame(WooCommerceOrderImport::STATUS_IMPORTED, $import->getStatus());

        $memo = $import->getCreditMemo();
        self::assertInstanceOf(CreditMemo::class, $memo);
        self::assertSame($invoice->getId(), $memo->getInvoice()->getId());
        self::assertSame($import->getCompany()->getId(), $memo->getCompany()->getId());
        self::assertSame('Closed', $memo->getStatus(), 'issued then immediately refunded for its whole total, so the balance is spent and settle() derives Closed');
        self::assertSame('110.00', $memo->getTotal());
        self::assertSame('0.00', $memo->getBalance(), 'the whole note was refunded, so nothing is left to apply elsewhere');
        self::assertCount(1, $memo->getRefunds());
        self::assertSame('110.00', $memo->getAmountRefunded());
        self::assertCount(1, $memo->getLines());
        self::assertSame('2.00', $memo->getLines()->first()->getQuantity());
    }

    /** A redelivered refund (same order, same status) must not raise a second credit note. */
    public function testARedeliveredRefundDoesNotDoubleCreditTheInvoice(): void
    {
        $paid = $this->orderPayload(5015, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'processing']);
        $this->post('main-store', $paid, $this->sign($paid));

        $refunded = $this->orderPayload(5015, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'refunded']);
        $this->post('main-store', $refunded, $this->sign($refunded));
        $this->post('main-store', $refunded, $this->sign($refunded));

        $imports = self::getContainer()->get(WooCommerceOrderImportRepository::class);
        $import = $imports->findOneForConnectionAndWooOrderId($this->connection, 5015);
        $memoId = $import->getCreditMemo()->getId();

        $memos = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(CreditMemo::class)
            ->findBy(['invoice' => $import->getInvoice()]);
        self::assertCount(1, $memos, 'a redelivered refund must not raise a second credit note');
        self::assertSame($memoId, $memos[0]->getId());
    }

    /**
     * A partial refund leaves the Woo order's own status unchanged (still processing/completed), so
     * this connector — which only reacts to the order's own status reaching "refunded" — does not
     * raise a credit note for it. This is the documented, known gap in
     * WooCommerceOrderImportService::handleUpdateToImportedOrder(), pinned here so it stays visible
     * rather than silently assumed to work.
     */
    public function testAPartialRefundThatLeavesTheOrderProcessingRaisesNoCreditNoteYet(): void
    {
        $paid = $this->orderPayload(5016, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 2, 'price' => 50, 'subtotal' => '100.00', 'total' => '100.00',
        ]], ['status' => 'processing']);
        $this->post('main-store', $paid, $this->sign($paid));

        // Same status as before — a partial refund does not flip Woo's own order status.
        $stillProcessing = $this->orderPayload(5016, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 2, 'price' => 50, 'subtotal' => '100.00', 'total' => '100.00',
        ]], [
            'status' => 'processing',
            'refunds' => [['id' => 501, 'reason' => 'One unit damaged', 'total' => '-50.00', 'total_tax' => '0.00']],
        ]);
        $this->post('main-store', $stillProcessing, $this->sign($stillProcessing));

        $import = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5016);
        self::assertNull($import->getCreditMemo());
    }

    /** A Skipped row (never invoiced) has nothing to credit and must not be touched by this path. */
    public function testASkippedOrderThatArrivesMarkedRefundedIsNotCredited(): void
    {
        $payload = $this->orderPayload(5017, [[
            'id' => 1, 'name' => 'Widget', 'product_id' => 900, 'variation_id' => 0,
            'sku' => 'WIDGET-1', 'quantity' => 1, 'price' => 50, 'subtotal' => '50.00', 'total' => '50.00',
        ]], ['status' => 'refunded']);
        $this->post('main-store', $payload, $this->sign($payload));

        $import = self::getContainer()->get(WooCommerceOrderImportRepository::class)
            ->findOneForConnectionAndWooOrderId($this->connection, 5017);
        self::assertTrue($import->isSkipped());
        self::assertNull($import->getInvoice());
        self::assertNull($import->getCreditMemo());
    }
}
