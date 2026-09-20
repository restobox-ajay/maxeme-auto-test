<?php

declare(strict_types=1);

namespace CustomFieldExampleBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class CustomFieldExampleBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Custom Field Example';
    }

    public function getType(): string
    {
        return 'Custom Field';
    }

    public function getSource(): string
    {
        return 'CustomFieldExampleBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/CustomFieldExampleBundle/README.md';
    }
}
