<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Bundle\TemplateOverrideProviderInterface;
use App\Repository\BundleStatusRepository;
use App\Service\TemplateOverrideResolver;
use PHPUnit\Framework\TestCase;

final class TemplateOverrideResolverTest extends TestCase
{
    private function provider(
        string $point,
        string $template,
        int $priority,
        string $source,
        ?string $templateSource = null,
    ): TemplateOverrideProviderInterface {
        $provider = $this->createStub(TemplateOverrideProviderInterface::class);
        $provider->method('getPoint')->willReturn($point);
        $provider->method('getTemplate')->willReturn($template);
        $provider->method('getPriority')->willReturn($priority);
        $provider->method('getSource')->willReturn($source);
        $provider->method('getTemplateSource')->willReturn($templateSource);

        return $provider;
    }

    private function activeRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn(true);

        return $repo;
    }

    public function testResolveReturnsDefaultWhenNoProviderMatchesPoint(): void
    {
        $resolver = new TemplateOverrideResolver(
            [$this->provider('other.point', 'other.html.twig', 0, 'OtherBundle')],
            $this->activeRepo(),
        );

        self::assertSame('default.html.twig', $resolver->resolve('cart.summary', 'default.html.twig'));
    }

    public function testResolveReturnsSoleMatchingProviderTemplate(): void
    {
        $resolver = new TemplateOverrideResolver(
            [$this->provider('cart.summary', 'override.html.twig', 5, 'CartBundle')],
            $this->activeRepo(),
        );

        self::assertSame('override.html.twig', $resolver->resolve('cart.summary', 'default.html.twig'));
    }

    public function testResolvePicksHighestPriorityAmongMatchingProviders(): void
    {
        $resolver = new TemplateOverrideResolver(
            [
                $this->provider('cart.summary', 'low.html.twig', 1, 'LowBundle'),
                $this->provider('cart.summary', 'high.html.twig', 10, 'HighBundle'),
                $this->provider('cart.summary', 'mid.html.twig', 5, 'MidBundle'),
            ],
            $this->activeRepo(),
        );

        self::assertSame('high.html.twig', $resolver->resolve('cart.summary', 'default.html.twig'));
    }

    public function testResolveSkipsProvidersFromInactiveBundles(): void
    {
        $activeProvider = $this->provider('cart.summary', 'active.html.twig', 1, 'ActiveBundle');
        $inactiveProvider = $this->provider('cart.summary', 'inactive.html.twig', 100, 'InactiveBundle');

        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturnMap([
            ['ActiveBundle', true],
            ['InactiveBundle', false],
        ]);

        $resolver = new TemplateOverrideResolver([$activeProvider, $inactiveProvider], $repo);

        self::assertSame('active.html.twig', $resolver->resolve('cart.summary', 'default.html.twig'));
    }

    public function testResolveSourceReturnsWinningProviderTemplateSource(): void
    {
        $resolver = new TemplateOverrideResolver(
            [$this->provider('cart.summary', 'override.html.twig', 5, 'CartBundle', '<div>raw twig</div>')],
            $this->activeRepo(),
        );

        self::assertSame('<div>raw twig</div>', $resolver->resolveSource('cart.summary'));
    }

    public function testResolveSourceReturnsNullWhenWinnerHasNoRawSource(): void
    {
        $resolver = new TemplateOverrideResolver(
            [$this->provider('cart.summary', 'override.html.twig', 5, 'CartBundle')],
            $this->activeRepo(),
        );

        self::assertNull($resolver->resolveSource('cart.summary'));
    }

    public function testResolveSourceReturnsNullWhenNoProviderMatches(): void
    {
        $resolver = new TemplateOverrideResolver([], $this->activeRepo());

        self::assertNull($resolver->resolveSource('cart.summary'));
    }
}
