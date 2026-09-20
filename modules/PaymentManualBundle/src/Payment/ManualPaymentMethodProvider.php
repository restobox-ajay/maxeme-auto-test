<?php

declare(strict_types=1);

namespace PaymentManualBundle\Payment;

use App\Contract\Payment\PaymentMethodInterface;
use App\Contract\Payment\PaymentMethodProviderInterface;
use App\Entity\PaymentMethod;
use App\Repository\PaymentMethodRepository;

final class ManualPaymentMethodProvider implements PaymentMethodProviderInterface
{
    public function __construct(private readonly PaymentMethodRepository $paymentMethodRepo) {}

    /** @return PaymentMethodInterface[] */
    public function getPaymentMethods(): array
    {
        return array_map(
            static fn (PaymentMethod $pm): PaymentMethodInterface => new ManualPaymentMethod($pm->getSlug(), $pm->getName()),
            $this->paymentMethodRepo->findBySource(ManualPaymentMethod::SOURCE),
        );
    }
}
