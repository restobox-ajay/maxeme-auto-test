<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Enum\PaymentMethod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
final class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    public function findOneByKey(string $invoiceKey): ?Invoice
    {
        return $this->findOneBy(['invoiceKey' => $invoiceKey]);
    }

    public function findOneByAppointment(Appointment $appointment): ?Invoice
    {
        return $this->findOneBy(['appointment' => $appointment]);
    }

    /** The sidebar "Find invoice": the invoice number as shown, leading zeros optional (legacy searchAction). */
    public function findOneByNumber(string $number): ?Invoice
    {
        $number = ltrim(trim($number), '0');

        return ctype_digit($number) ? $this->find((int) $number) : null;
    }

    /**
     * Invoice keys of the given appointments, for their Invoice / Work Order links.
     *
     * @param list<Appointment> $appointments
     *
     * @return array<int, string> appointment id => invoice key
     */
    public function keysByAppointment(array $appointments): array
    {
        if ($appointments === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.appointment) AS appointment', 'i.invoiceKey AS invoiceKey')
            ->andWhere('i.appointment IN (:appointments)')
            ->setParameter('appointments', $appointments)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'invoiceKey', 'appointment');
    }

    /**
     * The Summary Report's invoices: dated (lastModified) within [$from, $to] (UTC), with their
     * part lines for the material cost, in id order as the legacy report listed them.
     *
     * @return list<Invoice>
     */
    public function datedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, ?PaymentMethod $method = null): array
    {
        $query = $this->createQueryBuilder('i')
            ->addSelect('p')
            ->leftJoin('i.partLines', 'p')
            ->andWhere('i.lastModified >= :from AND i.lastModified <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.id', 'ASC');

        if ($method !== null) {
            $query->andWhere('i.paymentMethod = :method')->setParameter('method', $method);
        }

        return $query->getQuery()->getResult();
    }
}
