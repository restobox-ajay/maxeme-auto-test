<?php

declare(strict_types=1);

namespace TaxBCBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Entity\SalesOrder;
use TaxBCBundle\Tax\BCTaxCalculator;

final class BCTaxCustomerOrderDetailProvider implements InjectionPointProviderInterface
{
    public function __construct(private readonly BCTaxOrderSnapshotRenderer $renderer) {}

    public function getPoint(): string
    {
        return 'customer_order_detail_info';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return BCTaxCalculator::SOURCE;
    }

    public function render(array $context): string
    {
        $order = $context['order'] ?? null;

        return $order instanceof SalesOrder ? $this->renderer->renderCustomerDetailRow($order) : '';
    }
}
