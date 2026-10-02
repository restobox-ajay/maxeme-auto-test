<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Entity\HasNotes;
use App\Maxeme\Service\NoteBook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Add, edit and delete a record's notes (templates/maxeme/_notes.html.twig). Add and edit post the
 * note modal's `note` (and `id` for an edit), as core's company notes do. A subclass routes its
 * record's actions here and says where to go back to.
 */
abstract class AbstractNoteController extends AbstractMaxemeController
{
    public function __construct(
        protected readonly NoteBook $notes,
    ) {
    }

    protected function addNote(HasNotes $owner, Request $request): void
    {
        $message = self::message($request);
        if (($problem = NoteBook::problemWith($message)) !== null) {
            $this->addFlash('error', $problem);

            return;
        }
        $this->notes->add($owner, $message);
        $this->addFlash('success', 'Note added.');
    }

    protected function updateNote(HasNotes $owner, Request $request): void
    {
        $note = $this->notes->find($owner, $request->request->getInt('id')) ?? throw new NotFoundHttpException('This note is not on the record.');
        $message = self::message($request);
        if (($problem = NoteBook::problemWith($message)) !== null) {
            $this->addFlash('error', $problem);

            return;
        }
        $this->notes->update($note, $message);
        $this->addFlash('success', 'Note saved.');
    }

    protected function deleteNote(HasNotes $owner, int $noteId): JsonResponse
    {
        $note = $this->notes->find($owner, $noteId) ?? throw new NotFoundHttpException('This note is not on the record.');
        $this->notes->remove($owner, $note);

        return $this->json(['message' => 'Note deleted.']);
    }

    private static function message(Request $request): string
    {
        return trim((string) $request->request->get('note', ''));
    }
}
