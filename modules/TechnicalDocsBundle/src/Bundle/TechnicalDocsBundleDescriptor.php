<?php

declare(strict_types=1);

namespace TechnicalDocsBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class TechnicalDocsBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Technical Docs';
    }

    public function getType(): string
    {
        return 'Documentation';
    }

    public function getSource(): string
    {
        return 'TechnicalDocsBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/TechnicalDocsBundle/README.md';
    }
}
