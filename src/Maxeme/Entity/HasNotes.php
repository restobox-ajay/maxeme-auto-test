<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyNote;
use Doctrine\Common\Collections\Collection;

/**
 * A record that keeps notes: author, message, timestamp, each one editable and deletable. Every
 * Maxeme notes list is one of these, written by App\Maxeme\Service\NoteBook and shown by
 * templates/maxeme/_notes.html.twig, so all notes look and behave the same.
 */
interface HasNotes
{
    /** @return Collection<int, AbstractPartyNote> newest first */
    public function getNotes(): Collection;

    /** A new, empty note belonging to this record (not persisted). */
    public function newNote(): AbstractPartyNote;
}
