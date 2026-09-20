<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Entity\Company;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * One place for "is this product eligible to be shown/sold": status Active, not deleted, visible,
 * not private to another company, not Hide-ruled on the given price list. Used by the storefront
 * catalogue, the WooCommerce connector's outbound sync, and any future API — none hand-roll their
 * own copy of these four checks.
 */
final class ProductVisibilityGuard
{
    private const RULE_HIDE = 'Hide';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** Single-product check, for a caller already holding a loaded entity (e.g. before a push). */
    public function isEligible(ProductCore $product, ?PriceList $priceList = null, ?Company $company = null): bool
    {
        if ($product->isDeleted() || !$product->isVisible() || $product->getStatus() !== ProductStatus::Active->value) {
            return false;
        }

        if ($product->isPrivate() && ($company === null || !$product->getPrivateCompanies()->contains($company))) {
            return false;
        }

        if ($priceList instanceof PriceList && $this->isHiddenOn($product, $priceList)) {
            return false;
        }

        return true;
    }

    /** Bulk filter: adds the same four conditions onto an existing QueryBuilder selecting ProductCore as $alias. */
    public function applyTo(QueryBuilder $qb, string $alias, ?PriceList $priceList = null, ?int $companyId = null): void
    {
        $qb->andWhere("{$alias}.deleted = false")
            ->andWhere("{$alias}.visible = true")
            ->andWhere("{$alias}.status = :visibilityStatus")
            ->setParameter('visibilityStatus', ProductStatus::Active->value);

        if ($companyId !== null) {
            $qb->leftJoin("{$alias}.privateCompanies", 'visibilityPrivateMatch', 'WITH', 'visibilityPrivateMatch.id = :visibilityCompanyId')
                ->andWhere("(SIZE({$alias}.privateCompanies) = 0 OR visibilityPrivateMatch.id IS NOT NULL)")
                ->setParameter('visibilityCompanyId', $companyId);
        } else {
            $qb->andWhere("SIZE({$alias}.privateCompanies) = 0");
        }

        if ($priceList instanceof PriceList) {
            $qb->andWhere(
                'NOT EXISTS ('
                . 'SELECT 1 FROM ' . ProductPricing::class . " visibilityHidden"
                . " WHERE visibilityHidden.product = {$alias}"
                . ' AND visibilityHidden.priceList = :visibilityPriceList'
                . ' AND visibilityHidden.ruleType = :visibilityHideRule'
                . ')',
            )
                ->setParameter('visibilityPriceList', $priceList)
                ->setParameter('visibilityHideRule', self::RULE_HIDE);
        }
    }

    private function isHiddenOn(ProductCore $product, PriceList $priceList): bool
    {
        $pricing = $this->entityManager->getRepository(ProductPricing::class)->findOneBy([
            'product' => $product,
            'priceList' => $priceList,
        ]);

        return $pricing instanceof ProductPricing && $pricing->getRuleType() === self::RULE_HIDE;
    }
}
