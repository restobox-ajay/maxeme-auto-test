<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;

/**
 * @extends ServiceEntityRepository<InventoryAdjustmentReason>
 */
class InventoryAdjustmentReasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryAdjustmentReason::class);
    }

    /**
     * The eight shipped reasons, created by code if they are missing.
     *
     * **No longer called from any read path.** `AdjustmentController::form()` used to open with it,
     * so the reasons the reason-first form is built out of were created by rendering that form.
     * InventoryDepthBundle\ReferenceData\InventoryAdjustmentReasonSeeder owns them now and creates
     * them once, on the first admin login.
     *
     * Kept for `SeedDepthVolumeCommand`, which generates demo data and needs the catalogue present
     * at that instant rather than at the next login. Do not reintroduce it into a controller.
     *
     * ## What it will and will not touch
     *
     * Matched on `code`, and **an existing row is returned exactly as it stands**. That is the
     * important half. These are configurable rows: an admin who has relabelled "Lost / shrinkage",
     * deactivated "Scrapped" or set a G/L account on "Damaged" must not have it silently put back
     * on the next request, which is what a find-or-UPDATE would do. The seed is a floor, not a
     * template.
     *
     * A code that was deleted outright does come back, and that is deliberate: the screen cannot
     * offer "Damaged" if the row is gone, and the alternative — a blank form — is worse than a
     * recreated default. Deactivating is the supported way to take one off the list, which is why
     * InventoryAdjustmentReason::$active exists.
     */
    public function ensureCatalogue(): void
    {
        $em = $this->getEntityManager();
        $created = false;

        foreach (InventoryAdjustmentReason::defaults() as $index => $default) {
            if ($this->findOneBy(['code' => $default['code']]) instanceof InventoryAdjustmentReason) {
                continue;
            }

            $em->persist(
                (new InventoryAdjustmentReason())
                    ->setCode($default['code'])
                    ->setLabel($default['label'])
                    ->setFromStatus($default['from'])
                    ->setToStatus($default['to'])
                    ->setReversal($default['reversal'])
                    ->setHelp($default['help'])
                    ->setSortKey(($index + 1) * 10)
                    ->setActive(true)
            );
            $created = true;
        }

        if ($created) {
            $em->flush();
        }
    }

    /**
     * What the form offers, in the order the entity's $sortKey docblock argues for.
     *
     * `code` is the tiebreak rather than `label`, because two rows sharing a sort key should still
     * come out in a stable order across requests — a list that reshuffles itself between renders is
     * how an operator clicks the wrong reason.
     *
     * @return list<InventoryAdjustmentReason>
     */
    public function activeInOrder(): array
    {
        /** @var list<InventoryAdjustmentReason> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.active = true')
            ->orderBy('r.sortKey', 'ASC')
            ->addOrderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * One reason by its code, **only if it is active**.
     *
     * The active test is here rather than at the call site on purpose. Deactivating a reason has to
     * take it off the form AND refuse a POST that names it, or "we have stopped recording that"
     * lasts exactly as long as nobody has the old page open — and the hand-rolled POST is the case
     * a dropdown can never stop. One lookup, one rule.
     */
    public function findActiveByCode(string $code): ?InventoryAdjustmentReason
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $reason = $this->findOneBy(['code' => $code, 'active' => true]);

        return $reason instanceof InventoryAdjustmentReason ? $reason : null;
    }
}
