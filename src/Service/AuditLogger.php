<?php

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogger
{
    /**
     * A change record does not need the full text of a field to answer "what changed" — it needs
     * enough to recognise it, plus how long it really was (#302). Without this, an oversized value
     * (no field on the sales-document forms had a server-side length cap until #302's other half)
     * was copied whole into dataBefore/dataAfter on every single edit from then on: the input could
     * be corrected, but every audit row already holding the old value could not, so the bloat this
     * exists to stop was otherwise permanent and grew with every subsequent edit.
     */
    private const MAX_VALUE_LENGTH = 500;

    /**
     * @var list<array{
     *     entityType: string, entityId: ?int, action: string, dataBefore: ?array, dataAfter: ?array,
     *     label: ?string, entity: ?object, actorType: string, actorId: ?int, actorName: string,
     * }>
     */
    private array $queued = [];

    /**
     * @var list<array{
     *     entity: object, entityType: string, action: string, actorName: string,
     *     summary: string, recipientNotified: bool,
     * }>
     *
     * Separate from $queued rather than a variant shape in it: an activity-log entry already
     * carries its own actor name and summary text (attributed by the caller, not resolved from
     * the request) and has no before/after diff at all — forcing it through the same array shape
     * would mean every reader of $queued has to branch on which kind of row it is looking at.
     */
    private array $queuedActivityLog = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentActorResolver $actorResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * The client IP behind the current request, or null when there isn't one.
     *
     * Resolved through Symfony's Request so it honours trusted proxies, and null for console
     * commands, queued work and migrations rather than inventing a value for them.
     */
    private function clientIp(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp();
    }

    public function log(
        string $area,
        string $entityType,
        ?int $entityId,
        string $action,
        string $summary,
        ?array $dataBefore = null,
        ?array $dataAfter = null,
    ): void {
        [$actorType, $actorId, $actorName] = $this->resolveActor();

        $log = (new AuditLog())
            ->setActorType($actorType)
            ->setActorId($actorId)
            ->setActorName($actorName)
            ->setArea($area)
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setIpAddress($this->clientIp())
            ->setAction($action)
            ->setSummary($summary)
            ->setDataBefore($dataBefore !== null ? json_encode($this->bounded($dataBefore)) : null)
            ->setDataAfter($dataAfter !== null ? json_encode($this->bounded($dataAfter)) : null);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * Buffers the raw inputs for an AuditLog row in memory for the automatic entity-change
     * listener — does NOT persist, flush, or build the AuditLog entity yet. Call flushQueued()
     * once the originating flush completes (attempting to persist/flush mid-flush triggers
     * Doctrine's re-entrant-flush error).
     *
     * $entityId may be null here even for a real, identifiable row: on a fresh insert, this is
     * called from onFlush, before Doctrine has actually run the INSERT — the database hasn't
     * assigned an id yet. Pass $entity (the same object Doctrine is about to insert) so
     * flushQueued() can re-read its id later, once the INSERT has actually happened and
     * Doctrine has written the generated id onto that same object.
     */
    public function queueEntityChange(
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $dataBefore = null,
        ?array $dataAfter = null,
        ?string $label = null,
        ?object $entity = null,
    ): void {
        [$actorType, $actorId, $actorName] = $this->resolveActor();

        $this->queued[] = [
            'entityType' => $entityType,
            'entityId' => $entityId,
            'action' => $action,
            'dataBefore' => $dataBefore,
            'dataAfter' => $dataAfter,
            'label' => $label,
            'entity' => $entity,
            'actorType' => $actorType,
            'actorId' => $actorId,
            'actorName' => $actorName,
        ];
    }

    /**
     * Buffers one narrative timeline entry — AuditLogSubscriber calls this once per
     * PendingActivityLogEntry it finds on a flushed document, having already resolved the actor
     * name and comment text the entity itself queued. $entity is kept, not its id, for the same
     * reason queueEntityChange() keeps it: a brand-new document's id does not exist yet at
     * onFlush time.
     */
    public function queueActivityLogEntry(
        object $entity,
        string $entityType,
        string $action,
        string $actorName,
        string $summary,
        bool $recipientNotified,
    ): void {
        $this->queuedActivityLog[] = [
            'entity' => $entity,
            'entityType' => $entityType,
            'action' => $action,
            'actorName' => $actorName,
            'summary' => $summary,
            'recipientNotified' => $recipientNotified,
        ];
    }

    /**
     * Builds and persists every buffered row from queueEntityChange() and queueActivityLogEntry(),
     * then flushes once. Id resolution and summary text are both finalized here, not at queue
     * time — this is the first safe point to re-check a freshly-inserted entity's now-assigned id.
     */
    public function flushQueued(): void
    {
        if ($this->queued === [] && $this->queuedActivityLog === []) {
            return;
        }

        foreach ($this->queued as $item) {
            $entityId = $item['entityId'] ?? $this->resolveIdNow($item['entity']);

            $summary = match (true) {
                $item['label'] !== null && $entityId !== null => sprintf("%s '%s' #%d %s.", $item['entityType'], $item['label'], $entityId, $item['action']),
                $item['label'] !== null => sprintf("%s '%s' %s.", $item['entityType'], $item['label'], $item['action']),
                $entityId !== null => sprintf('%s #%d %s.', $item['entityType'], $entityId, $item['action']),
                default => sprintf('%s %s.', $item['entityType'], $item['action']),
            };

            $log = (new AuditLog())
                ->setIpAddress($this->clientIp())
                ->setActorType($item['actorType'])
                ->setActorId($item['actorId'])
                ->setActorName($item['actorName'])
                ->setArea('System')
                ->setEntityType($item['entityType'])
                ->setEntityId($entityId)
                ->setAction($item['action'])
                ->setSummary($summary)
                ->setDataBefore($item['dataBefore'] !== null ? json_encode($this->bounded($item['dataBefore'])) : null)
                ->setDataAfter($item['dataAfter'] !== null ? json_encode($this->bounded($item['dataAfter'])) : null);

            $this->entityManager->persist($log);
        }

        foreach ($this->queuedActivityLog as $item) {
            $log = (new AuditLog())
                ->setIpAddress($this->clientIp())
                ->setActorType('document')
                ->setActorName($item['actorName'])
                ->setArea('System')
                ->setEntityType($item['entityType'])
                ->setEntityId($this->resolveIdNow($item['entity']))
                ->setAction($item['action'])
                ->setSummary($item['summary'])
                ->setRecipientNotified($item['recipientNotified']);

            $this->entityManager->persist($log);
        }

        $this->queued = [];
        $this->queuedActivityLog = [];

        $this->entityManager->flush();
    }

    /**
     * Truncates any string value over MAX_VALUE_LENGTH, noting its true length — the record still
     * answers "what changed" without carrying the whole payload. Non-string values (numbers,
     * booleans, the REDACTED placeholder, already-short strings) pass through untouched.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function bounded(array $data): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                $data[$field] = sprintf(
                    '%s... [truncated, %d characters total]',
                    substr($value, 0, self::MAX_VALUE_LENGTH),
                    strlen($value),
                );
            }
        }

        return $data;
    }

    private function resolveIdNow(?object $entity): ?int
    {
        if ($entity === null || !method_exists($entity, 'getId')) {
            return null;
        }

        $id = $entity->getId();

        return is_int($id) ? $id : null;
    }

    /**
     * Delegates to DocumentActorResolver (#539 stage 2), which is the same resolution this method
     * used to perform inline. It moved out because the named document actions need the identity too
     * and an entity cannot reach the security context, so it had to become something a caller can
     * hold and pass; the audit log keeps the [type, id, name] triple it has always written.
     *
     * @return array{0: string, 1: ?int, 2: string}
     */
    private function resolveActor(): array
    {
        $actor = $this->actorResolver->resolve();

        return [$actor->type, $actor->id, $actor->auditName];
    }
}
