<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;

/**
 * One more contributor to the Product Inventory Hub's "Units sold" figure, for units billed on a
 * document core's own `InvoiceLine` query does not see (an app's own invoices — Maxeme's repair
 * order invoices). Same seam as {@see ProductActivityProviderInterface}: tagged
 * `app.product_sales_provider`, collected by {@see \App\Service\Inventory\ProductSalesResolver},
 * and gated on getSource() through App\Repository\BundleStatusRepository.
 */
interface ProductSalesProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /** Units of this product sold since $since (net of any taken back), as a plain number. */
    public function unitsSold(ProductCore $product, \DateTimeImmutable $since): float;
}
