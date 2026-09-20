<?php

declare(strict_types=1);

namespace InjectionPointExampleBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class InjectionPointExampleBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Injection Point Example';
    }

    public function getType(): string
    {
        return 'Injection Point';
    }

    public function getSource(): string
    {
        return 'InjectionPointExampleBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/InjectionPointExampleBundle/README.md';
    }
}
