<?php

declare(strict_types=1);

namespace Number1GuestCoverPageBundle\TemplateOverride;

use App\Contract\Bundle\TemplateOverrideProviderInterface;
use Number1GuestCoverPageBundle\Service\GuestCoverPageTemplateStore;

final class CustomerLoginTemplateOverrideProvider implements TemplateOverrideProviderInterface
{
    public function __construct(
        private readonly GuestCoverPageTemplateStore $store,
    ) {}

    public function getPoint(): string
    {
        return 'customer_login';
    }

    public function getTemplate(): string
    {
        return '@Number1GuestCoverPage/login.html.twig';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'Number1GuestCoverPageBundle';
    }

    public function getTemplateSource(): ?string
    {
        return $this->store->getCustomSource();
    }
}
