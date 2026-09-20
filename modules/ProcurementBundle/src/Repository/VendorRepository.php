<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorContact;

/**
 * @extends ServiceEntityRepository<Vendor>
 */
class VendorRepository extends ServiceEntityRepository
{
    /**
     * The filter keys the vendor list screen renders, in column order.
     *
     * Declared once and read by both the controller (which pulls them off the query string) and
     * search() below (which turns them into predicates), so a column cannot end up with an input
     * nothing reads or a predicate nothing renders — the two halves of what the UI audit filed as
     * "filters read with no input".
     *
     * @var list<string>
     */
    public const FILTER_KEYS = [
        'name',
        'email',
        'contact',
        'remitTo',
        'accountNumber',
        'paymentTerm',
        'currency',
        'status',
        'id',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    /** @return list<Vendor> */
    public function findActive(): array
    {
        /** @var list<Vendor> $rows */
        $rows = $this->findBy(['status' => Vendor::STATUS_ACTIVE], ['name' => 'ASC']);

        return $rows;
    }

    /**
     * The vendor list screen's query: one predicate per rendered column, all from GET params.
     *
     * Mirrors `CompanyController::index()` column for column where the two sides have the same
     * fact — name, primary email, primary contact, the money address, status and id — and adds the
     * three a vendor has and a customer does not: our account number with them, the payment term
     * and the currency.
     *
     * **Nothing here joins to a one-to-many for a filter.** Contacts and addresses are matched with
     * EXISTS subqueries, so a vendor with three contacts appears once in the rows and counts once in
     * the total; a JOIN would multiply both (#605). The only joins are the two the ORDER BY needs,
     * added after the count is taken and constrained to the at-most-one row each — the primary
     * contact and the remit-to address — which is the same device
     * `CompanyController::index()` uses for `billingAddress`.
     *
     * @param array<string, string> $filters
     *
     * @return array{rows: list<Vendor>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $qb = $this->createQueryBuilder('v');

        $this->like($qb, $filters, 'name', 'v.name');
        $this->like($qb, $filters, 'email', 'v.email');
        $this->like($qb, $filters, 'accountNumber', 'v.accountNumber');
        $this->like($qb, $filters, 'paymentTerm', 'v.paymentTerm');
        $this->like($qb, $filters, 'currency', 'v.currency');

        if (($filters['contact'] ?? '') !== '') {
            $qb->andWhere(
                'EXISTS (SELECT vc.id FROM ' . VendorContact::class . ' vc'
                . ' WHERE vc.vendor = v AND (vc.email LIKE :contact OR vc.firstName LIKE :contact'
                . ' OR vc.lastName LIKE :contact OR vc.phone LIKE :contact))'
            )->setParameter('contact', '%' . $filters['contact'] . '%');
        }

        if (($filters['remitTo'] ?? '') !== '') {
            $qb->andWhere(
                'EXISTS (SELECT va.id FROM ' . VendorAddress::class . ' va'
                . ' WHERE va.vendor = v AND va.isRemitTo = true AND (va.addressLine1 LIKE :remitTo'
                . ' OR va.city LIKE :remitTo OR va.province LIKE :remitTo OR va.country LIKE :remitTo'
                . ' OR va.postalCode LIKE :remitTo OR va.companyName LIKE :remitTo))'
            )->setParameter('remitTo', '%' . $filters['remitTo'] . '%');
        }

        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('v.status = :status')->setParameter('status', $filters['status']);
        }

        // An id box is an exact lookup, not a substring one — typing 12 must not also return 120.
        // A non-numeric id matches nothing rather than being ignored, because ignoring it would
        // silently return the whole table to somebody who mistyped.
        $id = trim($filters['id'] ?? '');
        if ($id !== '') {
            $qb->andWhere('v.id = :id')->setParameter('id', ctype_digit($id) ? (int) $id : -1);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(v.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Vendor> $rows */
        $rows = $this->ordered($qb, $sort, $dir)
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Each vendor's primary contact, keyed by `vendor.id`.
     *
     * ONE query for the whole page rather than a lazy `$vendor->getContacts()` per row: at the 500
     * rows the per-page control offers, touching the collection in the template is 500 extra
     * queries. Not fetch-joined into search() either, because a partially hydrated `contacts`
     * collection would then be handed to every other caller of that method.
     *
     * @param list<int> $vendorIds
     *
     * @return array<int, VendorContact>
     */
    public function primaryContactsFor(array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        /** @var list<VendorContact> $contacts */
        $contacts = $this->getEntityManager()->createQueryBuilder()
            ->select('c')
            ->from(VendorContact::class, 'c')
            ->andWhere('c.vendor IN (:ids)')->setParameter('ids', $vendorIds)
            ->andWhere('c.isPrimary = true')
            ->getQuery()
            ->getResult();

        $byVendor = [];
        foreach ($contacts as $contact) {
            $byVendor[(int) $contact->getVendor()->getId()] = $contact;
        }

        return $byVendor;
    }

    /**
     * Each vendor's remit-to address, keyed by `vendor.id`. One query, for the same reason.
     *
     * @param list<int> $vendorIds
     *
     * @return array<int, VendorAddress>
     */
    public function remitToAddressesFor(array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        /** @var list<VendorAddress> $addresses */
        $addresses = $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(VendorAddress::class, 'a')
            ->andWhere('a.vendor IN (:ids)')->setParameter('ids', $vendorIds)
            ->andWhere('a.isRemitTo = true')
            ->getQuery()
            ->getResult();

        $byVendor = [];
        foreach ($addresses as $address) {
            $byVendor[(int) $address->getVendor()->getId()] = $address;
        }

        return $byVendor;
    }

    /** @param array<string, string> $filters */
    private function like(QueryBuilder $qb, array $filters, string $key, string $field): void
    {
        $value = trim($filters[$key] ?? '');
        if ($value === '') {
            return;
        }

        $qb->andWhere($field . ' LIKE :f_' . $key)->setParameter('f_' . $key, '%' . $value . '%');
    }

    /**
     * ORDER BY for the row query only — never for the count, which is why the two joins below are
     * added here and not before the count is cloned.
     *
     * `WITH pc.isPrimary = true` and `WITH rt.isRemitTo = true` each match at most one row per
     * vendor: VendorController clears the flag off every other row whenever one claims it, for
     * exactly the reason a company has one default billing address. So neither join multiplies the
     * page.
     */
    private function ordered(QueryBuilder $qb, string $sort, string $dir): QueryBuilder
    {
        $scalar = [
            'name' => 'v.name',
            'email' => 'v.email',
            'accountNumber' => 'v.accountNumber',
            'paymentTerm' => 'v.paymentTerm',
            'currency' => 'v.currency',
            'status' => 'v.status',
            'id' => 'v.id',
        ];

        if (isset($scalar[$sort])) {
            return $qb->orderBy($scalar[$sort], $dir)->addOrderBy('v.id', 'ASC');
        }

        if ($sort === 'contact') {
            return $qb->leftJoin('v.contacts', 'pc', 'WITH', 'pc.isPrimary = true')
                ->orderBy('pc.lastName', $dir)
                ->addOrderBy('pc.firstName', $dir)
                ->addOrderBy('v.id', 'ASC');
        }

        if ($sort === 'remitTo') {
            return $qb->leftJoin('v.addresses', 'rt', 'WITH', 'rt.isRemitTo = true')
                ->orderBy('rt.addressLine1', $dir)
                ->addOrderBy('v.id', 'ASC');
        }

        return $qb->orderBy('v.name', $dir)->addOrderBy('v.id', 'ASC');
    }
}
