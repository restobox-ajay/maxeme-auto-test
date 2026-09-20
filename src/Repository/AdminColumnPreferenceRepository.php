<?php

namespace App\Repository;

use App\Entity\AdminColumnPreference;
use App\Entity\AdminUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminColumnPreference>
 */
final class AdminColumnPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminColumnPreference::class);
    }

    public function findForAdmin(AdminUser $admin, string $viewKey): ?AdminColumnPreference
    {
        return $this->findOneBy(['admin' => $admin, 'viewKey' => $viewKey]);
    }

    /** The Tech-Support-set site-wide default (admin_id IS NULL), if any. */
    public function findGlobal(string $viewKey): ?AdminColumnPreference
    {
        return $this->findOneBy(['admin' => null, 'viewKey' => $viewKey]);
    }

    /**
     * The columns this admin should see: their own selection, else the site-wide default, else null
     * (meaning "no preference — show everything").
     *
     * @return list<string>|null
     */
    public function resolveVisibleColumns(AdminUser $admin, string $viewKey): ?array
    {
        $pref = $this->findForAdmin($admin, $viewKey) ?? $this->findGlobal($viewKey);

        return $pref?->getColumns();
    }
}
