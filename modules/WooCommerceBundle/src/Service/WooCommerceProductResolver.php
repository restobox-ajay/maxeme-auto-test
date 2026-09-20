<?php

declare(strict_types=1);

namespace WooCommerceBundle\Service;

use App\Entity\Company;
use App\Entity\ProductCore;
use App\Repository\ProductCoreRepository;
use App\Service\ProductPrivacyLinker;
use Doctrine\ORM\EntityManagerInterface;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceProductMapping;
use WooCommerceBundle\Repository\WooCommerceProductMappingRepository;

/**
 * "What internal product is this Woo line item?" (#739). One line item in, one ProductCore out,
 * with a WooCommerceProductMapping row written on every first resolution so a repeat order for the
 * same item resolves the same way without re-running the match/create logic below.
 *
 * ## Resolution order
 *
 * 1. An existing mapping for (connection, external key) — once mapped, always mapped. This is
 *    checked FIRST and short-circuits everything else, including a SKU match, so a product an admin
 *    later re-SKU'd or merged the mapping onto is not silently re-diverted back to a stale SKU match.
 * 2. A Woo SKU that names an existing product ANYWHERE in the catalogue — product_core.sku is a
 *    global identifier in this app (see BarcodeBundle\Barcode\ProductLookup's docblock: "matched
 *    first and matched forever"), so a real match here is not treated as a coincidence.
 * 3. Auto-create a private product, scoped to the ordering company, flagged for admin review.
 *
 * ## The external key, when Woo sends no SKU
 *
 * Woo's line_items always carry `product_id` (and `variation_id` for a variable product) even when
 * `sku` is blank — many stores never bother setting one. Deriving the mapping key from the ORDER
 * instead (e.g. order id + line number) would mint a fresh product on every single order for that
 * item, defeating the entire purpose of the mapping table. So the external key for a SKU-less line
 * is `wp{variation_id ?: product_id}` — Woo's own stable identifier for the catalogue item, not the
 * order carrying it. The mapping table is namespaced by connection_id, so that key only needs to be
 * unique within one connection; the auto-created product's own SKU is `{connection-slug}-{key}`
 * instead, because product_core.sku has no connection scope and two different Woo stores can each
 * have an unrelated product_id 777.
 */
final class WooCommerceProductResolver
{
    public const SYNC_SOURCE = 'woocommerce';

    public function __construct(
        private readonly WooCommerceProductMappingRepository $mappings,
        private readonly ProductCoreRepository $products,
        private readonly ProductPrivacyLinker $privacyLinker,
    ) {
    }

    /**
     * @param array{sku?: mixed, name?: mixed, product_id?: mixed, variation_id?: mixed, price?: mixed} $lineItem
     *     A WooCommerce order line_item, as delivered on the order webhook payload.
     */
    public function resolve(
        EntityManagerInterface $entityManager,
        WooCommerceConnection $connection,
        Company $company,
        array $lineItem,
    ): ProductCore {
        $wooSku = trim((string) ($lineItem['sku'] ?? ''));
        $externalKey = $wooSku !== '' ? $wooSku : $this->syntheticKey($lineItem);

        $mapping = $this->mappings->findOneForConnectionAndSku($connection, $externalKey);
        if ($mapping instanceof WooCommerceProductMapping) {
            return $mapping->getProduct();
        }

        if ($wooSku !== '') {
            $existing = $this->products->findBySku($wooSku);
            if ($existing instanceof ProductCore) {
                $this->recordMapping($entityManager, $connection, $externalKey, $existing, $lineItem, flagged: false);

                return $existing;
            }
        }

        // The mapping's own external key only needs to be unique WITHIN this connection (the
        // mapping table is namespaced by connection_id), but the auto-created product's SKU is
        // GLOBAL (product_core.sku has no connection scope) — two different Woo stores can each
        // have their own product_id 777 for two unrelated items, so the product's own SKU is
        // prefixed with this connection's slug while the mapping key is not.
        $productSku = $wooSku !== '' ? $wooSku : sprintf('%s-%s', $connection->getSlug(), $externalKey);
        $product = $this->createPrivateProduct($entityManager, $company, $productSku, $lineItem);
        $this->recordMapping($entityManager, $connection, $externalKey, $product, $lineItem, flagged: true);

        return $product;
    }

    /** @param array{product_id?: mixed, variation_id?: mixed} $lineItem */
    private function syntheticKey(array $lineItem): string
    {
        $variationId = (int) ($lineItem['variation_id'] ?? 0);
        $productId = (int) ($lineItem['product_id'] ?? 0);

        return sprintf('wp%d', $variationId > 0 ? $variationId : $productId);
    }

    /** @param array{name?: mixed, price?: mixed} $lineItem */
    private function createPrivateProduct(
        EntityManagerInterface $entityManager,
        Company $company,
        string $sku,
        array $lineItem,
    ): ProductCore {
        $name = trim((string) ($lineItem['name'] ?? '')) ?: $sku;
        $price = trim((string) ($lineItem['price'] ?? ''));

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($name)
            ->setSyncSource(self::SYNC_SOURCE)
            // 'G' (standard taxable), not the codebase's null-means-Exempt default
            // (TaxContext::mapTaxCode()): a brand-new, unreviewed item defaulting to Exempt would
            // silently undercharge tax on every future order of it, on any channel, until an admin
            // happens to notice — the safer wrong answer is the one that costs a refund, not the
            // one that costs a remittance.
            ->setSalesTaxCode('G')
            ->activate();

        if ($price !== '' && is_numeric($price)) {
            $product->setDefaultPrice(number_format((float) $price, 2, '.', ''));
        }

        $entityManager->persist($product);
        $entityManager->flush();

        // Needs the product's real id, which only exists after the flush above — the same
        // constraint ProductPrivacyLinker::sync() itself documents.
        if ($company->getId() !== null) {
            $this->privacyLinker->sync($product, [$company->getId()], $entityManager);
        }

        return $product;
    }

    /** @param array{product_id?: mixed, variation_id?: mixed} $lineItem */
    private function recordMapping(
        EntityManagerInterface $entityManager,
        WooCommerceConnection $connection,
        string $externalKey,
        ProductCore $product,
        array $lineItem,
        bool $flagged,
    ): void {
        $variationId = (int) ($lineItem['variation_id'] ?? 0);

        $mapping = (new WooCommerceProductMapping())
            ->setConnection($connection)
            ->setWooSku($externalKey)
            ->setProduct($product)
            ->setWooProductId((int) ($lineItem['product_id'] ?? 0))
            ->setWooVariationId($variationId > 0 ? $variationId : null)
            ->setFlagged($flagged);

        $entityManager->persist($mapping);
    }
}
