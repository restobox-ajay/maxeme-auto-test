<?php

declare(strict_types=1);

namespace App\Service\Connector;

use App\Contract\Connector\ConnectorTypeProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * Every connector type currently registered and switched on, for the core `/admin/connectors`
 * directory (#741) to render. Aggregates (the "ask every sibling module to contribute rows" shape —
 * see `ProductActivityFeedResolver`), not first-responder: the directory has to show every connected
 * type at once, not stop at the first.
 *
 * Delete-safe and Inactive-safe, same as every other tagged-provider seam in this app: a connector
 * bundle deleted outright contributes nothing (its service was never tagged, so it's simply absent
 * from $providers); a connector bundle still installed but switched Inactive on Bundle Management is
 * skipped by the {@see BundleStatusRepository::isActive()} check below. Either way the directory is
 * just missing a row — never an error, never a gap the admin has to explain. With zero live
 * providers the directory is empty, exactly as it would be on an install with no connector bundles
 * at all.
 */
final class ConnectorRegistry
{
    /** @param iterable<ConnectorTypeProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /** @return list<ConnectorTypeProviderInterface> Every connector type whose bundle is installed and Active. */
    public function activeTypes(): array
    {
        $active = [];

        foreach ($this->providers as $provider) {
            if ($provider instanceof ConnectorTypeProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $active[] = $provider;
            }
        }

        return $active;
    }
}
