<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Admin-route counterpart of CustomerUrlGenerator. Needed because admin links generated
 * from a customer-firewall request (e.g. an admin notification email sent from checkout)
 * would otherwise inherit the current request's host — the customer host, not ADMIN_HOST —
 * and AdminHostSubscriber 404s any /admin/* path visited on a non-admin host.
 */
final class AdminUrlGenerator
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        private readonly string $adminHost,
    ) {
    }

    public function generate(string $route, array $parameters = []): string
    {
        $path = $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        $configuredHost = trim($this->adminHost);

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
