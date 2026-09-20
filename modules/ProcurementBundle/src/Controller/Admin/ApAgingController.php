<?php

declare(strict_types=1);

namespace ProcurementBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\DocumentActorResolver;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Reporting\ApAgingReport;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounts payable, aged — what we owe, to whom, and how late it is.
 *
 * A read-only report and deliberately nothing more: no actions, no forms that write, no state. It
 * sits beside the exception screens because it answers the other half of the same question — those
 * say which bills are wrong, this says which are old.
 *
 * The arithmetic is all in `ApAgingReport`, including why a bill with no due date ages from its own
 * date and why Draft and Void are excluded. The one thing worth repeating here is where the money
 * comes from: `VendorBill::getBalance()`, the same figure the bill screen prints, which is the
 * total less the sum of that bill's payment rows. A report that re-derived it would be a second
 * answer able to disagree with the document it is reporting on.
 */
#[Route('/admin/bundles/procurement/ap-aging')]
final class ApAgingController extends AbstractProcurementController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        DocumentActorResolver $actors,
        private readonly ApAgingReport $report,
    ) {
        parent::__construct($em, $bundleStatusRepo, $actors);
    }

    #[Route('', name: 'admin_bundle_procurement_bill_aging', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['vendor', 'asOf']);

        // As of today unless somebody asks otherwise. A date in the URL rather than a form that
        // posts, because this screen changes nothing and a GET is reproducible: the same link shows
        // the same report to the next person who opens it.
        $asOf = $this->calendarDate($filters['asOf']) ?? (new \DateTimeImmutable())->format('Y-m-d');
        $today = \DateTimeImmutable::createFromFormat('Y-m-d', $asOf) ?: new \DateTimeImmutable();

        $report = $this->report->build($today, (int) ($filters['vendor'] !== '' ? $filters['vendor'] : 0));

        return $this->render('@Procurement/ap_aging.html.twig', [
            'buckets' => ApAgingReport::BUCKETS,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'bills' => $report['bills'],
            'vendors' => $this->activeVendors(),
            'filters' => ['vendor' => $filters['vendor'], 'asOf' => $asOf],
        ]);
    }
}
