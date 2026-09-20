<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Repository\TrackingPolicyRepository;
use ProcurementBundle\Repository\ProductReceivingRuleRepository;

/**
 * What receiving must capture for one product, for the screens that render it.
 *
 * Reads the SAME `ProductReceivingRule` that ReceivingService refuses on — whose batch, expiry and
 * serial answers are derived from the product's tracking policy since item 67 — and adds the two
 * things a screen needs and a refusal does not: which identity a unit carries, so the row can name
 * it, and the sentinel an unidentified row would wear.
 *
 * One object, three readers: the receipt form to decide which boxes to render at all, the scan
 * console to decide whether to stop and ask, and ReceivingService's own refusals. A screen that
 * asks for something different from what booking in enforces is how item 67 happened.
 */
final class CaptureRequirements
{
    public function __construct(
        private readonly TrackingPolicyRepository $policies,
        private readonly ProductReceivingRuleRepository $rules,
    ) {
    }

    public function forProduct(ProductCore $product): CaptureRequirement
    {
        $policy = $this->policies->policyFor($product);
        $rule = $this->rules->ruleFor($product);

        return new CaptureRequirement(
            match ($policy->getMode()) {
                TrackingPolicy::MODE_LOT => CaptureRequirement::IDENTITY_LOT,
                TrackingPolicy::MODE_SERIAL => CaptureRequirement::IDENTITY_SERIAL,
                default => CaptureRequirement::IDENTITY_NONE,
            },
            $rule->isLotRequired(),
            $rule->isExpiryRequired(),
            $rule->isSerialRequired(),
            $rule->isLocationRequired(),
            $policy->getSentinelIn(),
        );
    }

    /**
     * Keyed by product id, for a template that has to render one row per product.
     *
     * @param iterable<ProductCore> $products
     *
     * @return array<int, CaptureRequirement>
     */
    public function forProducts(iterable $products): array
    {
        $map = [];

        foreach ($products as $product) {
            $id = $product->getId();
            if ($id !== null && !isset($map[$id])) {
                $map[$id] = $this->forProduct($product);
            }
        }

        return $map;
    }
}
