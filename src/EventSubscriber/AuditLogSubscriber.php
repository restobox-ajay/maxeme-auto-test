<?php

namespace App\EventSubscriber;

use App\Entity\SalesOrderLog;
use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Entity\EmailLog;
use App\Entity\ErrorLog;
use App\Entity\EstimateLog;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\InvoiceLog;
use App\Service\AuditLogger;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::preFlush)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditLogSubscriber
{
    /**
     * Entities that would recurse (this log itself), are pure system noise, or hold
     * generic key/value config that can carry secrets under a non-distinctive column name
     * (AppSetting stores Stripe API keys as a plain `settingValue` — field-name redaction
     * below can't catch that, so the whole entity is excluded rather than partially logged).
     */
    private const EXCLUDED = [
        AuditLog::class,
        ErrorLog::class,
        EmailLog::class,
        SalesOrderLog::class,
        // The quote's half of the same document log. Omitted when quotes were added, which had
        // every quote note and status change recorded twice — once as the note, once as an
        // audit row saying a note was written (#273).
        EstimateLog::class,
        // The invoice's half (#539). Listed with its two siblings from the outset rather than after
        // stage 2 starts writing entries, so the #273 defect cannot arrive a third time.
        InvoiceLog::class,
        AppSetting::class,
        InventoryBucketChangeLog::class,
        InventoryReconciliationDiscrepancy::class,
    ];

    /**
     * Field names (case-insensitive substring match) never logged in cleartext — credential/secret
     * material. 'token' also catches resetTokenExpiresAt (a timestamp, not a secret) — an accepted
     * over-redaction, safer than the alternative of a narrower pattern missing a real token field.
     *
     * 'apikey' covers ApiCredential::$apiKey, which is a live credential: it authenticates as the
     * customer who owns it, and /profile/api-key promises that user nobody else can see it. The
     * entity itself is deliberately NOT excluded from auditing — that a key was generated, rotated
     * or revoked is exactly the kind of event the log exists for. Only the value is withheld.
     */
    private const REDACTED_FIELDS = ['password', 'secret', 'token', 'apikey'];

    private const REDACTED_PLACEHOLDER = '***REDACTED***';

    /**
     * Checked in this order against the live entity (not the changeset — the identifying
     * field often isn't the one that changed, e.g. BundleStatus's `source` never changes
     * on a status toggle) to build a human-readable summary instead of a bare numeric id.
     */
    private const LABEL_GETTERS = [
        'getName', 'getSlug', 'getSource', 'getSku', 'getOrderNumber', 'getEmail', 'getLabel', 'getTitle',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->queue($entity, 'created', $uow);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->queue($entity, 'updated', $uow);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->queue($entity, 'deleted', $uow);
        }
    }

    /**
     * Drains whatever narrative timeline entries any currently-managed document queued on itself
     * this request — `SalesOrder::approve()`, `Invoice::issue()` and the rest of the named
     * actions, via `AbstractSalesDocument::queueActivityLogEntry()` / the buy-side equivalent.
     *
     * preFlush, not onFlush's scheduled-insertions/updates like the generic diff audit above:
     * Doctrine only schedules an entity for update when a MAPPED field actually changed, and a
     * pending activity-log entry lives in a plain, non-mapped property — EstimateController's
     * "add a note" action changes nothing else on the Estimate at all, so that entity would never
     * appear in getScheduledEntityUpdates() and its entry would be silently dropped. The identity
     * map has no such condition: every entity Doctrine is currently managing is in it, changed or
     * not, which is what a queued-but-otherwise-untouched entry needs.
     *
     * This is now the ONLY thing that writes one of these rows, which is what keeps this from
     * becoming #273 again: there is no second, automatic capture running alongside it, because the
     * entity types that used to need excluding from `queue()` above (SalesOrderLog, InvoiceLog,
     * EstimateLog) no longer exist to schedule an insert of their own.
     */
    public function preFlush(PreFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if (!method_exists($entity, 'pullPendingActivityLogEntries')) {
                    continue;
                }

                $entityType = (new \ReflectionClass($entity))->getShortName();

                foreach ($entity->pullPendingActivityLogEntries() as $pending) {
                    $this->auditLogger->queueActivityLogEntry(
                        $entity,
                        $entityType,
                        $pending->getType(),
                        $pending->getUserName() ?? 'System',
                        $pending->getComment(),
                        $pending->isRecipientNotified(),
                    );
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->auditLogger->flushQueued();
    }

    private function queue(object $entity, string $action, UnitOfWork $uow): void
    {
        if ($this->isExcluded($entity)) {
            return;
        }

        $entityType = (new \ReflectionClass($entity))->getShortName();
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;
        $entityId = is_int($id) ? $id : null;
        $label = $this->resolveLabel($entity);

        if ($action === 'deleted') {
            $dataBefore = $this->normalizeFields($uow->getOriginalEntityData($entity));
            $dataAfter = null;
        } else {
            [$before, $after] = $this->splitChangeSet($uow->getEntityChangeSet($entity));
            // On insert, Doctrine's changeset old-value half is always null (nothing existed
            // before) — mirrors the manual log() convention of dataBefore: null for 'created'.
            $dataBefore = $action === 'created' ? null : ($before !== [] ? $before : null);
            $dataAfter = $after !== [] ? $after : null;
        }

        $this->auditLogger->queueEntityChange($entityType, $entityId, $action, $dataBefore, $dataAfter, $label, $entity);
    }

    private function isExcluded(object $entity): bool
    {
        foreach (self::EXCLUDED as $excludedClass) {
            if ($entity instanceof $excludedClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads directly off the live entity, not the changeset — the field that best identifies
     * an entity to a human (a name, slug, source, etc.) is frequently not the field that
     * changed, so it would otherwise never appear anywhere in an automatic log entry.
     */
    private function resolveLabel(object $entity): ?string
    {
        foreach (self::LABEL_GETTERS as $getter) {
            if (!method_exists($entity, $getter)) {
                continue;
            }

            $value = $entity->$getter();
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function splitChangeSet(array $changeSet): array
    {
        $before = [];
        $after = [];

        foreach ($changeSet as $field => $pair) {
            // Doctrine occasionally signals a de-referenced to-many collection with a raw
            // value instead of an [old, new] tuple — not a scalar field diff, skip it.
            if (!is_array($pair) || count($pair) !== 2) {
                continue;
            }

            [$old, $new] = $pair;
            if ($this->isRedactedField($field)) {
                $before[$field] = $old === null ? null : self::REDACTED_PLACEHOLDER;
                $after[$field] = $new === null ? null : self::REDACTED_PLACEHOLDER;
                continue;
            }
            $before[$field] = $this->normalizeValue($old);
            $after[$field] = $this->normalizeValue($new);
        }

        return [$before, $after];
    }

    /** @param array<string, mixed> $fields */
    private function normalizeFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $field => $value) {
            $result[$field] = $this->isRedactedField($field) && $value !== null
                ? self::REDACTED_PLACEHOLDER
                : $this->normalizeValue($value);
        }

        return $result;
    }

    private function isRedactedField(string $field): bool
    {
        $lower = strtolower($field);
        foreach (self::REDACTED_FIELDS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \Doctrine\Common\Collections\Collection) {
            // Avoid initializing a lazy to-many collection just to log it — count() on a
            // PersistentCollection is cheap (backed by a COUNT query), unlike iterating it.
            return sprintf('%d item(s)', $value->count());
        }

        if (is_object($value)) {
            $label = (new \ReflectionClass($value))->getShortName();

            return method_exists($value, 'getId') ? $label . '#' . ($value->getId() ?? '?') : $label;
        }

        if (is_array($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        return (string) $value;
    }
}
