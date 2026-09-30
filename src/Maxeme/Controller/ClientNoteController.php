<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\Client;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Service\NoteBook;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Client Profile › Notes: add, edit and delete (templates/maxeme/_notes.html.twig). Add and edit
 * post the note modal's `note` (and `id` for an edit), as core's company notes do.
 */
#[Route('/admin/clients/{id}/notes', name: 'maxeme_client_note_', requirements: ['id' => '\d+'])]
final class ClientNoteController extends AbstractMaxemeController
{
    public function __construct(
        private readonly NoteBook $notes,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function create(#[MapEntity] Client $client, Request $request): RedirectResponse
    {
        $message = $this->message($request);
        if (($problem = NoteBook::problemWith($message)) !== null) {
            $this->addFlash('error', $problem);
        } else {
            $this->notes->add($client, $message);
            $this->addFlash('success', 'Note added.');
        }

        return $this->toProfile($client);
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function update(#[MapEntity] Client $client, Request $request): RedirectResponse
    {
        $note = $this->notes->find($client, $request->request->getInt('id')) ?? throw new NotFoundHttpException('This note is not on the client.');
        $message = $this->message($request);
        if (($problem = NoteBook::problemWith($message)) !== null) {
            $this->addFlash('error', $problem);
        } else {
            $this->notes->update($note, $message);
            $this->addFlash('success', 'Note saved.');
        }

        return $this->toProfile($client);
    }

    #[Route('/{noteId}/delete', name: 'delete', requirements: ['noteId' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::PEOPLE_EDIT)]
    public function delete(#[MapEntity] Client $client, int $noteId): JsonResponse
    {
        $note = $this->notes->find($client, $noteId) ?? throw new NotFoundHttpException('This note is not on the client.');
        $this->notes->remove($client, $note);

        return $this->json(['message' => 'Note deleted.']);
    }

    private function message(Request $request): string
    {
        return trim((string) $request->request->get('note', ''));
    }

    private function toProfile(Client $client): RedirectResponse
    {
        return $this->redirectToRoute('maxeme_client_show', ['id' => $client->getId()]);
    }
}
