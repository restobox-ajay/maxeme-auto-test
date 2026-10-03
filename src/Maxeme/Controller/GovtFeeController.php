<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\AbstractChargeData;
use App\Maxeme\Dto\GovtFeeData;
use App\Maxeme\Entity\AbstractCharge;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Repository\AbstractChargeRepository;
use App\Maxeme\Repository\GovtFeeRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Config › Government Fees: fees added to invoices by hand, or by a GovtFeeRule (name, code, $,
 * description, active, tax class).
 *
 * @extends AbstractChargeController<GovtFee>
 */
#[Route('/admin/government-fees', name: 'maxeme_govt_fee_')]
final class GovtFeeController extends AbstractChargeController
{
    public function __construct(RecordWriter $records, EntityManagerInterface $entityManager, private readonly GovtFeeRepository $fees)
    {
        parent::__construct($records, $entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function index(Request $request): Response
    {
        return $this->renderList($request);
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        return $this->exportList($request, $activity, $timezone, 'government-fees', 'GovtFee');
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function createFee(Request $request): RedirectResponse
    {
        return $this->create($request);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function updateFee(#[MapEntity] GovtFee $fee, Request $request): RedirectResponse
    {
        return $this->update($fee, $request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function deleteFee(#[MapEntity] GovtFee $fee): JsonResponse
    {
        return $this->remove($fee);
    }

    protected function repository(): AbstractChargeRepository { return $this->fees; }
    protected function newCharge(): AbstractCharge { return new GovtFee(); }
    protected function formData(Request $request): AbstractChargeData { return GovtFeeData::fromRequest($request); }
    protected function routePrefix(): string { return 'maxeme_govt_fee_'; }
    protected function template(): string { return 'maxeme/govt_fee/index.html.twig'; }
}
