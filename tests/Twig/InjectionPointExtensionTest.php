<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Contract\Hook\InjectionPointProviderInterface;
use App\Repository\BundleStatusRepository;
use App\Twig\InjectionPointExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

final class InjectionPointExtensionTest extends TestCase
{
    private function provider(
        string $point,
        int $priority,
        string $source,
        string $html,
    ): InjectionPointProviderInterface {
        $provider = $this->createStub(InjectionPointProviderInterface::class);
        $provider->method('getPoint')->willReturn($point);
        $provider->method('getPriority')->willReturn($priority);
        $provider->method('getSource')->willReturn($source);
        $provider->method('render')->willReturn($html);

        return $provider;
    }

    private function activeRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn(true);

        return $repo;
    }

    public function testRenderReturnsEmptyStringWhenNoProviderMatchesPoint(): void
    {
        $extension = new InjectionPointExtension(
            [$this->provider('other.point', 0, 'OtherBundle', '<div>other</div>')],
            $this->activeRepo(),
        );

        self::assertSame('', $extension->render('checkout.summary'));
    }

    public function testRenderReturnsSoleMatchingProviderOutput(): void
    {
        $extension = new InjectionPointExtension(
            [$this->provider('checkout.summary', 0, 'CheckoutBundle', '<div>summary</div>')],
            $this->activeRepo(),
        );

        self::assertSame('<div>summary</div>', $extension->render('checkout.summary'));
    }

    public function testRenderConcatenatesMultipleMatchingProvidersInAscendingPriorityOrder(): void
    {
        $extension = new InjectionPointExtension(
            [
                $this->provider('checkout.summary', 10, 'HighBundle', '<div>high</div>'),
                $this->provider('checkout.summary', 1, 'LowBundle', '<div>low</div>'),
            ],
            $this->activeRepo(),
        );

        self::assertSame('<div>low</div><div>high</div>', $extension->render('checkout.summary'));
    }

    public function testRenderSkipsProvidersFromInactiveBundles(): void
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturnMap([
            ['ActiveBundle', true],
            ['InactiveBundle', false],
        ]);

        $extension = new InjectionPointExtension(
            [
                $this->provider('checkout.summary', 0, 'ActiveBundle', '<div>active</div>'),
                $this->provider('checkout.summary', 1, 'InactiveBundle', '<div>inactive</div>'),
            ],
            $repo,
        );

        self::assertSame('<div>active</div>', $extension->render('checkout.summary'));
    }

    public function testRenderPassesContextThroughToMatchingProviders(): void
    {
        $context = ['order' => 'ORD-1'];

        $provider = $this->createMock(InjectionPointProviderInterface::class);
        $provider->method('getPoint')->willReturn('checkout.summary');
        $provider->method('getPriority')->willReturn(0);
        $provider->method('getSource')->willReturn('CheckoutBundle');
        $provider->expects(self::once())->method('render')->with($context)->willReturn('<div>summary</div>');

        $extension = new InjectionPointExtension([$provider], $this->activeRepo());

        self::assertSame('<div>summary</div>', $extension->render('checkout.summary', $context));
    }

    public function testGetFunctionsRegistersInjectionPointAsHtmlSafe(): void
    {
        $extension = new InjectionPointExtension([], $this->activeRepo());

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('injection_point', $functions[0]->getName());
        self::assertSame(['html'], $functions[0]->getSafe(new \Twig\Node\Expression\ConstantExpression(1, 0)));
    }
}
