<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomFieldDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomFieldDefinition>
 */
class CustomFieldDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomFieldDefinition::class);
    }

    /** @return CustomFieldDefinition[] */
    public function findByObjectType(string $objectType): array
    {
        return $this->findBy(['objectType' => $objectType], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    public function findBySlug(string $objectType, string $slug): ?CustomFieldDefinition
    {
        return $this->findOneBy(['objectType' => $objectType, 'slug' => $slug]);
    }

    /**
     * The rows behind the definition grid: every definition of an admin-facing object type,
     * narrowed by whatever the filter row carries.
     *
     * `$objectTypes` is the caller's list of admin-facing types rather than "everything mapped",
     * and that is the screen's existing behaviour kept rather than a new restriction:
     * `CustomFieldController::OBJECT_TYPES` is the admin-facing half of the feature, and a type
     * absent from it (`company_address`, `product_category`, both registered by bundles) has never
     * been shown on, creatable from, or editable through that screen. Widening what the grid shows
     * is a separate decision from what shape the grid takes.
     *
     * The ordering is the one the grouped page had inside each of its five tables — `sortOrder`
     * then `id`, which is the order the fields render in on the object itself — with `objectType`
     * ahead of it so that the one table still reads as the five groups it replaced.
     *
     * @param list<string> $objectTypes
     * @param array{objectType: string, label: string, slug: string, fieldType: string, source: string} $filters
     *
     * @return CustomFieldDefinition[]
     */
    public function searchForAdmin(array $objectTypes, array $filters): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.objectType IN (:objectTypes)')
            ->setParameter('objectTypes', $objectTypes)
            ->orderBy('d.objectType', 'ASC')
            ->addOrderBy('d.sortOrder', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if ($filters['objectType'] !== '') {
            $qb->andWhere('d.objectType = :objectType')->setParameter('objectType', $filters['objectType']);
        }

        if ($filters['fieldType'] !== '') {
            $qb->andWhere('d.fieldType = :fieldType')->setParameter('fieldType', $filters['fieldType']);
        }

        if ($filters['label'] !== '') {
            $qb->andWhere('LOWER(d.label) LIKE :label')->setParameter('label', '%' . mb_strtolower($filters['label']) . '%');
        }

        if ($filters['slug'] !== '') {
            $qb->andWhere('LOWER(d.slug) LIKE :slug')->setParameter('slug', '%' . mb_strtolower($filters['slug']) . '%');
        }

        // "Admin" is what the Source column PRINTS for a definition with no source — the column has
        // no blank cell — so the filter has to accept the word the reader can see. Anything else is
        // matched against the stored bundle name.
        if ($filters['source'] === 'admin') {
            $qb->andWhere('d.source IS NULL');
        } elseif ($filters['source'] !== '') {
            $qb->andWhere('LOWER(d.source) LIKE :source')->setParameter('source', '%' . mb_strtolower($filters['source']) . '%');
        }

        /** @var CustomFieldDefinition[] $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * How many definitions each admin-facing object type has, including the types that have none.
     *
     * The grouped page said "no custom fields defined for orders yet" by rendering an empty table
     * per type. The grid says it in the filter bar instead, which needs a count for every type the
     * screen offers and not only for the types that happen to have a row — hence the zero-filled
     * seed rather than the GROUP BY alone.
     *
     * @param list<string> $objectTypes
     *
     * @return array<string, int>
     */
    public function countByObjectType(array $objectTypes): array
    {
        $counts = array_fill_keys($objectTypes, 0);

        /** @var list<array{objectType: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.objectType AS objectType', 'COUNT(d.id) AS total')
            ->andWhere('d.objectType IN (:objectTypes)')
            ->setParameter('objectTypes', $objectTypes)
            ->groupBy('d.objectType')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $counts[$row['objectType']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Lazy upsert: finds by objectType+slug; if missing, creates from $seedData and returns it.
     *
     * @param array{label: string, fieldType?: string, options?: string[], visibleOnAdd?: bool, visibleOnEdit?: bool, visibleOnListing?: bool, searchable?: bool, source?: string, sortOrder?: int} $seedData
     */
    public function ensureBySlug(string $objectType, string $slug, array $seedData): CustomFieldDefinition
    {
        $definition = $this->findBySlug($objectType, $slug);
        if ($definition !== null) {
            return $definition;
        }

        $definition = (new CustomFieldDefinition())
            ->setObjectType($objectType)
            ->setSlug($slug)
            ->setLabel($seedData['label'])
            ->setFieldType($seedData['fieldType'] ?? CustomFieldDefinition::FIELD_TYPE_TEXT)
            ->setOptions($seedData['options'] ?? null)
            ->setVisibleOnAdd($seedData['visibleOnAdd'] ?? true)
            ->setVisibleOnEdit($seedData['visibleOnEdit'] ?? true)
            ->setVisibleOnListing($seedData['visibleOnListing'] ?? false)
            ->setSearchable($seedData['searchable'] ?? false)
            ->setSource($seedData['source'] ?? null)
            ->setSortOrder($seedData['sortOrder'] ?? 0);

        $em = $this->getEntityManager();
        $em->persist($definition);
        $em->flush();

        return $definition;
    }
}
