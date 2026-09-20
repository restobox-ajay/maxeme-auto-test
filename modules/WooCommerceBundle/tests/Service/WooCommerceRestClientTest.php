<?php

declare(strict_types=1);

namespace WooCommerceBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Service\WooCommerceRestClient;

/**
 * A pure unit test — no DB, no container — because this class's entire job is shaping one HTTP
 * request correctly, and MockHttpClient/MockResponse (both shipped with symfony/http-client, the
 * component this class already depends on) let every case here be exercised deterministically,
 * including the two failure paths a real store would only produce under conditions this test
 * cannot reliably create on demand (an outage, an auth rejection).
 */
final class WooCommerceRestClientTest extends TestCase
{
    private function connection(): WooCommerceConnection
    {
        return (new WooCommerceConnection())
            ->setName('Main Store')
            ->setSlug('main-store')
            ->setStoreUrl('https://store.example.com/')
            ->setConsumerKey('ck_abc')
            ->setConsumerSecret('cs_xyz')
            ->setWebhookSecret('whsec')
            ->setActive(true);
    }

    public function testASimpleProductPutsToItsOwnEndpointWithBasicAuthAndStockPayload(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [$method, $url, $options];

            return new MockResponse('{"id":900}', ['http_code' => 200]);
        });

        $mapping = (new WooCommerceProductMapping())->setWooProductId(900);

        (new WooCommerceRestClient($httpClient))->updateStock($this->connection(), $mapping, 42);

        [$method, $url, $options] = $captured;
        self::assertSame('PUT', $method);
        self::assertSame('https://store.example.com/wp-json/wc/v3/products/900', $url);
        self::assertSame(
            'Authorization: Basic ' . base64_encode('ck_abc:cs_xyz'),
            $options['normalized_headers']['authorization'][0],
        );
        self::assertSame(['stock_quantity' => 42, 'manage_stock' => true], json_decode($options['body'], true));
    }

    public function testAVariationPutsToTheVariationEndpointUnderItsParent(): void
    {
        $captured = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$captured) {
            $captured = $url;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $mapping = (new WooCommerceProductMapping())->setWooProductId(900)->setWooVariationId(905);

        (new WooCommerceRestClient($httpClient))->updateStock($this->connection(), $mapping, 3);

        self::assertSame('https://store.example.com/wp-json/wc/v3/products/900/variations/905', $captured);
    }

    public function testANonTwoHundredResponseThrows(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"code":"woocommerce_rest_cannot_edit"}', ['http_code' => 403]));
        $mapping = (new WooCommerceProductMapping())->setWooProductId(900);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 403/');

        (new WooCommerceRestClient($httpClient))->updateStock($this->connection(), $mapping, 1);
    }

    public function testANetworkFailureThrows(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('', ['error' => 'Connection refused']));
        $mapping = (new WooCommerceProductMapping())->setWooProductId(900);

        $this->expectException(\RuntimeException::class);

        (new WooCommerceRestClient($httpClient))->updateStock($this->connection(), $mapping, 1);
    }

    public function testAMappingWithNoKnownWooProductIdRefusesToPush(): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            self::fail('No request should have been made.');
        });
        $mapping = new WooCommerceProductMapping();

        $this->expectException(\RuntimeException::class);

        (new WooCommerceRestClient($httpClient))->updateStock($this->connection(), $mapping, 1);
    }
}
