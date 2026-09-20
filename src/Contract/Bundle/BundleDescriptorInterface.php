<?php

declare(strict_types=1);

namespace App\Contract\Bundle;

interface BundleDescriptorInterface
{
    public function getName(): string;

    public function getType(): string;

    public function getSource(): string;

    public function getEditRoute(): ?string;

    public function getDocsUrl(): ?string;
}
