<?php

namespace App\Repository;

use App\Entity\AdminUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<AdminUser>
 */
class AdminUserRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminUser::class);
    }

    /** Signs in by email or by username, both case-insensitive. */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        $identifier = trim(strtolower($identifier));
        if ($identifier === '') {
            return null;
        }

        $result = $this->createQueryBuilder('user')
            ->andWhere('LOWER(user.email) = :identifier OR LOWER(user.username) = :identifier')
            ->setParameter('identifier', $identifier)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof AdminUser ? $result : null;
    }

    public function isUsernameTaken(string $username, ?AdminUser $except = null): bool
    {
        return $this->isTaken('username', $username, $except);
    }

    public function isEmailTaken(string $email, ?AdminUser $except = null): bool
    {
        return $this->isTaken('email', $email, $except);
    }

    /**
     * Called by Symfony's PasswordMigratingListener after a login that verified against a hash the
     * current hasher would not produce (e.g. an imported legacy sha512 one). The new hash is saltless,
     * so the legacy salt goes with the old hash.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AdminUser) {
            return;
        }

        $user->setPassword($newHashedPassword);
        $user->setLegacySalt(null);
        $this->getEntityManager()->flush();
    }

    private function isTaken(string $field, string $value, ?AdminUser $except): bool
    {
        $query = $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->andWhere(sprintf('LOWER(user.%s) = :value', $field))
            ->setParameter('value', trim(strtolower($value)));

        if ($except?->getId() !== null) {
            $query->andWhere('user.id != :id')->setParameter('id', $except->getId());
        }

        return (int) $query->getQuery()->getSingleScalarResult() > 0;
    }
}
