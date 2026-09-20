<?php

declare(strict_types=1);

namespace WooCommerceBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceProductMapping;

/**
 * The outbound half of the connector (#739): pushes an Available-to-sell quantity to WooCommerce's
 * own REST API v3, the same endpoints the inbound side's payload shape was confirmed against
 * (`class-wc-rest-orders-v2-controller.php` et al.) — here, `WC_REST_Products_Controller` and
 * `WC_REST_Product_Variations_Controller`, both of which accept `PUT /products/{id}` with a JSON
 * body to update an existing resource; a variation is namespaced under its parent product.
 *
 * Authenticated with HTTP Basic Auth over the connection's own consumer key/secret — the standard
 * WooCommerce REST API scheme for a server-to-server integration over HTTPS (the OAuth1.0a query
 * parameter scheme exists only for a plain-HTTP store, which is not a case this app needs to
 * support). Used by both the Push Queue worker and Bulk Resync, so there is exactly one place that
 * knows how to talk to Woo's write API rather than each caller building its own request.
 */
final class WooCommerceRestClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Sets the Available-to-sell quantity Woo shows for this mapping's product/variation.
     * `manage_stock: true` is sent alongside it because a product Woo isn't managing stock for
     * ignores `stock_quantity` entirely — pushing a number with no effect would look like success.
     *
     * @throws \RuntimeException on anything other than a successful response — a non-2xx status, a
     *     network failure, or a mapping with no known Woo product id yet (pushTargetId() is 0).
     */
    public function updateStock(WooCommerceConnection $connection, WooCommerceProductMapping $mapping, int $quantity): void
    {
        $targetId = $mapping->pushTargetId();
        if ($targetId <= 0) {
            throw new \RuntimeException(sprintf(
                'Mapping %s has no known WooCommerce product id to push to.',
                $mapping->getWooSku(),
            ));
        }

        $path = $mapping->isVariation()
            ? sprintf('/wp-json/wc/v3/products/%d/variations/%d', $mapping->getWooProductId(), $mapping->getWooVariationId())
            : sprintf('/wp-json/wc/v3/products/%d', $targetId);

        try {
            $response = $this->httpClient->request('PUT', rtrim($connection->getStoreUrl(), '/') . $path, [
                'auth_basic' => [$connection->getConsumerKey(), $connection->getConsumerSecret()],
                'json' => ['stock_quantity' => $quantity, 'manage_stock' => true],
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf(
                    'WooCommerce rejected the stock update for product %d (HTTP %d): %s',
                    $targetId,
                    $status,
                    substr($response->getContent(false), 0, 500),
                ));
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Could not reach %s to push stock for product %d: %s',
                $connection->getStoreUrl(),
                $targetId,
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
