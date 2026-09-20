<?php

namespace App\Repository;

use App\Entity\CustomerUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<CustomerUser>
 */
class CustomerUserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerUser::class);
    }

    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        $email = trim(strtolower($identifier));
        if ($email === '') {
            return null;
        }

        return $this->createQueryBuilder('user')
            ->leftJoin('user.company', 'company')
            ->addSelect('company')
            ->andWhere('LOWER(user.email) = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByEmailInsensitive(string $email): ?CustomerUser
    {
        $normalizedEmail = trim(strtolower($email));
        if ($normalizedEmail === '') {
            return null;
        }

        $result = $this->createQueryBuilder('user')
            ->leftJoin('user.company', 'company')
            ->addSelect('company')
            ->andWhere('LOWER(user.email) = :email')
            ->setParameter('email', $normalizedEmail)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof CustomerUser ? $result : null;
    }
}
