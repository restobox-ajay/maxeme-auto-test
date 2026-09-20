<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProductCore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes which companies a product is private to — the one real implementation, so every caller
 * that needs to set a product's private-company list (the admin product form, and any importer
 * that mints a private product of its own) goes through the same pivot-table write rather than
 * each hand-rolling it.
 *
 * ## Why this bypasses the ORM collection
 *
 * Doctrine's ManyToMany collection persister does not reliably emit the company_product_access
 * insert/delete statements for `ProductCore::$privateCompanies` on flush() (confirmed via direct
 * instrumentation: the collection is correctly dirty/initialized and the entity is managed, yet no
 * SQL is generated) — so the pivot table is synced directly instead of relying on
 * `$product->getPrivateCompanies()->add()`/`clear()`.
 *
 * ## Why this refreshes $product afterwards
 *
 * A brand-new entity's collections are marked initialized-empty by Doctrine at persist time — there
 * is nothing to lazy-load for a row that didn't exist a moment ago — so `$product->isPrivate()`
 * called later in the SAME request would still read that stale empty collection even though the raw
 * write above landed correctly; only a later request, with a fresh EntityManager, would see it. A
 * caller minting a product and syncing its privacy in one request (WooCommerceProductResolver is
 * exactly this) needs `isPrivate()` to be right immediately, so this refreshes the entity from the
 * database it was just written to, discarding nothing unflushed since the caller has already
 * persisted every other field by the time it calls this.
 */
final class ProductPrivacyLinker
{
    /** @param list<int> $companyIds */
    public function sync(ProductCore $product, array $companyIds, EntityManagerInterface $entityManager): void
    {
        $productId = $product->getId();
        if ($productId === null) {
            return;
        }

        $connection = $entityManager->getConnection();
        $connection->delete('company_product_access', ['product_id' => $productId]);
        foreach (array_unique($companyIds) as $companyId) {
            $connection->insert('company_product_access', ['product_id' => $productId, 'company_id' => $companyId]);
        }

        $entityManager->refresh($product);
    }
}
