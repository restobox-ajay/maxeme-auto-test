<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Contract\Email\ShippedEmailTemplateProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * The shipped catalogue: core's templates plus those of every Active bundle (#563).
 *
 * `ShippedEmailTemplates` stays exactly what it is — core's own 22, a static class with a hardcoded
 * array, byte-verified against the migration chain by ShippedEmailTemplatesMatchTheChainTest. This
 * sits in front of it so callers stop reaching for the static directly and bundle templates appear
 * everywhere core's do: the panel, the editor, the preview, revert.
 *
 * **Core wins on collision.** A provider contributing a code core already ships is ignored, and
 * deliberately without an exception: a bundle silently overriding a core template would be a very
 * quiet way to change what a customer receives, and throwing at boot over an email template would
 * take the whole instance down for something cosmetic.
 *
 * Providers are gated with BundleStatusRepository::isActiveForInstance() — the same gate
 * ProductController uses for fee field providers — so deactivating a bundle removes its templates
 * from the panel rather than leaving entries nothing can send.
 */
final class ShippedEmailTemplateCatalogue
{
    /** @param iterable<ShippedEmailTemplateProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatuses,
    ) {
    }

    /**
     * Core's codes first, in their existing order, then each Active provider's.
     *
     * Order matters only for the panel listing, and core leading keeps that screen looking the way
     * it does today with no bundles installed.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = ShippedEmailTemplates::codes();

        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->codes() as $code) {
                if (!\in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    public function has(string $code): bool
    {
        return $this->get($code) !== null;
    }

    /**
     * @return array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}|null
     */
    public function get(string $code): ?array
    {
        $core = ShippedEmailTemplates::get($code);
        if ($core !== null) {
            return $core;
        }

        foreach ($this->activeProviders() as $provider) {
            $definition = $provider->get($code);
            if ($definition !== null) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<ShippedEmailTemplateProviderInterface> */
    private function activeProviders(): array
    {
        $active = [];

        foreach ($this->providers as $provider) {
            if ($provider instanceof ShippedEmailTemplateProviderInterface
                && $this->bundleStatuses->isActiveForInstance($provider)
            ) {
                $active[] = $provider;
            }
        }

        return $active;
    }
}
