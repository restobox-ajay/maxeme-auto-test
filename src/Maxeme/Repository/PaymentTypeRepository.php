<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\PaymentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentType>
 */
final class PaymentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentType::class);
    }

    /** @return list<PaymentType> every one, in list order (the settings list, the report's tabs) */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return list<PaymentType> the ones the invoice builder offers, in list order */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['position' => 'ASC', 'name' => 'ASC']);
    }

    public function findOneByName(string $name): ?PaymentType
    {
        return $this->findOneBy(['name' => trim($name)]);
    }

    /** The position a new one takes: after the last. */
    public function nextPosition(): int
    {
        return (int) $this->createQueryBuilder('p')->select('MAX(p.position)')->getQuery()->getSingleScalarResult() + 1;
    }

    public function countInvoices(PaymentType $type): int
    {
        return $this->getEntityManager()->getRepository(Invoice::class)->count(['paymentType' => $type]);
    }
}
