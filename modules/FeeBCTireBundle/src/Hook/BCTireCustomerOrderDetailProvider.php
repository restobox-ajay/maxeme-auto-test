<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Entity\SalesOrder;
use App\Service\AppSettings;
use FeeBCTireBundle\Fee\BCTireFeeCalculator;

final class BCTireCustomerOrderDetailProvider implements InjectionPointProviderInterface
{
    // Must match a key in BCTireFeeConfigController::DISPLAY_SETTING_LABELS.
    private const SETTING_KEY = 'fee_bc_tire_show_order_detail_customer';

    public function __construct(
        private readonly BCTireOrderSnapshotRenderer $renderer,
        private readonly AppSettings $appSettings,
    ) {}

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
        return BCTireFeeCalculator::SOURCE;
    }

    public function render(array $context): string
    {
        if ($this->appSettings->get(self::SETTING_KEY, '1') !== '1') {
            return '';
        }

        $order = $context['order'] ?? null;

        return $order instanceof SalesOrder ? $this->renderer->renderCustomerDetailRow($order) : '';
    }
}
