<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Contract\Document\DocumentPrefix;
use App\Contract\Document\DocumentPrefixProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * Every document prefix that exists right now: core's, plus every Active bundle's (#615).
 *
 * Modelled on {@see \App\Service\Email\ShippedEmailTemplateCatalogue}, which solves the same
 * problem for email templates. The differences are both deliberate:
 *
 * - **Core is a provider here, not a static list.** The email catalogue keeps core's 22 in a static
 *   class because they are byte-pinned to the migration chain. Prefixes have no such pin, and
 *   making core ordinary is the point of #615: the screen renders what registered, and there is no
 *   hard-coded branch left for a ninth document type to fail to be added to.
 * - **Core still wins on collision**, which is why core's providers are ordered first and a
 *   duplicate key is dropped rather than throwing. A bundle quietly taking over the field that
 *   writes `invoice_number_prefix` would renumber a customer's invoices; an exception at boot over
 *   a settings screen would take the instance down for something cosmetic.
 *
 * Providers are gated with `BundleStatusRepository::isActiveForInstance()` — the same gate the
 * email catalogue and the fee-field providers use — so a bundle switched Inactive drops off the
 * screen. Core is exempt from the gate: `sourceFromClass()` reduces `App\…` to `App`, and while no
 * `bundle_status` row is expected to exist for it, an accidental one must not be able to hide the
 * order, quote and invoice prefixes from the only screen that can set them.
 */
final class DocumentPrefixCatalogue
{
    private const CORE_SOURCE = 'App';

    /** @param iterable<DocumentPrefixProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatuses,
    ) {
    }

    /**
     * Every prefix on offer, core's first, then each Active bundle's in registration order.
     *
     * @return list<DocumentPrefix>
     */
    public function all(): array
    {
        $prefixes = [];
        $seen = [];

        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->documentPrefixes() as $prefix) {
                if (isset($seen[$prefix->key])) {
                    continue;
                }

                $seen[$prefix->key] = true;
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    public function get(string $key): ?DocumentPrefix
    {
        foreach ($this->all() as $prefix) {
            if ($prefix->key === $key) {
                return $prefix;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (DocumentPrefix $prefix): string => $prefix->key, $this->all());
    }

    /**
     * Core's providers first so that core wins a key collision, then the rest in the order the
     * container handed them over.
     *
     * @return list<DocumentPrefixProviderInterface>
     */
    private function activeProviders(): array
    {
        $core = [];
        $bundles = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof DocumentPrefixProviderInterface) {
                continue;
            }

            if (BundleStatusRepository::sourceFromClass($provider::class) === self::CORE_SOURCE) {
                $core[] = $provider;

                continue;
            }

            if ($this->bundleStatuses->isActiveForInstance($provider)) {
                $bundles[] = $provider;
            }
        }

        return [...$core, ...$bundles];
    }
}
