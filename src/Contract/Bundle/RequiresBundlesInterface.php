<?php

declare(strict_types=1);

namespace App\Contract\Bundle;

/**
 * Optional companion to {@see BundleDescriptorInterface}: a bundle that hard-imports another
 * bundle's classes implements this to say so (#788).
 *
 * Not added to `BundleDescriptorInterface` itself — every one of the ~30 existing descriptors
 * would need a method that says "none" for it, for no benefit. A bundle with no requirement simply
 * does not implement this interface at all, and {@see \App\Service\Bundle\BundleDependencyGraph}
 * treats "does not implement it" the same as "implements it and returns []".
 */
interface RequiresBundlesInterface
{
    /**
     * The sources (e.g. 'InventoryDepthBundle') this bundle cannot run without.
     *
     * Direct requirements only — {@see \App\Service\Bundle\BundleDependencyGraph} computes the
     * transitive closure. An optional/"nice to have" integration is not a requirement and does not
     * belong here; see the interface's own docblock for the soft-integration distinction
     * (App\Contract\… seams are not dependencies).
     *
     * @return list<string>
     */
    public function getRequiredBundles(): array;
}
