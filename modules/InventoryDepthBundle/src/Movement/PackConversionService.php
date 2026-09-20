<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryModeResolver;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\PackConversion;
use InventoryDepthBundle\Entity\ProductPackRule;
use InventoryDepthBundle\Repository\ProductPackRuleRepository;

/**
 * Breaking a case open, and re-forming one (#22).
 *
 * ## What one call does
 *
 * ONE operation, BOTH sides. "Break 3 cases of SKU-CASE" writes a single
 * {@see InventoryMovementGroup} of type `pack_convert` carrying a movement OUT of the case SKU and a
 * movement INTO the unit SKU, plus one {@see PackConversion} header saying which way round it was
 * and what it was worth. Nothing here writes a detail row or a bucket itself — every quantity goes
 * through {@see StockMovementService}, which is the only writer of `inventory_detail` and
 * `product_inventory.received_quantity` and already owns the transaction, the idempotency key and
 * the stock guard.
 *
 * That is the whole reason this needed no new ledger. Two SKUs changing hands is the shape a
 * movement group was built for — "the reason, the actor, the reference, and the movements it
 * caused" — and a conversion is a transfer between products instead of between buildings.
 *
 * ## IT IS NOT A UNIT OF MEASURE
 *
 * {@see ProductPackRule} carries the argument in full. The one-line form: a unit of measure converts
 * a quantity within ONE SKU (`40 BOX-12` of SKU-X is `480 EA` of SKU-X — one balance, no movement,
 * no date, no actor), and this converts stock between TWO SKUs, which is a physical event. Nothing
 * in this file reads `unit_of_measure`, no conversion here is ever derived from a unit factor, and
 * {@see declarePack()} refuses a rule whose two products are the same product precisely so that the
 * table cannot be used to express a unit conversion by accident.
 *
 * ## Why a movement leaves the system on one side and enters on the other
 *
 * An `inventory_movement` row carries ONE `product_id`, denormalised off its two detail sides, so a
 * single row cannot span two products — and it should not: the case balance and the unit balance are
 * separate numbers and each has to move on its own record. So the case side is a withdrawal
 * (`to = NULL`) and the unit side a receipt (`from = NULL`), and the group is what says the two
 * happened together and why. That also puts the change in `received_quantity` on both products,
 * which is correct: the client's external system knows about neither, and
 * `SUM(available detail) == quantity + received` holds on both sides afterwards.
 *
 * ## Where the goods are
 *
 * The source rows are chosen by {@see AutomaticSourcePicker} — earliest expiry, then lowest bin sort
 * key — so the operator says "3 cases at this warehouse" and never has to name a bin. **The units
 * land in the bin the case came out of**, one pair of movements per source row, because that is
 * where somebody physically cut it open. A rebuild is the mirror with one asymmetry it cannot avoid:
 * the units may be drawn from several bins and the finished cases have to be in one, so they land in
 * the bin the first units came from — where the work started.
 *
 * So the group holds exactly two movements when the stock sits in one place, which is the ordinary
 * case, and one pair per source row when it does not. It is still one operation either way.
 *
 * ## What is deliberately refused
 *
 * - **Not enough stock.** Refused by `StockMovementService::assertSourcesCanCover()` BEFORE the
 *   transaction opens, so neither balance moves and the EntityManager stays open. Breaking 3 cases
 *   when 2 are on hand is an error message, never a negative balance — and because both sides are
 *   one request, a refusal on the source leaves the destination untouched too.
 * - **Rebuilding a pack nobody said could be rebuilt.** `rebuild_allowed` defaults to false; see
 *   {@see ProductPackRule} for why twelve loose units are not necessarily a case.
 * - **Lot- or serial-tracked products, on either side.** An `inventory_lot` row belongs to one
 *   product, so a lot of `CASE-X` is not a lot of `UNIT-X` and the identity CANNOT cross — there is
 *   no row it could be carried to. A conversion that silently dropped the traceability would be
 *   worse than one that refuses, so this refuses and says why.
 * - **A product not on dimensional inventory.** `StockMovementService` refuses it anyway; this says
 *   it in a sentence naming the product first, because the layer's own message is about a rule the
 *   operator did not break.
 */
final class PackConversionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly AutomaticSourcePicker $picker,
        private readonly ProductPackRuleRepository $rules,
        private readonly InventoryModeResolver $modes,
    ) {
    }

    /**
     * Declares (or re-states) that one case SKU holds N of one unit SKU.
     *
     * @param ProductPackRule|null $existing the row being edited, or null to create
     *
     * @throws PackConversionRefusal when the declaration would not be a pack
     */
    public function declarePack(
        ProductCore $caseProduct,
        ProductCore $unitProduct,
        int $unitsPerCase,
        bool $rebuildAllowed,
        ?ProductPackRule $existing = null,
    ): ProductPackRule {
        // THE GUARD THAT KEEPS THE TWO CONCEPTS APART. A SKU holding N of itself is a unit of
        // measure — 1 CASE = 12 EA of the same product — and expressing one here would make a
        // conversion move stock out of a balance and straight back into it while writing two
        // movements and a group saying something happened. Refused at the only place a rule can be
        // created, so no later screen has to check it again.
        if (self::same($caseProduct, $unitProduct)) {
            throw new PackConversionRefusal(sprintf(
                'A pack is two different products. %s cannot hold %d of itself — that is a unit of measure, '
                . 'and it belongs on the product as a unit (Products → Units of Measure), where it converts the '
                . 'figure and moves no stock.',
                $caseProduct->getSku() ?: $caseProduct->getName(),
                $unitsPerCase,
            ));
        }

        if ($unitsPerCase < ProductPackRule::MINIMUM_UNITS_PER_CASE) {
            throw new PackConversionRefusal(sprintf(
                'A case has to hold at least %d units. A case of one is the same goods under a second SKU, '
                . 'which is a relabelling rather than a pack.',
                ProductPackRule::MINIMUM_UNITS_PER_CASE,
            ));
        }

        $clash = $this->rules->forCaseProduct($caseProduct);
        if ($clash instanceof ProductPackRule && $clash !== $existing) {
            throw new PackConversionRefusal(sprintf(
                '%s already holds %d × %s (inventory_pack_rule row %d). A case SKU has one pack size — edit that '
                . 'row rather than adding a second one.',
                $caseProduct->getSku() ?: $caseProduct->getName(),
                $clash->getUnitsPerCase(),
                $clash->getUnitProduct()->getSku() ?: $clash->getUnitProduct()->getName(),
                (int) $clash->getId(),
            ));
        }

        $this->assertNoCycle($caseProduct, $unitProduct);

        $rule = $existing ?? new ProductPackRule();
        $rule
            ->setCaseProduct($caseProduct)
            ->setUnitProduct($unitProduct)
            ->setUnitsPerCase($unitsPerCase)
            ->setRebuildAllowed($rebuildAllowed);

        if ($existing === null) {
            $this->em->persist($rule);
        }

        $this->em->flush();

        return $rule;
    }

    /**
     * Breaks $cases cases of $rule open at $warehouse. Both sides, one group, one call.
     *
     * @throws PackConversionRefusal      when the operation is not one this pack allows
     * @throws InsufficientStockException when the warehouse does not hold that many cases
     */
    public function breakCases(
        ProductPackRule $rule,
        Warehouse $warehouse,
        int $cases,
        ?string $actor,
        ?string $reference = null,
        ?string $operationKey = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): PackConversion {
        return $this->convert($rule, $warehouse, $cases, PackConversion::DIRECTION_BREAK, $actor, $reference, $operationKey, $occurredAt);
    }

    /**
     * Re-forms $cases cases of $rule out of loose units. The same operation in reverse.
     *
     * @throws PackConversionRefusal      when this pack may not be rebuilt, or the operation is not allowed
     * @throws InsufficientStockException when the warehouse does not hold that many loose units
     */
    public function rebuildCases(
        ProductPackRule $rule,
        Warehouse $warehouse,
        int $cases,
        ?string $actor,
        ?string $reference = null,
        ?string $operationKey = null,
        ?\DateTimeImmutable $occurredAt = null,
    ): PackConversion {
        return $this->convert($rule, $warehouse, $cases, PackConversion::DIRECTION_REBUILD, $actor, $reference, $operationKey, $occurredAt);
    }

    /**
     * The one body both directions run through, so nothing can be true of a break and not of an
     * unbreak — including, especially, the cost arithmetic.
     *
     * @throws PackConversionRefusal
     * @throws InsufficientStockException
     */
    private function convert(
        ProductPackRule $rule,
        Warehouse $warehouse,
        int $cases,
        string $direction,
        ?string $actor,
        ?string $reference,
        ?string $operationKey,
        ?\DateTimeImmutable $occurredAt,
    ): PackConversion {
        if ($cases <= 0) {
            throw new PackConversionRefusal('A conversion moves at least one case. Say how many cases.');
        }

        $isBreak = $direction === PackConversion::DIRECTION_BREAK;

        if (!$isBreak && !$rule->isRebuildAllowed()) {
            throw new PackConversionRefusal(sprintf(
                '%s is not marked as rebuildable, so its units cannot be re-formed into cases. Twelve loose units '
                . 'are not necessarily a case that can be resealed — if this pack genuinely can be, tick '
                . '"Cases can be rebuilt" on the declaration first.',
                $rule->describe(),
            ));
        }

        $caseProduct = $rule->getCaseProduct();
        $unitProduct = $rule->getUnitProduct();

        $this->assertConvertible($caseProduct);
        $this->assertConvertible($unitProduct);

        $units = $rule->unitsFor($cases);

        $request = MovementRequest::of(
            InventoryMovementGroup::TYPE_PACK_CONVERT,
            $operationKey,
            sprintf(
                '%s %d case(s): %s',
                $isBreak ? 'Broke open' : 'Rebuilt',
                $cases,
                $rule->describe(),
            ),
            $actor,
            $reference,
            $occurredAt,
        );

        // Sources first, and only then destinations, so the whole plan exists before anything is
        // applied. plan() throws InsufficientStockException from a pure read — no transaction is
        // open, nothing has moved, and the destination side is never built at all.
        if ($isBreak) {
            foreach ($this->picker->plan($caseProduct, $warehouse, $cases) as $step) {
                $bin = $step['detail']->getLocation();
                // Out of the case SKU and into the unit SKU IN THE SAME BIN — that is where somebody
                // stood and cut it open. No lot and no serial on either side: both products are inert
                // by the check above, and a lot of the case SKU could not be carried across anyway
                // because an inventory_lot row belongs to one product.
                $request->remove($caseProduct, new DetailKey($warehouse, $bin, null, null, InventoryDetail::STATUS_AVAILABLE), $step['quantity']);
                $request->receive($unitProduct, new DetailKey($warehouse, $bin, null, null, InventoryDetail::STATUS_AVAILABLE), $step['quantity'] * $rule->getUnitsPerCase());
            }
        } else {
            $plan = $this->picker->plan($unitProduct, $warehouse, $units);

            foreach ($plan as $step) {
                $request->remove($unitProduct, new DetailKey($warehouse, $step['detail']->getLocation(), null, null, InventoryDetail::STATUS_AVAILABLE), $step['quantity']);
            }

            // The one asymmetry the operation cannot avoid: units may come off several shelves and a
            // finished case is in exactly one place. It goes where the work started.
            $request->receive(
                $caseProduct,
                new DetailKey($warehouse, $plan[0]['detail']->getLocation(), null, null, InventoryDetail::STATUS_AVAILABLE),
                $cases,
            );
        }

        $group = $this->movements->apply($request);

        // The cost is read off the CASE SKU in both directions — see PackCost for why the case is the
        // anchor and what that buys: a break followed by an unbreak of the same cases returns the
        // value exactly, with the division residue recorded rather than compounded.
        $caseCost = trim((string) $caseProduct->getCostPrice());
        $hasCost = $caseCost !== '' && is_numeric($caseCost);

        $conversion = (new PackConversion())
            ->setGroup($group)
            ->setRule($rule)
            ->setDirection($direction)
            ->setCases($cases)
            // Frozen. A rule later corrected from 12 to 24 must not restate what this operation did.
            ->setUnitsPerCase($rule->getUnitsPerCase())
            ->setCaseCost($hasCost ? PackCost::format(PackCost::toMicros($caseCost)) : null)
            ->setUnitCost($hasCost ? PackCost::perUnit($caseCost, $rule->getUnitsPerCase()) : null);

        $this->em->persist($conversion);
        $this->em->flush();

        return $conversion;
    }

    /**
     * Everything that makes a product convertible at all, said in sentences the operator can act on.
     *
     * @throws PackConversionRefusal
     */
    private function assertConvertible(ProductCore $product): void
    {
        $name = $product->getSku() ?: $product->getName();

        if (!$this->modes->isDimensional($product)) {
            throw new PackConversionRefusal(sprintf(
                '%s is on simple inventory: its quantity is a number an admin types, and no movement layer may '
                . 'touch it. Switch it to dimensional inventory before it can be part of a pack conversion.',
                $name,
            ));
        }

        $policy = $product->getTrackingPolicy();
        if ($policy !== null && !$policy->isInert()) {
            throw new PackConversionRefusal(sprintf(
                '%s is tracked by %s, and lot or serial identity cannot cross a pack conversion — an inventory_lot '
                . 'row belongs to one product, so a lot of a case is not a lot of the unit inside it and there is no '
                . 'row to carry it to. Breaking this would drop the traceability without saying so, which is why it '
                . 'is refused rather than guessed.',
                $name,
                $policy->getName(),
            ));
        }
    }

    /**
     * Refuses a declaration that would close a loop.
     *
     * A ladder is fine and common — a pallet holds 40 cases, a case holds 12 eaches, two rows — so
     * this does not ban chains. What it bans is any path of declarations leading from the proposed
     * UNIT SKU back to the proposed CASE SKU, because breaking round a loop multiplies the quantity
     * at every step and would manufacture stock out of arithmetic.
     *
     * Walks the chain by case SKU, which terminates because each case SKU has at most one rule and a
     * product is visited once.
     *
     * @throws PackConversionRefusal
     */
    private function assertNoCycle(ProductCore $caseProduct, ProductCore $unitProduct): void
    {
        $seen = [];
        $cursor = $unitProduct;

        while (true) {
            if (self::same($cursor, $caseProduct)) {
                throw new PackConversionRefusal(sprintf(
                    'That would make a loop: %s already leads back to %s through other pack declarations. Breaking '
                    . 'round a loop multiplies the quantity at every step, so the chain has to end somewhere.',
                    $unitProduct->getSku() ?: $unitProduct->getName(),
                    $caseProduct->getSku() ?: $caseProduct->getName(),
                ));
            }

            $id = $cursor->getId();
            if ($id === null || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;

            $next = $this->rules->forCaseProduct($cursor);
            if (!$next instanceof ProductPackRule) {
                return;
            }

            $cursor = $next->getUnitProduct();
        }
    }

    /** Identity first, then id — the same rule MovementRequest::same() uses, and for the same reason. */
    private static function same(ProductCore $a, ProductCore $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return $a->getId() !== null && $a->getId() === $b->getId();
    }
}
