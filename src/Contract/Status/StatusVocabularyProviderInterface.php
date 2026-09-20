<?php

declare(strict_types=1);

namespace App\Contract\Status;

/**
 * Lets whoever owns a document type declare its status vocabulary (handoff section 4).
 *
 * **Definitions are distributed; the lookup is central.** One loader, many contributors, and not
 * one big file. Core ships a provider for the documents core owns; `ProcurementBundle` ships its
 * own for the buy side; a future bundle ships its own. If purchase-order statuses lived in a core
 * file, switching the bundle off would leave a vocabulary for documents that no longer exist.
 *
 * The eleventh collection of a shape core already uses ten times — `app.injection_point`,
 * `app.template_override`, `app.document_prefix_provider`, `app.catalog_facet_provider` and the
 * rest. It is modelled most closely on `DocumentPrefixProviderInterface`, which solves the nearest
 * problem: a bundle needing to contribute a keyed definition that core collects.
 *
 * **Tagged by hand in each bundle's own `services.yaml`**, like every other seam in this app.
 * There is no `registerForAutoconfiguration()` call and no `_instanceof` block anywhere in this
 * codebase, and hand-tagging keeps the seam greppable: every contributor to every seam is found by
 * searching `app.`, which an autoconfigured service would not be.
 *
 * ## Two providers may not claim the same key
 *
 * {@see \App\Status\StatusVocabularyLoader} throws in its constructor on a collision. That is
 * deliberately harsher than `DocumentPrefixCatalogue`, which drops the duplicate and lets core
 * win: a prefix collision costs a settings field, and a status-vocabulary collision silently
 * changes what transitions are legal on a live document. Last-one-wins is impossible to debug.
 *
 * ## Bundle-off behaviour: providers are NOT gated
 *
 * Unlike the prefix and email-template catalogues, this loader does not filter providers on
 * `BundleStatusRepository::isActive()`. Bundles are inert until activated, so a switched-off
 * bundle's controllers deny the request — but its services still exist, and so do its entity
 * classes. A vocabulary sitting in the loader for a document nobody can reach costs nothing and is
 * asked for by nothing.
 *
 * The gate belongs on the **customisation screen**, not here: a screen offering to customise
 * purchase-order statuses while ProcurementBundle is Inactive would be offering to configure
 * documents that do not exist. No such screen exists in this phase; when one is built it filters
 * `StatusVocabularyLoader::getVocabularies()` through `isActive()`, exactly as
 * `DocumentPrefixCatalogue::activeProviders()` already does for its screen — and it must exempt
 * `BundleStatusRepository::CORE_SOURCE` the same way, or core's own vocabularies vanish from the
 * only screen that could edit them.
 */
interface StatusVocabularyProviderInterface
{
    /**
     * The vocabularies this provider owns, keyed by vocabulary key ('invoice', 'purchase_order').
     *
     * Each value is `['statuses' => [...], 'transitions' => [...]]`:
     *
     * ```php
     * 'invoice' => [
     *     'statuses' => [
     *         'Draft'   => ['label' => 'Draft'],
     *         'Pending' => ['label' => 'Pending'],
     *         // a bare string is shorthand for a label with no derived flag
     *         'On Hold' => 'On Hold',
     *     ],
     *     'transitions' => [
     *         'Draft'   => ['Pending', 'On Hold', 'Cancelled'],
     *         'Pending' => ['Processing', 'Cancelled'],
     *     ],
     * ],
     * ```
     *
     * The transitions must reproduce what is legal TODAY, no more and no less. They are transcribed
     * from the existing verb guards — `issue()`, `approve()`, `void()`, `dispute()`, `cancel()`,
     * `complete()` and the derivers — not designed from what looks sensible. Get one wrong and you
     * have either blocked something people do every day or allowed something that was refused for a
     * reason nobody wrote down.
     *
     * @return array<string, array{statuses: array<string, string|array{label?: string, derived?: bool}>, transitions?: array<string, list<string>>}>
     */
    public function statusVocabularies(): array;
}
