<?php

declare(strict_types=1);

namespace InjectionPointExampleBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;

final class CartCheckoutMessageProvider implements InjectionPointProviderInterface
{
    public function getPoint(): string
    {
        return 'cart_before_checkout';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'InjectionPointExampleBundle';
    }

    public function render(array $context): string
    {
        return '<p class="injection-point-example-message">Example bundle content: your order will be reviewed before shipping.</p>';
    }
}
