<?php

declare(strict_types=1);

namespace App\Twig;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Repository\BundleStatusRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class InjectionPointExtension extends AbstractExtension
{
    /** @param iterable<InjectionPointProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('injection_point', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    /** @param array<string, mixed> $context */
    public function render(string $point, array $context = []): string
    {
        $matching = [];
        foreach ($this->providers as $provider) {
            if ($provider->getPoint() === $point && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $matching[] = $provider;
            }
        }

        usort($matching, fn (InjectionPointProviderInterface $a, InjectionPointProviderInterface $b) => $a->getPriority() <=> $b->getPriority());

        $html = '';
        foreach ($matching as $provider) {
            $html .= $provider->render($context);
        }

        return $html;
    }
}
