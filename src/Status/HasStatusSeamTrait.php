<?php

declare(strict_types=1);

namespace App\Status;

use App\Contract\Document\DocumentLog;
use App\Exception\StatusTransitionRefused;
use App\Service\DocumentActor;

/**
 * Shared {@see \App\Contract\Status\HasStatus} bodies for master-data entities that have no common
 * ancestor to hold them.
 *
 * `AbstractSalesDocument` carries the same bodies for Cart/Estimate/SalesOrder/Invoice, and its own
 * docblock explains why it is a mapped superclass rather than a trait: those four already share one.
 * `AdminUser`, `CustomerUser` and `Company` do not — `CustomerUser`'s own docblock records why a
 * shared parent with `AdminUser` was rejected once already (PartyContact, #635) — so a trait is what
 * avoids writing this seam three times over classes that must otherwise stay unrelated.
 *
 * Every using class supplies: the `STATUS_VOCABULARY` constant, `readStatus()`/`writeStatus()` for
 * its own column, and — where it has one — its own `newLogEntry()`. None of these three keeps a
 * timeline of its own, so the default below (null) is what all three currently use; a status change
 * still reaches the row via `AuditLogSubscriber`, the same fallback `CreditMemo`/`DebitMemo` rely on.
 *
 * `setStatus()` is PUBLIC directly here, unlike the protected-then-reopened shape on
 * `AbstractSalesDocument`. That indirection exists there solely to keep `Cart` — which has no status
 * at all — from inheriting a write door onto nothing. Every class that uses this trait genuinely has
 * a status, so there is no equivalent case to guard against.
 */
trait HasStatusSeamTrait
{
    abstract protected function readStatus(): string;

    abstract protected function writeStatus(string $status): void;

    public function statusVocabulary(): string
    {
        return static::STATUS_VOCABULARY;
    }

    public static function loadStatusVocab(): StatusVocab
    {
        return StatusVocabularyRegistry::get(static::STATUS_VOCABULARY);
    }

    /** @return array<string, string> slug => label */
    public static function listStatuses(): array
    {
        return static::loadStatusVocab()->labels();
    }

    /**
     * THE GATE. See `HasStatus::setStatus()` for the full ruling — same three-step order here:
     * the typo guard, then this class's own `assertStatusChangeAllowed()`, then the no-op check.
     *
     * `$actor` is REQUIRED, same as the document seam, on the same reasoning: a status change is
     * something somebody or something did, and that fact must be capturable at the point of the
     * call — not reconstructed later from whoever happened to be signed in. `writeStatusChange()`
     * below does not currently write it anywhere (`newLogEntry()` returns null for all three classes
     * on this trait — none keeps a timeline entity yet), but the caller passing it is what makes
     * adding one later a storage change, not an every-call-site hunt for who to blame. Every current
     * caller can already say who or what is acting — `DocumentActor::forAdmin()`/`forCustomer()` for
     * a signed-in request, `DocumentActor::system()` for a console command, an import, or a fixture —
     * so there is no genuine case this requirement is being defaulted around.
     *
     * @throws StatusTransitionRefused on a move this entity refuses
     * @throws \LogicException on a status this vocabulary does not know — the typo guard
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        $vocabulary = static::loadStatusVocab();

        if (!$vocabulary->has($status)) {
            throw new \LogicException(sprintf(
                'There is no status "%s" in the "%s" vocabulary. It has: %s.',
                $status,
                $vocabulary->key,
                implode(', ', $vocabulary->slugs()),
            ));
        }

        $current = $this->readStatus();

        $this->assertStatusChangeAllowed($current, $status);

        if ($current === $status) {
            return $current;
        }

        $this->writeStatusChange($status, $actor, $comment ?? $this->defaultStatusComment($current, $status));

        return $status;
    }

    /**
     * This entity's own rules about a REQUESTED move. Throw to refuse; return to allow.
     *
     * No rules by default — `AdminUser`, `CustomerUser` and `Company` refuse nothing here today.
     * Any repository-wide fact a rule would need (e.g. "is this the only active Super Admin") stays
     * a precondition in the calling controller, the same way it is checked today: an entity method
     * has no EntityManager to ask a cross-row question with, and inventing one here would be new
     * internal behaviour this seam was not asked to add.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        unset($from, $to);
    }

    protected function defaultStatusComment(string $from, string $to): string
    {
        return sprintf('Status changed from %s to %s.', $from, $to);
    }

    private function writeStatusChange(string $status, DocumentActor $actor, string $comment): void
    {
        $this->writeStatus($status);

        $log = $this->newLogEntry();
        if ($log !== null) {
            $log->setUserName($actor->displayName)
                ->setComment($comment)
                ->setType('System');
        }
    }

    public function canTransitionTo(string $status): bool
    {
        if (!static::loadStatusVocab()->has($status)) {
            return false;
        }

        try {
            $this->assertStatusChangeAllowed($this->readStatus(), $status);
        } catch (StatusTransitionRefused) {
            return false;
        } catch (\DomainException) {
            return true;
        }

        return true;
    }

    /** @return array<string, array{label: string, derived: bool}> */
    public function allowedTransitions(): array
    {
        $vocabulary = static::loadStatusVocab();
        $current = $this->readStatus();
        $moves = [];

        foreach ($vocabulary->slugs() as $slug) {
            if ($slug === $current || !$this->canTransitionTo($slug)) {
                continue;
            }

            $moves[$slug] = ['label' => $vocabulary->labelFor($slug), 'derived' => $vocabulary->isDerived($slug)];
        }

        return $moves;
    }

    public function isStatus(string $status): bool
    {
        $vocabulary = static::loadStatusVocab();

        if (!$vocabulary->has($status)) {
            throw new \LogicException(sprintf(
                'There is no status "%s" in the "%s" vocabulary. It has: %s.',
                $status,
                $vocabulary->key,
                implode(', ', $vocabulary->slugs()),
            ));
        }

        return $this->readStatus() === $status;
    }

    public function statusIsRecognised(): bool
    {
        return static::loadStatusVocab()->has($this->readStatus());
    }

    public function statusLabel(): string
    {
        $vocabulary = static::loadStatusVocab();
        $current = $this->readStatus();

        return $vocabulary->has($current) ? $vocabulary->labelFor($current) : $current . ' (unrecognised)';
    }

    /** None of the classes on this seam derive a status from anything else. */
    public function deriveStatus(): ?string
    {
        return null;
    }

    public function applyDerivedStatus(DocumentActor $actor): bool
    {
        unset($actor);

        return false;
    }

    /** None of the classes on this seam keep a timeline entity; a status change still audits via AuditLogSubscriber. */
    public function newLogEntry(): ?DocumentLog
    {
        return null;
    }

    /**
     * `HasStatus::canEditOnStatus()`. Unconditionally true for everything on this trait.
     *
     * Unlike a sell-side document, none of AdminUser/CustomerUser/Company/Warehouse/PriceList/
     * FulfillmentRegion/ProductCategory has a status that locks its OWN fields against editing — an
     * Inactive company's name is still correctable, an Inactive warehouse's address is still
     * correctable. What an inactive/hidden status actually restricts (a company cannot check out, a
     * hidden category will not render in the nav) is a fact about how the row is USED elsewhere, not
     * about whether the row's own fields may be changed, so there is no per-status enum rule to
     * delegate to here the way `InvoiceStatus::allowsEditing()` states one for a document.
     */
    public function canEditOnStatus(): bool
    {
        return true;
    }
}
