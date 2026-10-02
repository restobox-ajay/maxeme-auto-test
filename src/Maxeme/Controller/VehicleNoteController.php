<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Vehicle page › Notes: add, edit and delete. */
#[Route('/admin/vehicles/{id}/notes', name: 'maxeme_vehicle_note_', requirements: ['id' => '\d+'])]
final class VehicleNoteController extends AbstractNoteController
{
    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function create(#[MapEntity] Vehicle $vehicle, Request $request): RedirectResponse
    {
        $this->addNote($vehicle, $request);

        return $this->toVehicle($vehicle);
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function update(#[MapEntity] Vehicle $vehicle, Request $request): RedirectResponse
    {
        $this->updateNote($vehicle, $request);

        return $this->toVehicle($vehicle);
    }

    #[Route('/{noteId}/delete', name: 'delete', requirements: ['noteId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::CAR_EDIT)]
    public function delete(#[MapEntity] Vehicle $vehicle, int $noteId): JsonResponse
    {
        return $this->deleteNote($vehicle, $noteId);
    }

    private function toVehicle(Vehicle $vehicle): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_vehicle_show', ['id' => $vehicle->getId(), '_fragment' => 'notes-card']);
    }
}
