<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Dto\FormData;
use App\Maxeme\Entity\SoftDeletable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Validates, saves and soft-deletes the Maxeme records edited through a FormData form. */
final class RecordWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** @return array<string, string> property => message */
    public function validate(object $data): array
    {
        return FieldErrors::from($this->validator->validate($data));
    }

    /** Applies $data (already validated) to $entity and saves it, new or not. */
    public function save(object $entity, FormData $data): void
    {
        $data->applyTo($entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function delete(SoftDeletable $entity): void
    {
        $entity->deactivate();
        $this->entityManager->flush();
    }
}
