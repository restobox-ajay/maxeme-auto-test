<?php

declare(strict_types=1);

namespace App\Contract\Status;

use App\Status\StatusVocab;

/**
 * The one thing that answers "what statuses exist for key X" (handoff section 3).
 *
 * Every `setStatus()`, every `isStatus()`, every filter bar and every status dropdown asks this.
 *
 * **An interface because the database-backed version is coming.** The owner's stated goal is
 * customer-defined statuses, so {@see \App\Status\StatusVocabularyLoader} is explicitly *the first*
 * implementation rather than *the* implementation. Putting a loader in front of the arrays is what
 * makes swapping the source later change one implementation and no call sites.
 *
 * ## The define/edit surface belongs here, not on `HasStatus`
 *
 * `$invoice->setVocabulary(...)` would mean one invoice redefining statuses for all invoices — a
 * per-type, global, editable thing sitting on a per-instance interface. It is the same category
 * error as `setTaxProvince()` on a document, which this codebase has already removed once. The
 * entity says *which* vocabulary governs it; the loader owns *what* is in it.
 */
interface StatusVocabularyLoaderInterface
{
    /**
     * @throws \LogicException when no provider declares this key — a vocabulary key is written in
     *     source, so an unknown one is a typo rather than a condition to recover from
     */
    public function getVocabulary(string $key): StatusVocab;

    public function hasVocabulary(string $key): bool;

    /**
     * Every vocabulary that exists, keyed by vocabulary key — for the future customisation screen.
     *
     * That screen must filter these on `BundleStatusRepository::isActive()` before offering them:
     * see {@see StatusVocabularyProviderInterface} for why the loader itself deliberately does not.
     *
     * @return array<string, StatusVocab>
     */
    public function getVocabularies(): array;

    /**
     * Where the future customisation UI writes.
     *
     * Declared here because it is part of the loader's shape, and unsupported by the config-backed
     * first implementation, which throws rather than accepting a write it cannot keep. A method
     * that took the edit and dropped it at the end of the request is precisely the fail-silently
     * this seam exists to delete.
     *
     * @param array{statuses: array<string, string|array{label?: string, derived?: bool}>, transitions?: array<string, list<string>>} $definition
     */
    public function setVocabulary(string $key, array $definition): void;
}
