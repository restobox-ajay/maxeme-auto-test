<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Dto\FormData;
use App\Maxeme\Dto\TechnicianData;
use App\Maxeme\Entity\Technician;
use App\Maxeme\Repository\TechnicianRepository;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\RecordWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Config › Settings › Technicians: the names offered in the master technician field.
 *
 * @extends AbstractSettingsListController<Technician>
 */
#[Route('/admin/shop-settings/technicians', name: 'maxeme_settings_technician_')]
final class TechnicianController extends AbstractSettingsListController
{
    public function __construct(RecordWriter $records, EntityManagerInterface $entityManager, private readonly TechnicianRepository $technicians)
    {
        parent::__construct($records, $entityManager);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function index(): Response
    {
        return $this->renderList();
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function createTechnician(Request $request): RedirectResponse
    {
        return $this->create($request);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function updateTechnician(#[MapEntity] Technician $technician, Request $request): RedirectResponse
    {
        return $this->update($technician, $request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::SETTINGS)]
    public function deleteTechnician(#[MapEntity] Technician $technician): JsonResponse
    {
        return $this->remove($technician);
    }

    protected function all(): array { return $this->technicians->findAllOrdered(); }
    protected function newRecord(): object { return new Technician(); }
    protected function formData(Request $request): FormData { return TechnicianData::fromRequest($request); }
    protected function routePrefix(): string { return 'maxeme_settings_technician_'; }
    protected function template(): string { return 'maxeme/settings/technicians.html.twig'; }

    /** @param TechnicianData $data */
    protected function check(object $record, FormData $data): array
    {
        $taken = $this->technicians->findOneByName((string) $data->name);

        return $taken !== null && $taken !== $record
            ? ['name' => sprintf('There is already a technician named %s.', $taken->getName())]
            : [];
    }

    /** Nothing refers to a technician yet (the master technician field is not built). */
    protected function inUse(object $record): ?string
    {
        return null;
    }
}
