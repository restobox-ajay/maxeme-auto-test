<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\Client;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Client Profile › Notes: add, edit and delete. */
#[Route('/admin/clients/{id}/notes', name: 'maxeme_client_note_', requirements: ['id' => '\d+'])]
final class ClientNoteController extends AbstractNoteController
{
    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function create(#[MapEntity] Client $client, Request $request): RedirectResponse
    {
        $this->addNote($client, $request);

        return $this->toProfile($client);
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function update(#[MapEntity] Client $client, Request $request): RedirectResponse
    {
        $this->updateNote($client, $request);

        return $this->toProfile($client);
    }

    #[Route('/{noteId}/delete', name: 'delete', requirements: ['noteId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function delete(#[MapEntity] Client $client, int $noteId): JsonResponse
    {
        return $this->deleteNote($client, $noteId);
    }

    private function toProfile(Client $client): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId()]);
    }
}
