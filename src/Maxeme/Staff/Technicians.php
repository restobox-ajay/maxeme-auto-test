<?php

declare(strict_types=1);

namespace App\Maxeme\Staff;

use App\Entity\AdminUser;
use App\Maxeme\Security\StaffRole;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The technicians to pick from (mockup 858860a): active staff users holding the Technician role,
 * by name. Settings › Technicians is gone; a technician is added under Staff.
 */
final class Technicians
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<AdminUser> */
    public function active(): array
    {
        $users = $this->entityManager->getRepository(AdminUser::class)->createQueryBuilder('u')
            ->andWhere('u.status = :active')->setParameter('active', 'Active')
            ->andWhere('u.roles LIKE :role')->setParameter('role', '%"' . StaffRole::Technician->value . '"%')
            ->getQuery()
            ->getResult();
        usort($users, static fn (AdminUser $a, AdminUser $b): int => strcasecmp($a->getName(), $b->getName()));

        return $users;
    }

    /**
     * The choices for a technician field: the active technicians, plus $current when it is set and no
     * longer one of them (shown "(no longer an active technician)", so a save never blanks it).
     *
     * @return list<array{user: AdminUser, stale: bool}>
     */
    public function choices(?AdminUser $current = null): array
    {
        $active = $this->active();
        $choices = array_map(static fn (AdminUser $user): array => ['user' => $user, 'stale' => false], $active);
        if ($current !== null && !in_array($current, $active, true)) {
            $choices[] = ['user' => $current, 'stale' => true];
        }

        return $choices;
    }

    /**
     * The posted technician: one of the active technicians, or the one already stored ($current).
     *
     * @throws \DomainException when it is neither
     */
    public function pick(?string $id, ?AdminUser $current = null): ?AdminUser
    {
        if ($id === null || $id === '') {
            return null;
        }
        if ($current !== null && (string) $current->getId() === $id) {
            return $current;
        }
        foreach ($this->active() as $user) {
            if ((string) $user->getId() === $id) {
                return $user;
            }
        }
        throw new \DomainException('Choose a technician from the list.');
    }
}
