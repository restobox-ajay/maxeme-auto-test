<?php

declare(strict_types=1);

namespace NewsBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class NewsBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'News Scroller';
    }

    public function getType(): string
    {
        return 'Injection Point';
    }

    public function getSource(): string
    {
        return 'NewsBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_news_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/NewsBundle/README.md';
    }
}
