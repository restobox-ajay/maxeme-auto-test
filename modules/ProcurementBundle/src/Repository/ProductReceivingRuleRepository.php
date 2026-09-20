<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\ProductReceivingRule;

/**
 * @extends ServiceEntityRepository<ProductReceivingRule>
 */
class ProductReceivingRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReceivingRule::class);
    }

    /**
     * The rule for $product, or an unsaved one bound to it when there is no row.
     *
     * Never null, deliberately. Every caller null-checking it would be five copies of the same
     * default, one of which would eventually get it backwards and start requiring a lot for
     * everything. The returned object is **not persisted**: reading a policy must not create one.
     *
     * An unsaved rule is now a COMPLETE answer rather than an empty one (item 67). Its three
     * identity questions are derived from the product's tracking policy, so they answer correctly
     * for a product nobody ever made a row for — which is every product, because nothing in the
     * application ever created one. That is precisely the hole this used to open: ReceivingService's
     * four guards were correct and never fired, because they were asking a table nothing wrote to.
     * Only `location_required` is genuinely absent when the row is, and absent means false.
     */
    public function ruleFor(ProductCore $product): ProductReceivingRule
    {
        $rule = $this->findOneBy(['product' => $product]);

        return $rule ?? (new ProductReceivingRule())->setProduct($product);
    }

    /**
     * Every product this bundle holds a rule row for.
     *
     * The filter it used to carry — "any of the four booleans is true" — cannot be written as DQL
     * any more and should not be: three of the four are derived from the tracking policy now and
     * exist on products with no row here at all.
     *
     * Two columns are still decided by a row: the destination bin, and this product's own minimum
     * shelf life (item 68). Both are asked for, and the second is asked for as IS NOT NULL rather
     * than as a value — a stored `0` means "exempt from the minimum entirely", which is a thing
     * somebody said and has to appear on the screen that lists what has been said. A row where both
     * are absent is deleted by SettingsController rather than stored, so every row returned decides
     * something by construction.
     *
     * @return list<ProductReceivingRule>
     */
    public function allWithRequirements(): array
    {
        /** @var list<ProductReceivingRule> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.locationRequired = true OR r.minimumShelfLifeDays IS NOT NULL')
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
