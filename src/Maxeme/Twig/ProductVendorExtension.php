<?php

declare(strict_types=1);

namespace App\Maxeme\Twig;

use App\Entity\ProductCore;
use App\Maxeme\Service\ProductVendors;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The product's Vendor on core's product screens (mockup):
 *  - {{ product_vendor_options(productId) }}: the form's choices, and which one is chosen;
 *  - {{ product_vendor_names(products) }}: product id => vendor name for a page of the product list.
 */
final class ProductVendorExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProductVendors $vendors,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('product_vendor_options', $this->options(...)),
            new TwigFunction('product_vendor_names', $this->names(...)),
        ];
    }

    /** @return array{options: list<array{id: int, name: string, active: bool}>, selected: ?int} */
    public function options(int|string|null $productId): array
    {
        $product = (int) $productId > 0 ? $this->entityManager->find(ProductCore::class, (int) $productId) : null;

        return [
            'options' => $this->vendors->options($product),
            'selected' => $product !== null ? $this->vendors->vendorOf($product)?->getId() : null,
        ];
    }

    /**
     * @param iterable<array{id: int|string}> $products the list's rows
     *
     * @return array<int, string>
     */
    public function names(iterable $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = $product['id'] ?? 0;
        }

        return $this->vendors->namesFor($ids);
    }
}
