<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiCredential;
use App\Entity\CustomerUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiCredential>
 */
class ApiCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiCredential::class);
    }

    public function findOneByUser(CustomerUser $user): ?ApiCredential
    {
        return $this->findOneBy(['customerUser' => $user]);
    }

    public function findOneByApiKey(string $apiKey): ?ApiCredential
    {
        if ($apiKey === '') {
            return null;
        }

        return $this->findOneBy(['apiKey' => $apiKey]);
    }

    public function apiKeyExists(string $apiKey): bool
    {
        return $this->count(['apiKey' => $apiKey]) > 0;
    }

    /**
     * Users in the company that currently hold an active key — for the "API Access" column on
     * /company-users, which shows the team at a glance without letting anyone operate a key that
     * is not their own.
     *
     * @param list<int> $userIds
     * @return array<int, bool> user id => holds an active key
     */
    public function activeKeyFlagsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<array{userId: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.customerUser) AS userId')
            ->andWhere('c.customerUser IN (:userIds)')
            ->andWhere('c.status = :active')
            ->setParameter('userIds', $userIds)
            ->setParameter('active', ApiCredential::STATUS_ACTIVE)
            ->getQuery()
            ->getArrayResult();

        $flags = [];
        foreach ($rows as $row) {
            $flags[(int) $row['userId']] = true;
        }

        return $flags;
    }
}
