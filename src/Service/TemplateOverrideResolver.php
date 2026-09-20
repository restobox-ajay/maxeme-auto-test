<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Bundle\TemplateOverrideProviderInterface;
use App\Repository\BundleStatusRepository;

final class TemplateOverrideResolver
{
    /** @param iterable<TemplateOverrideProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    public function resolve(string $point, string $default): string
    {
        return $this->winner($point)?->getTemplate() ?? $default;
    }

    /** Raw Twig source the winning provider wants rendered instead of its getTemplate() file, or null. */
    public function resolveSource(string $point): ?string
    {
        return $this->winner($point)?->getTemplateSource();
    }

    private function winner(string $point): ?TemplateOverrideProviderInterface
    {
        $matching = [];
        foreach ($this->providers as $provider) {
            if ($provider->getPoint() === $point && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $matching[] = $provider;
            }
        }

        if ($matching === []) {
            return null;
        }

        usort($matching, fn (TemplateOverrideProviderInterface $a, TemplateOverrideProviderInterface $b) => $b->getPriority() <=> $a->getPriority());

        return $matching[0];
    }
}
