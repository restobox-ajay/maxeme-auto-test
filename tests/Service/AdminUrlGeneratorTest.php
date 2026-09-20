<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class AdminUrlGeneratorTest extends TestCase
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
        $generator = new AdminUrlGenerator(
            $this->router('/admin/orders', 'https://router-default.example.com/admin/orders'),
            $this->emptyRequestStack(),
            '   ',
        );

        self::assertSame('https://router-default.example.com/admin/orders', $generator->generate('admin_order_index'));
    }

    public function testGenerateUsesRequestSchemeAndOmitsDefaultPort(): void
    {
        $generator = new AdminUrlGenerator(
            $this->router('/admin/orders'),
            $this->requestStackWithMainRequest('https', 443),
            'admin.example.com',
        );

        self::assertSame('https://admin.example.com/admin/orders', $generator->generate('admin_order_index'));
    }

    public function testGenerateIncludesNonDefaultPortFromRequest(): void
    {
        $generator = new AdminUrlGenerator(
            $this->router('/admin/orders'),
            $this->requestStackWithMainRequest('https', 8443),
            'admin.example.com',
        );

        self::assertSame('https://admin.example.com:8443/admin/orders', $generator->generate('admin_order_index'));
    }

    public function testGenerateParsesSchemeHostAndPortFromFullyQualifiedConfiguredHost(): void
    {
        $generator = new AdminUrlGenerator(
            $this->router('/admin/orders'),
            $this->requestStackWithMainRequest('https', 443),
            'http://internal-admin.example.com:8080',
        );

        self::assertSame('http://internal-admin.example.com:8080/admin/orders', $generator->generate('admin_order_index'));
    }

    public function testGenerateDefaultsToHttpsSchemeWhenNoMainRequest(): void
    {
        $generator = new AdminUrlGenerator(
            $this->router('/admin/orders'),
            $this->emptyRequestStack(),
            'admin.example.com',
        );

        self::assertSame('https://admin.example.com/admin/orders', $generator->generate('admin_order_index'));
    }

    public function testGeneratePassesRouteAndParametersThroughToRouter(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('admin_order_show', ['id' => 42], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/orders/42');

        $generator = new AdminUrlGenerator(
            $router,
            $this->requestStackWithMainRequest('https', 443),
            'admin.example.com',
        );

        self::assertSame('https://admin.example.com/admin/orders/42', $generator->generate('admin_order_show', ['id' => 42]));
    }
}
