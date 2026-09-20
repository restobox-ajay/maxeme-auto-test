<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class CustomerUrlGenerator
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        private readonly string $customerHost,
    ) {
    }

    public function generate(string $route, array $parameters = []): string
    {
        $path = $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        $configuredHost = trim($this->customerHost);

        if ($configuredHost === '') {
            return $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $request = $this->requestStack->getMainRequest();
        $scheme = $request?->getScheme() ?? 'https';
        $port = $request?->getPort();

        if (preg_match('#^https?://#i', $configuredHost) === 1) {
            $parts = parse_url($configuredHost);
            $scheme = (string) ($parts['scheme'] ?? $scheme);
            $configuredHost = (string) ($parts['host'] ?? $configuredHost);
            $port = isset($parts['port']) ? (int) $parts['port'] : $port;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portSuffix = ($port !== null && $port !== $defaultPort) ? ':' . $port : '';

        return $scheme . '://' . $configuredHost . $portSuffix . $path;
    }
}
