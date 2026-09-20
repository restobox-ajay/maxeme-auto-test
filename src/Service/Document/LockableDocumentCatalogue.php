<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Contract\Document\LockableDocument;
use App\Contract\Document\LockableDocumentProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * Every document type a lock can be held against right now: core's, plus every Active bundle's
 * (#759).
 *
 * Modelled on `DocumentPrefixCatalogue` (#615) and `CustomFieldObjectTypeCatalogue` (#745) — same
 * two deliberate choices, for the same reasons:
 *
 * - **Core is a provider here, not a static list.** `CoreLockableDocumentProvider` declares
 *   Estimate/SalesOrder/Invoice through this interface rather than core staying a hardcoded special
 *   case, which is what makes "a document that forgets to register" a testable failure.
 * - **Core still wins a key collision.** Core's providers are ordered first and a duplicate
 *   `typeKey` is dropped rather than throwing — a bundle quietly taking over `order` would point a
 *   sales order's lock at the wrong entity.
 *
 * Bundle providers are gated with `isActiveForInstance()`, same as the other two catalogues. Unlike
 * them, this one is genuinely belt-and-braces for the documents registered so far: every
 * `PurchaseOrderController`/`VendorBillController` action already refuses with `denyIfInactive()`
 * before it could reach a lock check either way. It still matters for whatever bundle registers a
 * lockable document next without the same gate.
 */
final class LockableDocumentCatalogue
{
    private const CORE_SOURCE = 'App';

    /** @param iterable<LockableDocumentProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatuses,
    ) {
    }

    /**
     * Every lockable document, core's first, then each Active bundle's in registration order.
     *
     * @return list<LockableDocument>
     */
    public function all(): array
    {
        $documents = [];
        $seen = [];

        foreach ($this->activeProviders() as $provider) {
            foreach ($provider->lockableDocuments() as $document) {
                if (isset($seen[$document->typeKey])) {
                    continue;
                }

                $seen[$document->typeKey] = true;
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /** The registered document matching $document's class, checked by `instanceof` for a Doctrine proxy. */
    public function forDocument(object $document): ?LockableDocument
    {
        foreach ($this->all() as $candidate) {
            if ($document instanceof $candidate->documentClass) {
                return $candidate;
            }
        }

        return null;
    }

    public function forType(string $typeKey): ?LockableDocument
    {
        foreach ($this->all() as $candidate) {
            if ($candidate->typeKey === $typeKey) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The document a write to $entity is a write to, or null when $entity is neither a lockable
     * document nor a registered child of one.
     */
    public function documentFor(object $entity): ?object
    {
        if ($this->forDocument($entity) !== null) {
            return $entity;
        }

        foreach ($this->all() as $candidate) {
            foreach ($candidate->owners as $childClass => $accessor) {
                if ($entity instanceof $childClass) {
                    return $entity->{$accessor}();
                }
            }
        }

        return null;
    }

    /** Whether a collection holding $targetEntity is one some document's lock freezes. */
    public function guardsCollectionOf(string $targetEntity): bool
    {
        foreach ($this->all() as $candidate) {
            if (isset($candidate->owners[$targetEntity])) {
                return true;
            }

            foreach (array_keys($candidate->owners) as $class) {
                if (is_a($targetEntity, $class, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Core's providers first so that core wins a key collision, then the rest in the order the
     * container handed them over.
     *
     * @return list<LockableDocumentProviderInterface>
     */
    private function activeProviders(): array
    {
        $core = [];
        $bundles = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof LockableDocumentProviderInterface) {
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
