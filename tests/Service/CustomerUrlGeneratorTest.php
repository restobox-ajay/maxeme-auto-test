<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CustomerUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class CustomerUrlGeneratorTest extends TestCase
{
    private function router(string $absolutePath, string $absoluteUrl = ''): RouterInterface
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            function (string $route, array $parameters, int $referenceType) use ($absolutePath, $absoluteUrl) {
                return $referenceType === UrlGeneratorInterface::ABSOLUTE_PATH ? $absolutePath : $absoluteUrl;
            }
        );

        return $router;
    }

    private function requestStackWithMainRequest(string $scheme, int $port): RequestStack
    {
        $request = Request::create(sprintf('%s://placeholder:%d/', $scheme, $port), 'GET');

        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getMainRequest')->willReturn($request);

        return $requestStack;
    }

    private function emptyRequestStack(): RequestStack
    {
        $requestStack = $this->createStub(RequestStack::class);
        $requestStack->method('getMainRequest')->willReturn(null);

        return $requestStack;
    }

    public function testGenerateFallsBackToRouterAbsoluteUrlWhenHostBlank(): void
    {
        $generator = new CustomerUrlGenerator(
            $this->router('/cart', 'https://router-default.example.com/cart'),
            $this->emptyRequestStack(),
            '   ',
        );

        self::assertSame('https://router-default.example.com/cart', $generator->generate('cart_show'));
    }

    public function testGenerateUsesRequestSchemeAndOmitsDefaultPort(): void
    {
        $generator = new CustomerUrlGenerator(
            $this->router('/cart'),
            $this->requestStackWithMainRequest('https', 443),
            'shop.example.com',
        );

        self::assertSame('https://shop.example.com/cart', $generator->generate('cart_show'));
    }

    public function testGenerateIncludesNonDefaultPortFromRequest(): void
    {
        $generator = new CustomerUrlGenerator(
            $this->router('/cart'),
            $this->requestStackWithMainRequest('https', 8443),
            'shop.example.com',
        );

        self::assertSame('https://shop.example.com:8443/cart', $generator->generate('cart_show'));
    }

    public function testGenerateParsesSchemeHostAndPortFromFullyQualifiedConfiguredHost(): void
    {
        $generator = new CustomerUrlGenerator(
            $this->router('/cart'),
            $this->requestStackWithMainRequest('https', 443),
            'http://internal-shop.example.com:8080',
        );

        self::assertSame('http://internal-shop.example.com:8080/cart', $generator->generate('cart_show'));
    }

    public function testGenerateDefaultsToHttpsSchemeWhenNoMainRequest(): void
    {
        $generator = new CustomerUrlGenerator(
            $this->router('/cart'),
            $this->emptyRequestStack(),
            'shop.example.com',
        );

        self::assertSame('https://shop.example.com/cart', $generator->generate('cart_show'));
    }

    public function testGeneratePassesRouteAndParametersThroughToRouter(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('product_show', ['slug' => 'widget'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/products/widget');

        $generator = new CustomerUrlGenerator(
            $router,
            $this->requestStackWithMainRequest('https', 443),
            'shop.example.com',
        );

        self::assertSame('https://shop.example.com/products/widget', $generator->generate('product_show', ['slug' => 'widget']));
    }
}
