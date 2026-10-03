<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\AbstractChargeData;
use App\Maxeme\Dto\LabourData;
use App\Maxeme\Entity\AbstractCharge;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Enum\LabourUnit;
use App\Maxeme\Repository\AbstractChargeRepository;
use App\Maxeme\Repository\LabourRepository;
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
 * Config › Labour: labour rates (code, name, price per hour or each, sublet, active, tax class).
 *
 * @extends AbstractChargeController<Labour>
 */
#[Route('/admin/labour', name: 'maxeme_labour_')]
final class LabourController extends AbstractChargeController
{
    public function __construct(RecordWriter $records, EntityManagerInterface $entityManager, private readonly LabourRepository $labour)
    {
        parent::__construct($records, $entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function index(Request $request): Response
    {
        return $this->renderList($request, ['units' => LabourUnit::cases()]);
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    #[RequiresPermission(Permission::SERVICE_VIEW)]
    public function export(Request $request, ActivityRecorder $activity, #[Autowire(param: 'maxeme.timezone')] string $timezone): Response
    {
        return $this->exportList($request, $activity, $timezone, 'labour', 'Labour');
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function createLabour(Request $request): RedirectResponse
    {
        return $this->create($request);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function updateLabour(#[MapEntity] Labour $labour, Request $request): RedirectResponse
    {
        return $this->update($labour, $request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SERVICE_EDIT)]
    public function deleteLabour(#[MapEntity] Labour $labour): JsonResponse
    {
        return $this->remove($labour);
    }

    protected function repository(): AbstractChargeRepository { return $this->labour; }
    protected function newCharge(): AbstractCharge { return new Labour(); }
    protected function formData(Request $request): AbstractChargeData { return LabourData::fromRequest($request); }
    protected function routePrefix(): string { return 'maxeme_labour_'; }
    protected function template(): string { return 'maxeme/labour/index.html.twig'; }
}
