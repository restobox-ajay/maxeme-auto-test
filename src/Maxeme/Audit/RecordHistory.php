<?php

declare(strict_types=1);

namespace App\Maxeme\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;

/**
 * One record's history in the Activity Log (every Logs button): the record's own audit_log rows,
 * plus those of the records that belong to it, found from the Doctrine mapping rather than a
 * hand-kept list, so a new entity is covered the day it is added.
 *
 *  - its children's rows: every entity with a many-to-one / one-to-one onto the record's class
 *    (a Client's vehicles, notes, addresses and appointments; an Invoice's lines), by the ids that
 *    point at it now;
 *  - any row whose before / after snapshot names it ("client":"Client#4058"), which is how a
 *    child that has since been deleted, or moved to another parent, still shows.
 *
 * audit_log keeps the short class name only, so a name two namespaces share (core's Invoice and
 * the shop's) resolves to the shop's.
 */
final class RecordHistory
{
    private const SHOP_NAMESPACE = 'App\\Maxeme\\Entity\\';

    /** A child type with more rows than this under one record is matched by its snapshot only. */
    private const MAX_CHILD_IDS = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Restricts $qb (audit_log aliased $alias) to the record's history. */
    public function apply(QueryBuilder $qb, string $alias, string $type, int $id): void
    {
        $or = $qb->expr()->orX(sprintf('%1$s.entityType = :historyType AND %1$s.entityId = :historyId', $alias));
        $qb->setParameter('historyType', $type)->setParameter('historyId', $id);

        $class = $this->classFor($type);
        if ($class !== null) {
            foreach ($this->children($class, $id) as $index => [$childType, $childIds]) {
                $or->add(sprintf('%1$s.entityType = :childType%2$d AND %1$s.entityId IN (:childIds%2$d)', $alias, $index));
                $qb->setParameter('childType' . $index, $childType)->setParameter('childIds' . $index, $childIds);
            }
        }

        $or->add(sprintf('%1$s.dataBefore LIKE :historyRef OR %1$s.dataAfter LIKE :historyRef', $alias));
        $qb->setParameter('historyRef', '%' . json_encode($type . '#' . $id) . '%');

        $qb->andWhere($or);
    }

    /** @return class-string|null the mapped entity class audit_log calls $type */
    private function classFor(string $type): ?string
    {
        $found = null;
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->getReflectionClass()->getShortName() !== $type) {
                continue;
            }
            if (str_starts_with($metadata->getName(), self::SHOP_NAMESPACE)) {
                return $metadata->getName();
            }
            $found ??= $metadata->getName();
        }

        return $found;
    }

    /**
     * @param class-string $class
     *
     * @return list<array{0: string, 1: list<int>}> child short name => ids pointing at the record now
     */
    private function children(string $class, int $id): array
    {
        $children = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if ($mapping['targetEntity'] !== $class
                    || !($mapping['type'] & ClassMetadata::TO_ONE)
                    || !$metadata->isAssociationWithSingleJoinColumn($field)
                    || !in_array('id', $metadata->getIdentifierFieldNames(), true)) {
                    continue;
                }

                $ids = $this->entityManager->createQueryBuilder()
                    ->select('c.id')
                    ->from($metadata->getName(), 'c')
                    ->where(sprintf('IDENTITY(c.%s) = :id', $field))
                    ->setParameter('id', $id)
                    ->setMaxResults(self::MAX_CHILD_IDS)
                    ->getQuery()
                    ->getSingleColumnResult();

                if ($ids !== []) {
                    $children[] = [$metadata->getReflectionClass()->getShortName(), array_map('intval', $ids)];
                }
            }
        }

        return $children;
    }
}
