<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Security\StaffRole;
use App\Repository\AdminUserRepository;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy FOSUserBundle `user` rows → AdminUser, matched by email (then username) so a re-run
 * updates instead of duplicating.
 *
 * The password hash and its salt are copied as they are; security.yaml's `maxeme_legacy` hasher
 * verifies them, and the first successful login rehashes the password. An account whose password
 * was changed here since the last import keeps its new password.
 */
final class UserImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function name(): string
    {
        return 'users';
    }

    public static function order(): int
    {
        return 10;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        $rows = $legacy->fetchAllAssociative('SELECT username, email, enabled, salt, password, roles, first_name, last_name FROM user ORDER BY id');

        foreach ($rows as $row) {
            $user = $this->users->loadUserByIdentifier((string) $row['email'])
                ?? $this->users->loadUserByIdentifier((string) $row['username']);
            $isNew = !$user instanceof AdminUser;

            if ($isNew) {
                // The role only on the way in: a re-run must not undo a role given in Manage Admins.
                $user = (new AdminUser())
                    ->setPassword((string) $row['password'])
                    ->setLegacySalt((string) $row['salt'])
                    ->setRoles([StaffRole::fromLegacyRoles($this->legacyRoles((string) $row['roles']))->value]);
                $this->entityManager->persist($user);
            } elseif ($user->getSalt() !== null) {
                // Not rehashed yet, so nobody has changed it here: follow the legacy password.
                $user->setPassword((string) $row['password'])->setLegacySalt((string) $row['salt']);
            }

            $user->setEmail((string) $row['email'])
                ->setUsername((string) $row['username'])
                ->setFirstName($row['first_name'] !== null ? (string) $row['first_name'] : null)
                ->setLastName($row['last_name'] !== null ? (string) $row['last_name'] : null);

            $status = (bool) $row['enabled'] ? AdminUserStatus::Active : AdminUserStatus::Inactive;
            if ($user->getStatus() !== $status->value) {
                $user->setStatus($status->value, DocumentActor::system(), 'Imported from the legacy Maxeme app.');
            }
        }

        $this->entityManager->flush();

        return count($rows);
    }

    /**
     * FOSUserBundle stores roles as a PHP-serialized array.
     *
     * @return list<string>
     */
    private function legacyRoles(string $serialized): array
    {
        $roles = @unserialize($serialized, ['allowed_classes' => false]);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }
}
