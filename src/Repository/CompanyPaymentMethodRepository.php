<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\CompanyPaymentMethod;
use App\Entity\PaymentMethod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyPaymentMethod>
 */
class CompanyPaymentMethodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyPaymentMethod::class);
    }

    /**
     * A payment method is enabled for a company unless an admin has explicitly
     * turned it off — there's no opt-in grant step, only an opt-out override.
     *
     * @return PaymentMethod[]
     */
    public function findEnabledForCompany(Company $company): array
    {
        $disabledMethodIds = $this->createQueryBuilder('cpm')
            ->select('IDENTITY(cpm.paymentMethod)')
            ->andWhere('cpm.company = :company')
            ->andWhere('cpm.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', CompanyPaymentMethod::STATUS_INACTIVE)
            ->getQuery()
            ->getSingleColumnResult();

        $qb = $this->getEntityManager()->getRepository(PaymentMethod::class)->createQueryBuilder('pm');

        if ($disabledMethodIds !== []) {
            $qb->andWhere($qb->expr()->notIn('pm.id', ':disabled'))
                ->setParameter('disabled', $disabledMethodIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * All explicit grants for a company (both Active and Inactive) — unlike
     * findEnabledForCompany(), this is for the admin grid, which needs to show
     * and edit every row an admin has ever set, not just the opt-out-enabled ones.
     *
     * @return CompanyPaymentMethod[]
     */
    public function findAllForCompany(Company $company): array
    {
        return $this->createQueryBuilder('cpm')
            ->innerJoin('cpm.paymentMethod', 'pm')
            ->addSelect('pm')
            ->andWhere('cpm.company = :company')
            ->setParameter('company', $company)
            ->orderBy('pm.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
