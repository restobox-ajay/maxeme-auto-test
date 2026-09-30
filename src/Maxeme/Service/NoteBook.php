<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\AbstractPartyNote;
use App\Entity\AdminUser;
use App\Maxeme\Entity\HasNotes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes the notes of any record that keeps them (HasNotes): the author is whoever is signed in,
 * the timestamp is when the note was written (an edit corrects the message, it does not re-date
 * it), and a message is required and at most AbstractPartyNote::MAX_LENGTH characters.
 */
final class NoteBook
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /** @return string|null why $message cannot be saved, or null when it can */
    public static function problemWith(string $message): ?string
    {
        return match (true) {
            $message === '' => 'Write the note first.',
            mb_strlen($message) > AbstractPartyNote::MAX_LENGTH => sprintf('A note can be at most %d characters.', AbstractPartyNote::MAX_LENGTH),
            default => null,
        };
    }

    public function add(HasNotes $owner, string $message): AbstractPartyNote
    {
        $note = $owner->newNote()->setUserName($this->author())->setText($message);
        $owner->getNotes()->add($note);
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    public function update(AbstractPartyNote $note, string $message): void
    {
        $note->setText($message);
        $this->entityManager->flush();
    }

    public function remove(HasNotes $owner, AbstractPartyNote $note): void
    {
        $owner->getNotes()->removeElement($note);
        $this->entityManager->remove($note);
        $this->entityManager->flush();
    }

    /** $owner's note with this id, or null when it has none (a note of another record included). */
    public function find(HasNotes $owner, int $id): ?AbstractPartyNote
    {
        foreach ($owner->getNotes() as $note) {
            if ($note->getId() === $id) {
                return $note;
            }
        }

        return null;
    }

    private function author(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof AdminUser) {
            return null;
        }
        $name = trim(sprintf('%s %s', $user->getFirstName(), $user->getLastName()));

        return $name !== '' ? $name : ($user->getUsername() ?: $user->getEmail());
    }
}
