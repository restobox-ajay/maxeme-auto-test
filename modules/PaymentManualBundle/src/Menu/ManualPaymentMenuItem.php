<?php

declare(strict_types=1);

namespace PaymentManualBundle\Menu;

use App\Contract\Payment\PaymentMenuItemInterface;

final class ManualPaymentMenuItem implements PaymentMenuItemInterface
{
    public function getLabel(): string { return 'Manual Payment Methods'; }
    public function getRoute(): string { return 'admin_bundle_payment_manual_index'; }
}
