<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Repair order › Notes: add, edit and delete (admins and technicians: work order edit). */
#[Route('/admin/repair-orders/{id}/notes', name: 'maxeme_repair_order_note_', requirements: ['id' => '\d+'])]
final class RepairOrderNoteController extends AbstractNoteController
{
    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function create(#[MapEntity] RepairOrder $repairOrder, Request $request): RedirectResponse
    {
        $this->addNote($repairOrder, $request);

        return $this->toRepairOrder($repairOrder);
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function update(#[MapEntity] RepairOrder $repairOrder, Request $request): RedirectResponse
    {
        $this->updateNote($repairOrder, $request);

        return $this->toRepairOrder($repairOrder);
    }

    #[Route('/{noteId}/delete', name: 'delete', requirements: ['noteId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::WORK_ORDER_EDIT)]
    public function delete(#[MapEntity] RepairOrder $repairOrder, int $noteId): JsonResponse
    {
        return $this->deleteNote($repairOrder, $noteId);
    }

    private function toRepairOrder(RepairOrder $repairOrder): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_repair_order_edit', ['id' => $repairOrder->getId(), '_fragment' => 'notes-card']);
    }
}
