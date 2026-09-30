<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maxeme Auto: the seven staff roles (App\Maxeme\Security\StaffRole) replace the three tiers.
 * Accounts move to the new role names: Admin ROLE_MANAGER => ROLE_SHOP_ADMIN, and the legacy
 * view-only Staff ROLE_STAFF => ROLE_RECEPTIONIST. Super Admin and Tech Support keep theirs.
 *
 * Data only. admin_user.roles is a JSON list, so the role name is replaced as a quoted string.
 */
final class Version20260930100000 extends AbstractMigration
{
    private const RENAMES = [
        'ROLE_MANAGER' => 'ROLE_SHOP_ADMIN',
        'ROLE_STAFF' => 'ROLE_RECEPTIONIST',
    ];

    public function getDescription(): string
    {
        return 'Maxeme: move staff accounts onto the seven-role names';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->rename($from, $to);
        }
    }

    public function down(Schema $schema): void
    {
        // The roles added since have no old tier: Secretary I / II read as Admin, Technician as Staff.
        foreach (['ROLE_SECRETARY_1' => 'ROLE_MANAGER', 'ROLE_SECRETARY_2' => 'ROLE_MANAGER', 'ROLE_TECHNICIAN' => 'ROLE_STAFF'] as $from => $to) {
            $this->rename($from, $to);
        }
        foreach (self::RENAMES as $from => $to) {
            $this->rename($to, $from);
        }
    }

    private function rename(string $from, string $to): void
    {
        $this->addSql('UPDATE admin_user SET roles = REPLACE(roles, :from, :to) WHERE roles LIKE :match', [
            'from' => sprintf('"%s"', $from),
            'to' => sprintf('"%s"', $to),
            'match' => sprintf('%%"%s"%%', $from),
        ]);
    }
}
