<?php

declare(strict_types=1);

namespace App\Maxeme\Repository;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Listing\ListPage;
use App\Maxeme\Listing\ListQuery;
use App\Maxeme\Listing\SearchTerm;
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

    /** @return list<Invoice> a repair order's invoices, newest first */
    public function findForRepairOrder(RepairOrder $repairOrder): array
    {
        return $this->findBy(['repairOrder' => $repairOrder], ['id' => 'DESC']);
    }

    /** The invoice "Go to Invoice" opens: the newest not cancelled, else the newest. */
    public function findCurrentForRepairOrder(RepairOrder $repairOrder): ?Invoice
    {
        $invoices = $this->findForRepairOrder($repairOrder);
        foreach ($invoices as $invoice) {
            if ($invoice->getStatus() !== InvoiceStatus::Cancelled) {
                return $invoice;
            }
        }

        return $invoices[0] ?? null;
    }

    public function findOneByAppointment(Appointment $appointment): ?Invoice
    {
        return $this->findOneBy(['appointment' => $appointment]);
    }

    /** Invoices list sort key => column (first = default sort). */
    public const LIST_SORTS = [
        'id' => 'i.id',
        'createdOn' => 'i.createdOn',
        'client' => 'i.clientLastName',
        'vehicle' => 'i.vehicleManufacturer',
        'status' => 'i.status',
        'totalPrice' => 'i.totalPrice',
    ];

    /** The sidebar Invoice # box: the invoice number as shown, leading zeros and "#" optional. */
    public function findOneByNumber(SearchTerm $find): ?Invoice
    {
        $number = $find->invoiceNumber();

        return $number !== null ? $this->find($number) : null;
    }

    /**
     * @return ListPage<Invoice> invoices whose number starts with the sidebar Invoice # box's digits
     *                           (all of them when it is empty), and the grid's search box
     */
    public function findPage(SearchTerm $find, ListQuery $list): ListPage
    {
        $query = $this->createQueryBuilder('i');
        if (!$find->isEmpty()) {
            $number = $find->invoiceNumber();
            $query->andWhere('i.id LIKE :number')->setParameter('number', $number !== null ? $number . '%' : '-');
        }

        return ListPage::paginate($query, $list, self::LIST_SORTS, ['i.clientFirstName', 'i.clientLastName', 'i.vehicleManufacturer', 'i.vehicleModel', 'i.vehicleLicense']);
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
    public function datedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, ?PaymentType $method = null): array
    {
        $query = $this->createQueryBuilder('i')
            ->addSelect('p', 't')
            ->leftJoin('i.partLines', 'p')
            ->leftJoin('i.paymentType', 't')
            ->andWhere('i.lastModified >= :from AND i.lastModified <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.id', 'ASC');

        if ($method !== null) {
            $query->andWhere('i.paymentType = :method')->setParameter('method', $method);
        }

        return $query->getQuery()->getResult();
    }
}
