<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Hook;

use App\Contract\Hook\InjectionPointProviderInterface;
use CustomHeaderFooterBundle\Service\CustomHeaderFooterStore;

final class CustomFooterProvider implements InjectionPointProviderInterface
{
    public function __construct(private readonly CustomHeaderFooterStore $store) {}

    public function getPoint(): string
    {
        return 'customer_body_end';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'CustomHeaderFooterBundle';
    }

    public function render(array $context): string
    {
        return $this->store->getFooterHtml();
    }
}
