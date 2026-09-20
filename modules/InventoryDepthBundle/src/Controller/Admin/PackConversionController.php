<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Product\ProductPicker;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\PackConversion;
use InventoryDepthBundle\Entity\ProductPackRule;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\PackConversionRefusal;
use InventoryDepthBundle\Movement\PackCost;
use InventoryDepthBundle\Movement\PackConversionService;
use InventoryDepthBundle\Repository\PackConversionRepository;
use InventoryDepthBundle\Repository\ProductPackRuleRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Break a Case — the screen that takes "break 3 cases of SKU-CASE" and writes both sides (#22).
 *
 * ## One screen, two jobs, on purpose
 *
 * Declaring that a case holds twelve and then breaking three of them are the same job done a minute
 * apart, and splitting them would mean an operator who spots a wrong pack size has to navigate
 * somewhere else to fix it. Same call `/low-stock` makes for reorder levels, and the same reasoning.
 *
 * ## What the operator does NOT have to say
 *
 * A bin. The source rows are chosen by `AutomaticSourcePicker` and the units land where the case
 * came from — see {@see PackConversionService} for why. So the form is: which pack, which way, which
 * warehouse, how many cases. Four fields, and three of them are lists.
 *
 * ## No JavaScript anywhere on it
 *
 * Plain form POSTs, plain selects, and the server states every rule the markup claims — a refused
 * conversion comes back as a flash with the reason in it rather than as a disabled button. The
 * product pickers are core's shared three-tier partial, which keeps its no-JS fallback.
 *
 * ## Refusals are flashes, and they are precise
 *
 * Both {@see PackConversionRefusal} and {@see InsufficientStockException} are raised BEFORE the
 * movement layer opens a transaction, so a refusal genuinely means neither balance moved — the case
 * SKU was not decremented and the unit SKU was not incremented. The message is printed verbatim
 * because it names the row and the figures, which is what an operator needs to decide what to do
 * next.
 */
#[Route('/admin/bundles/inventory-depth/pack-conversion')]
final class PackConversionController extends AbstractInventoryDepthController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly PackConversionService $conversions,
        private readonly ProductPackRuleRepository $rules,
        private readonly PackConversionRepository $history,
        private readonly ProductPicker $products,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    #[Route('', name: 'admin_bundle_inventory_depth_pack_conversion', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $editId = $request->query->getInt('edit', 0);
        $editing = $editId > 0 ? $this->em->find(ProductPackRule::class, $editId) : null;

        $rules = $this->rules->all();

        return $this->render('@InventoryDepth/pack_conversion.html.twig', [
            'rules' => $rules,
            'packRows' => array_map(static function (ProductPackRule $rule): array {
                // What a conversion of this pack WOULD record, computed with the same arithmetic it
                // would use, so the figure on the list and the figure on the row can never disagree.
                // Nothing is stored: PackConversion is where a derived unit cost lands, and only once
                // an operation has actually happened.
                $cost = trim((string) $rule->getCaseProduct()->getCostPrice());
                $known = $cost !== '' && is_numeric($cost);

                return [
                    'rule' => $rule,
                    'caseCost' => $known ? PackCost::format(PackCost::toMicros($cost)) : null,
                    'unitCost' => $known ? PackCost::perUnit($cost, $rule->getUnitsPerCase()) : null,
                ];
            }, $rules),
            'editing' => $editing instanceof ProductPackRule ? $editing : null,
            'warehouses' => $this->activeWarehouses(),
            'recent' => $this->history->recent(25),
            'productOptions' => $this->products->options(),
            'productsRemote' => $this->products->isRemote(),
            'selectedRuleId' => $request->query->getInt('rule', 0),
            'direction' => $request->query->get('direction') === PackConversion::DIRECTION_REBUILD
                ? PackConversion::DIRECTION_REBUILD
                : PackConversion::DIRECTION_BREAK,
        ]);
    }

    /**
     * Declares a pack, or restates an existing one.
     *
     * Every refusal is the service's, not this method's: which two products may form a pack, what
     * the smallest pack is, and whether a chain closes a loop are facts about the model rather than
     * about this form, and a second copy of them here would be a second place to correct.
     */
    #[Route('/declare', name: 'admin_bundle_inventory_depth_pack_rule_save', methods: ['POST'])]
    public function declarePack(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $existing = $id > 0 ? $this->em->find(ProductPackRule::class, $id) : null;

        // A named id that resolves to nothing is a stale Edit link. Falling through to create would
        // declare a pack out of whatever the form's product fields happened to hold.
        if ($id > 0 && !$existing instanceof ProductPackRule) {
            $this->addFlash('error', 'That pack declaration no longer exists. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion');
        }

        $caseId = $this->productIdFrom($request->request->all(), 'case_product_id');
        $unitId = $this->productIdFrom($request->request->all(), 'unit_product_id');

        if ($caseId <= 0 || $unitId <= 0) {
            $this->addFlash('error', 'A pack names two products: the case SKU and the unit SKU inside it. Nothing was saved.');

            return $this->back($id);
        }

        $size = trim((string) $request->request->get('units_per_case', ''));
        if ($size === '' || !ctype_digit($size)) {
            $this->addFlash('error', 'A pack size is a whole number of units. Nothing was saved.');

            return $this->back($id);
        }

        try {
            $rule = $this->conversions->declarePack(
                $this->productOr404($caseId),
                $this->productOr404($unitId),
                (int) $size,
                $request->request->getBoolean('rebuild_allowed'),
                $existing instanceof ProductPackRule ? $existing : null,
            );
        } catch (PackConversionRefusal $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($id);
        }

        $this->addFlash('success', sprintf(
            '%s (inventory_pack_rule row %d %s). Cases %s be rebuilt from loose units.',
            $rule->describe(),
            (int) $rule->getId(),
            $id > 0 ? 'updated' : 'created',
            $rule->isRebuildAllowed() ? 'can' : 'cannot',
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion');
    }

    /**
     * Stops declaring a pack. The rule row goes; no stock is touched and no history is rewritten.
     *
     * `inventory_pack_conversion.rule_id` is `ON DELETE SET NULL`, so every conversion ever run under
     * this declaration keeps its row, its cases, its frozen pack size and its cost. What is lost is
     * the pointer, which is provenance rather than fact.
     */
    #[Route('/{id}/retire', name: 'admin_bundle_inventory_depth_pack_rule_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retire(int $id): Response
    {
        $this->denyIfInactive();

        $rule = $this->em->find(ProductPackRule::class, $id);
        if (!$rule instanceof ProductPackRule) {
            $this->addFlash('error', 'That pack declaration no longer exists.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion');
        }

        $described = $rule->describe();
        $recorded = $this->history->forRule($rule);

        // Detach every recorded conversion from this rule BEFORE removing it, rather than leaning on
        // the column's own `ON DELETE SET NULL` — AuditLogSubscriber::postFlush() (src/EventSubscriber/
        // AuditLogSubscriber.php) runs a second, nested flush to persist the audit-log row, and that
        // second flush re-scans the identity map. Each `$recorded` row loaded above is still managed
        // and still pointing at $rule, which by then has been deleted; Doctrine sees a "new" entity
        // reachable through an association with no cascade-persist and throws
        // ORMInvalidArgumentException, which surfaced to the admin as a bare 500. Nulling the
        // association here, on the ORM side, means the second flush finds nothing stale to complain
        // about — the DB-level SET NULL still fires too, but only as a backstop for rows this request
        // never loaded.
        foreach ($recorded as $conversion) {
            $conversion->setRule(null);
        }

        $this->em->remove($rule);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Stopped declaring %s. No stock moved; the %d conversion(s) already recorded under it keep their rows.',
            $described,
            \count($recorded),
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion');
    }

    /**
     * Runs one conversion. BOTH sides, ONE group, one submission.
     *
     * The idempotency key is a digest of this form's CSRF token — see
     * `AbstractInventoryDepthController::operationKey()`. A double-submitted form applies once; a
     * deliberate second break is a fresh page load, a fresh token and a second group.
     */
    #[Route('/run', name: 'admin_bundle_inventory_depth_pack_conversion_submit', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->denyIfInactive();

        $rule = $this->em->find(ProductPackRule::class, $request->request->getInt('rule_id', 0));
        if (!$rule instanceof ProductPackRule) {
            $this->addFlash('error', 'Choose which pack is being broken or rebuilt. Nothing was written.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion');
        }

        $warehouse = $this->warehouseOr404($request->request->getInt('warehouse_id', 0));

        $cases = trim((string) $request->request->get('cases', ''));
        if ($cases === '' || !ctype_digit($cases) || (int) $cases <= 0) {
            // Refused rather than defaulted, and said out loud rather than left to the browser: these
            // screens work with scripting off, so the server states every rule the markup claims.
            $this->addFlash('error', 'A conversion moves a whole number of cases, one or more. Nothing was written.');

            return $this->back(0, $rule, $request);
        }

        $direction = (string) $request->request->get('direction', '');
        if (!\in_array($direction, PackConversion::directions(), true)) {
            // Refused rather than defaulted: `$direction === DIRECTION_REBUILD` used to treat anything
            // else — a typo, a stale value, a tampered request — as `break`, silently running the
            // opposite of what was never actually asked for.
            $this->addFlash('error', 'Choose a direction: break cases open, or rebuild them. Nothing was written.');

            return $this->back(0, $rule, $request);
        }

        $rebuild = $direction === PackConversion::DIRECTION_REBUILD;
        $reference = trim((string) $request->request->get('reference', ''));

        try {
            $conversion = $rebuild
                ? $this->conversions->rebuildCases($rule, $warehouse, (int) $cases, $this->actor(), $reference !== '' ? $reference : null, $this->operationKey($request), $this->occurredAt($request))
                : $this->conversions->breakCases($rule, $warehouse, (int) $cases, $this->actor(), $reference !== '' ? $reference : null, $this->operationKey($request), $this->occurredAt($request));
        } catch (PackConversionRefusal | InsufficientStockException $e) {
            // Both are raised before the movement layer opens its transaction, so this genuinely
            // means NEITHER balance moved — not "it was rolled back".
            $this->addFlash('error', $e->getMessage());

            return $this->back(0, $rule, $request);
        }

        $this->addFlash('success', sprintf(
            '%s %d case(s) at %s: %d %s %s %d %s, under movement group %d. %s',
            $conversion->isBreak() ? 'Broke open' : 'Rebuilt',
            $conversion->getCases(),
            $warehouse->getName(),
            $conversion->getCases(),
            $rule->getCaseProduct()->getSku() ?: $rule->getCaseProduct()->getName(),
            $conversion->isBreak() ? '→' : '←',
            $conversion->units(),
            $rule->getUnitProduct()->getSku() ?: $rule->getUnitProduct()->getName(),
            (int) $conversion->getGroup()->getId(),
            $conversion->getUnitCost() === null
                ? 'The case SKU carries no cost price, so no value was recorded.'
                : sprintf('Each unit carries %s of the case cost %s.', $conversion->getUnitCost(), (string) $conversion->getCaseCost()),
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion', ['rule' => $rule->getId(), 'direction' => $conversion->getDirection()]);
    }

    /** Back to the screen with the form the operator was on still selected. */
    private function back(int $editId, ?ProductPackRule $rule = null, ?Request $request = null): Response
    {
        $parameters = [];
        if ($editId > 0) {
            $parameters['edit'] = $editId;
        }
        if ($rule instanceof ProductPackRule) {
            $parameters['rule'] = $rule->getId();
        }
        if ($request !== null && $request->request->get('direction') === PackConversion::DIRECTION_REBUILD) {
            $parameters['direction'] = PackConversion::DIRECTION_REBUILD;
        }

        return $this->redirectToRoute('admin_bundle_inventory_depth_pack_conversion', $parameters);
    }

    /**
     * The date the conversion happened, when somebody is recording one after the fact.
     *
     * Blank means now. An unparseable date is treated as blank rather than refused: the field is
     * optional and a typo in it should not lose the operation the operator came here to record.
     */
    private function occurredAt(Request $request): ?\DateTimeImmutable
    {
        $value = trim((string) $request->request->get('occurred_at', ''));
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
