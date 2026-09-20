<?php

declare(strict_types=1);

namespace App\Service\CustomField;

use App\Contract\CustomField\CustomFieldObjectType;
use App\Contract\CustomField\CustomFieldObjectTypeProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * Every object type a custom field can target right now: core's, plus every Active bundle's
 * (#745).
 *
 * Modelled on `App\Service\Document\DocumentPrefixCatalogue` (#615), which solves the same problem
 * for document prefixes. Same two deliberate choices:
 *
 * - **Core is a provider here, not a static list.** `CoreCustomFieldObjectTypeProvider` declares
 *   the existing seven types through this interface rather than core staying a hardcoded special
 *   case, which is what makes "a ninth type that forgets to register" a testable failure instead
 *   of an invisible one.
 * - **Core still wins on collision.** Core's providers are ordered first and a duplicate key is
 *   dropped rather than throwing. A bundle quietly taking over `product` would point every
 *   product's custom field values at the wrong table; an exception at boot over a settings screen
 *   would take the instance down for something cosmetic.
 *
 * Bundle providers are gated with `isActiveForInstance()` — the same gate `DocumentPrefixCatalogue`
 * and the shipped email catalogue use — so a bundle switched Inactive drops its object type off the
 * admin dropdown and filter bar. This is belt-and-braces rather than load-bearing for Vendor
 * specifically: every `VendorController` action already refuses with `denyIfInactive()` before it
 * could reach `CustomFieldRenderer`, so an Inactive ProcurementBundle can never actually be asked
 * about a `vendor`-typed field. It matters for whatever bundle registers next and does not carry
 * the same gate.
 */
final class CustomFieldObjectTypeCatalogue
{
    private const CORE_SOURCE = 'App';

    /** @param iterable<CustomFieldObjectTypeProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatuses,
    ) {
    }

    /**
     * Every object type on offer, core's first, then each Active bundle's in registration order.
     *
     * @return list<CustomFieldObjectType>
     */
    public function all(): array
    {
        $types = [];
        $seen = [];

        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->customFieldObjectTypes() as $type) {
                if (isset($seen[$type->key])) {
                    continue;
                }

                $seen[$type->key] = true;
                $types[] = $type;
            }
        }

        return $types;
    }

    public function get(string $key): ?CustomFieldObjectType
    {
        foreach ($this->all() as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * key => label, selectable types only, in registration order.
     *
     * This is what CustomFieldController's dropdown, index filter bar and validator all read —
     * narrower than all(): `company_address` and `product_category` resolve fine through get() but
     * are excluded here, because nothing hangs an ad-hoc field off them from this screen; only the
     * bundle that owns each ever populates it, via `ensureBySlug()`. See CustomFieldObjectType's
     * `$selectable` for the reasoning this preserves from before this catalogue existed.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->all() as $type) {
            if ($type->selectable) {
                $labels[$type->key] = $type->label;
            }
        }

        return $labels;
    }

    /**
     * Core's providers first so that core wins a key collision, then the rest in the order the
     * container handed them over.
     *
     * @return list<CustomFieldObjectTypeProviderInterface>
     */
    private function activeProviders(): array
    {
        $core = [];
        $bundles = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof CustomFieldObjectTypeProviderInterface) {
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
