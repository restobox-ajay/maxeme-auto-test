<?php

namespace App\Controller\Admin;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\Estimate;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Enum\ProductStatus;
use App\Enum\SalesOrderStatus;
use App\Service\BusinessDate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class DashboardController extends AbstractAdminController
{
    #[Route('/dashboard/recent-orders', name: 'admin_dashboard_recent_orders', methods: ['GET'])]
    public function recentOrders(EntityManagerInterface $em, Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(500, $request->query->getInt('limit', 100)));
        $search = trim((string) $request->query->get('q', ''));
        $sort = trim((string) $request->query->get('sort', ''));
        $dir = strtolower(trim((string) $request->query->get('dir', 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $limit;

        // DQL rather than the raw SQL this used to be. The date column is now a field Doctrine
        // validates against the mapping, so renaming it is a startup failure here instead of a
        // query that runs and selects a column which no longer exists. These statements were the
        // only place in the codebase where the column name appeared in a string nothing checked,
        // which is why the #498 rename converted them rather than just editing them.
        $sortField = match ($sort) {
            'orderNumber' => 'o.orderNumber',
            'company' => 'c.name',
            'total' => 'o.total',
            'status' => 'o.status',
            'date' => 'o.documentDate',
            default => 'o.id',
        };

        $applySearch = static function (QueryBuilder $qb) use ($search): QueryBuilder {
            if ($search !== '') {
                $qb->where('o.orderNumber LIKE :q OR c.name LIKE :q')
                    ->setParameter('q', '%' . $search . '%');
            }

            return $qb;
        };

        $total = (int) $applySearch(
            $em->createQueryBuilder()
                ->select('COUNT(o.id)')
                ->from(SalesOrder::class, 'o')
                ->join('o.company', 'c')
        )->getQuery()->getSingleScalarResult();

        $rows = $applySearch(
            $em->createQueryBuilder()
                ->select('o.id', 'o.orderNumber', 'o.status', 'o.total', 'o.documentDate', 'c.name AS companyName')
                ->from(SalesOrder::class, 'o')
                ->join('o.company', 'c')
        )
            ->orderBy($sortField, $dir)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $pages = (int) max(1, (int) ceil($total / $limit));
        if ($page > $pages) {
            $page = $pages;
        }

        return $this->json([
            'html' => $this->renderView('admin/_main/_recent_order_rows.html.twig', ['recentOrders' => $rows]),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
        ]);
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    #[Route('/', name: 'admin_dashboard_slash', methods: ['GET'])]
    public function index(EntityManagerInterface $em, BusinessDate $businessDate): Response
    {
        $conn = $em->getConnection();

        // From the display timezone, not PHP's UTC default. These are compared against document_date,
        // which SalesDocumentDateStamp writes in that same zone — asking "how many orders today"
        // in a different zone from the one the orders were dated in counts the wrong day's orders
        // for as many hours as the two are apart, and gets "this month's revenue" wrong on the
        // first and last day of every month.
        $today     = $businessDate->today();
        $yearMonth = substr($today, 0, 7);

        // ── KPI tiles ────────────────────────────────────────────────────────
        // SUBSTRING, not the strftime() this used to call: document_date holds exactly ten
        // characters of 'Y-m-d', so its year-month IS its first seven characters — nothing has to
        // parse it as a date, and DQL can express it, which is what puts the field name back under
        // Doctrine's validation. strftime() was only ever here because the column used to be DATE.
        $revenueMonth = (float) $em->createQueryBuilder()
            ->select('COALESCE(SUM(o.total), 0)')
            ->from(SalesOrder::class, 'o')
            // Void joins Draft in being excluded: a voided order is out of reporting totals by
            // definition, which is the whole reason #539 stage 2 gave it a status of its own.
            ->where('o.status NOT IN (:excluded)')
            ->andWhere('SUBSTRING(o.documentDate, 1, 7) = :yearMonth')
            ->setParameter('excluded', ['Draft', 'Void'])
            ->setParameter('yearMonth', $yearMonth)
            ->getQuery()
            ->getSingleScalarResult();

        // An open order is one that is accepted but not finished with: approved, part-invoiced or
        // invoiced-and-awaiting-payment. Closed and Void are done, and a Draft was never opened.
        // #539 stage 2's successors to the old ('Pending', 'Processing') pair.
        $openOrders = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM sales_order
             WHERE status IN ('Approved', 'Partially Invoiced', 'Invoiced')"
        );

        $activeCompanies = $em->getRepository(Company::class)->count(['status' => 'Active']);

        // ── Secondary stats ──────────────────────────────────────────────────
        // Parameterised off ProductStatus rather than an embedded literal: this is the one product
        // count on the dashboard, a grep for the enum has to find it, and "Active" is now a
        // narrower thing than "not Inactive" — a Draft SKU is not one we have.
        $activeSkus = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM product_core WHERE status = ? AND deleted = 0',
            [ProductStatus::Active->value],
        );

        $totalUsers = $em->getRepository(CustomerUser::class)->count([]);

        $ordersToday = $em->getRepository(SalesOrder::class)->count(['documentDate' => $today]);
        $quotesToday = $em->getRepository(Estimate::class)->count(['documentDate' => $today]);

        // ── Recent orders (last 10) ──────────────────────────────────────────
        $recentOrders = $em->createQueryBuilder()
            ->select('o.id', 'o.orderNumber', 'o.status', 'o.total', 'o.documentDate', 'c.name AS companyName')
            ->from(SalesOrder::class, 'o')
            ->join('o.company', 'c')
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();

        // ── Order status breakdown ───────────────────────────────────────────
        // Legacy rows can still carry pre-cutover status strings (e.g. 'Waiting for Quote')
        // that are no longer offered anywhere in the UI — see SalesOrderStatus docblock.
        $validStatuses = array_map(static fn (SalesOrderStatus $s) => $s->value, SalesOrderStatus::cases());
        $statusRows = $conn->fetchAllAssociative(
            "SELECT status, COUNT(*) AS cnt FROM sales_order WHERE status IN (?) GROUP BY status ORDER BY cnt DESC",
            [$validStatuses],
            [ArrayParameterType::STRING]
        );
        $statusCounts = [];
        foreach ($statusRows as $row) {
            $statusCounts[$row['status']] = (int) $row['cnt'];
        }

        // ── Top 5 companies by revenue ───────────────────────────────────────
        $topCompanies = $conn->fetchAllAssociative(
            "SELECT c.id, c.name, COUNT(DISTINCT ao.id) AS order_count,
                    COALESCE(SUM(ao.total), 0) AS total_revenue
             FROM company c
             LEFT JOIN sales_order ao ON ao.company_id = c.id AND ao.status NOT IN ('Draft', 'Void')
             GROUP BY c.id, c.name
             ORDER BY total_revenue DESC
             LIMIT 5"
        );

        return $this->render('admin/_main/dashboard.html.twig', [
            'stats' => [
                [
                    'label'  => 'Revenue (MTD)',
                    'value'  => '$' . number_format($revenueMonth, 0),
                    'detail' => 'Month-to-date from non-draft orders',
                ],
                [
                    'label'  => 'Open Orders',
                    'value'  => number_format($openOrders),
                    'detail' => 'Approved, part-invoiced and invoiced',
                ],
                [
                    'label'  => 'Active Customers',
                    'value'  => number_format($activeCompanies),
                    'detail' => 'Customers with active account status',
                ],
            ],
            'secondaryStats' => [
                ['label' => 'Active SKUs',     'value' => number_format($activeSkus),  'icon' => 'product'],
                ['label' => 'Customer Users',  'value' => number_format($totalUsers),  'icon' => 'users'],
                ['label' => "Today's Orders",  'value' => number_format($ordersToday), 'icon' => 'orders'],
                ['label' => "Today's Quotes",  'value' => number_format($quotesToday), 'icon' => 'orders'],
            ],
            'recentOrders'  => $recentOrders,
            'statusCounts'  => $statusCounts,
            'topCompanies'  => $topCompanies,
            'sections'      => self::adminSections(),
        ]);
    }
}
