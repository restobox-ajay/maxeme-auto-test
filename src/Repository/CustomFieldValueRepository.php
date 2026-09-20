<?php

declare(strict_types=1);

namespace App\Repository;

use App\Contract\CustomField\CustomFieldObjectType;
use App\Entity\AbstractCustomFieldValue;
use App\Entity\CustomFieldDefinition;
use App\Service\CustomField\CustomFieldObjectTypeCatalogue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Custom field values live in one physical table per object type (App\Entity\CustomFieldValue*),
 * each FK'd to its target entity, instead of one shared table keyed by a bare int. This repository
 * is the single place that maps an objectType string to the right entity class + association
 * property, so every caller (CustomFieldRenderer, bundle event subscribers, etc.) keeps using the
 * same objectType-agnostic API regardless of which table a value actually lives in.
 *
 * The mapping itself used to be a hardcoded `MAP` const here (seven entries, one per core object
 * type). #745 moved it to `CustomFieldObjectTypeCatalogue` so a bundle-owned type — Vendor was the
 * first — can join it without this class naming the bundle's entity class, which is the layering
 * violation "core reads nothing a bundle writes" exists to prevent.
 */
class CustomFieldValueRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomFieldDefinitionRepository $definitionRepo,
        private readonly CustomFieldObjectTypeCatalogue $objectTypes,
    ) {
    }

    public function getValue(CustomFieldDefinition $definition, int $objectId): ?string
    {
        $entity = $this->findOne($definition, $objectId);

        return $entity?->getValue();
    }

    public function setValue(CustomFieldDefinition $definition, int $objectId, ?string $value): void
    {
        $entity = $this->findOne($definition, $objectId);

        if ($entity === null) {
            $type = $this->typeOrThrow($definition->getObjectType());
            $entityClass = $type->valueEntityClass;
            $entity = (new $entityClass())
                ->setDefinition($definition)
                ->setObjectRef($this->em->getReference($type->targetEntityClass, $objectId));
            $this->em->persist($entity);
        }

        $entity->setValue($value);
    }

    /** @return array<string, string> slug => value */
    public function getValuesForObject(string $objectType, int $objectId): array
    {
        return $this->getValuesForObjects($objectType, [$objectId])[$objectId] ?? [];
    }

    /**
     * Batched form of getValuesForObject(), for grids that need many objects' values in one query instead of
     * one query per row. Caller is responsible for chunking $objectIds if the count could exceed the DB's
     * bound-parameter limit (see ProductController::PRICE_QUERY_CHUNK_SIZE for the existing convention).
     *
     * @param list<int> $objectIds
     * @return array<int, array<string, string>> objectId => (slug => value)
     */
    public function getValuesForObjects(string $objectType, array $objectIds): array
    {
        if ($objectIds === []) {
            return [];
        }

        $definitions = $this->definitionRepo->findByObjectType($objectType);
        if ($definitions === []) {
            return [];
        }

        $type = $this->typeOrThrow($objectType);

        $rows = $this->em->createQueryBuilder()
            ->select('v')
            ->from($type->valueEntityClass, 'v')
            ->andWhere('v.definition IN (:definitions)')
            ->andWhere("IDENTITY(v.{$type->associationProperty}) IN (:objectIds)")
            ->setParameter('definitions', $definitions)
            ->setParameter('objectIds', $objectIds)
            ->getQuery()
            ->getResult();

        $values = [];
        foreach ($rows as $row) {
            /** @var AbstractCustomFieldValue $row */
            $values[$row->getObjectId()][$row->getDefinition()->getSlug()] = $row->getValue() ?? '';
        }

        return $values;
    }

    private function findOne(CustomFieldDefinition $definition, int $objectId): ?AbstractCustomFieldValue
    {
        $type = $this->typeOrThrow($definition->getObjectType());

        /** @var AbstractCustomFieldValue|null */
        return $this->em->getRepository($type->valueEntityClass)->findOneBy(['definition' => $definition, $type->associationProperty => $objectId]);
    }

    /**
     * Fails loud rather than silently, on the same reasoning as
     * ProcurementDocumentPrefixProvider's `LABELS` guard (#615): a `CustomFieldDefinition` row can
     * only ever be created with an objectType a provider was registered for at the time
     * (ValidCustomFieldRequestValidator checks it against the catalogue's selectable set), so
     * reaching this with an unknown key means a bundle's provider was removed, or deactivated, while
     * its stored definitions and values were not — a state this repository should refuse to guess
     * about rather than silently create a value row nothing else can find.
     */
    private function typeOrThrow(string $objectType): CustomFieldObjectType
    {
        return $this->objectTypes->get($objectType) ?? throw new \LogicException(sprintf(
            'No CustomFieldObjectTypeProviderInterface registers object type "%s". A custom field'
            . ' definition or value row exists for it, but nothing declares how to store or find its'
            . ' values — check whether the owning bundle is Active and still registers this type.',
            $objectType,
        ));
    }
}
