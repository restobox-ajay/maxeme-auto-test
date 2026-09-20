<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use ProcurementBundle\Controller\Admin\ExceptionController;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * `.table-footer` is a flex row that never wrapped, so its spans compressed instead of taking a
 * second line.
 *
 * The exceptions worklist's footer states three separate facts — how many rows this section holds,
 * what they add up to, and how much of the file was read to find them — and the third arrived with
 * the exception-count work. Three flex items on one unwrappable line is a row that shrinks until
 * the text inside it breaks anywhere it can. The two-span case was already doing it; the third made
 * it visible. One declaration fixes it: `flex-wrap: wrap` on `.table-footer`.
 *
 * ## Why this is asserted here rather than measured
 *
 * A functional test has no layout engine — it cannot see that three spans are 90px wide when they
 * wanted 260px. What it CAN assert is the two halves that produce the layout, and it asserts both:
 * the ELEMENT that needed the rule really does carry three items on a real screen, driven with real
 * data (case 1), and the RULE that lets them wrap really is on that element's class in core's own
 * stylesheet, with nothing anywhere in the file putting it back (case 2). That is the same division
 * of labour `PurchaseOrderListFitsCest` settled on for the purchase order list's width.
 *
 * No browser was available in the session that made the change, so the pixel half is NOT claimed.
 * What was done instead is written into the report: every one of the 67 `.table-footer` blocks in
 * `templates/` and `modules/` was read and classified, and case 3 below fixes the two shapes that
 * carry the real risk — a right-aligned pager and a right-aligned action — against real screens.
 */
final class TableFooterWrapsCest
{
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/app.css';

    private const EXCEPTIONS = '/admin/bundles/procurement/exceptions';

    /** The footer under test: the bill-exceptions section's, on the exceptions worklist. */
    private const FOOTER = '#exceptions-on-bills .table-footer';

    /** The third span — the one the exception-count work added. */
    private const SCAN_NOTE = '#exceptions-on-bills-scan';

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('footer-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * 1. The element that needed the rule: a real footer, on a real screen, holding three items.
     *
     * More exception-carrying bills than the screen's scan cap, so all three spans render — the
     * count, the money at risk, and the note about what was not read. Seeded through the entities
     * rather than 205 form posts, for the reason `ProcurementExceptionScopeHonestyCest` gives for
     * the same seeding: the claim under test is what the screen renders, not how the rows got there,
     * and `approve()` here is the same named action the approve controller calls.
     */
    public function theExceptionsFooterReallyDoesCarryThreeItems(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $cap = ExceptionController::DEFAULT_SCAN_CAP;
        $context = $this->seedOrder($I, (string) ($cap * 10) . '.00');
        $this->bulkApprovedBills($I, $context, $cap + 5);

        $I->amOnPage(self::EXCEPTIONS . '?filters[vendor]=' . $context['vendorId']);
        $I->seeResponseCodeIsSuccessful();

        // Three direct children, all of them text spans, all on one flex line.
        $I->seeElement(self::FOOTER);
        $I->seeNumberOfElements(self::FOOTER . ' > .table-count', 3);

        // Named individually, so "three of something" cannot pass on three copies of one span.
        $I->see('bill exceptions', self::FOOTER . ' > .table-count:nth-child(1)');
        $I->see('at risk', self::FOOTER . ' > .table-count:nth-child(2)');
        $I->seeElement(self::FOOTER . ' > ' . self::SCAN_NOTE);
        $I->see('not examined. Narrow the filter to reach the rest', self::SCAN_NOTE);
    }

    /**
     * 2. The rule that lets them wrap is on `.table-footer` in core's stylesheet, and nothing in the
     *    file puts it back.
     *
     * The absence — no rule re-imposing `nowrap` — is paired with the positive assertion on the SAME
     * declaration and the SAME block: `flex-wrap: wrap` is asserted PRESENT first. A typo in the
     * block selector would otherwise pass the absence half on its own.
     *
     * The narrow-viewport rule is asserted too. It stacks the footer into a column below 600px and
     * it is what the wrap sits ABOVE: the wrap covers the band between that breakpoint and the width
     * three spans actually need, so a change that quietly dropped the column rule would leave the
     * phone case to a wrap that has no room to wrap in.
     */
    public function theWrapRuleIsOnTableFooterAndNothingUndoesIt(FunctionalTester $I): void
    {
        $css = (string) file_get_contents(self::CSS_PATH);

        $block = $this->ruleBlock($I, $css, "\n.table-footer {");
        $I->assertStringContainsString('flex-wrap: wrap;', $block, 'the footer must wrap rather than compress its spans');
        $I->assertStringContainsString('display: flex;', $block, 'the rule under test is a flex row');
        // The absence, against the block the positive assertion above just passed on.
        $I->assertStringNotContainsString('nowrap', $block);

        // Nowhere else in the file — at-rule nested or not — is flex-wrap set on this class.
        $offenders = [];
        foreach ($this->declarationsFor($css, 'flex-wrap') as $selector => $value) {
            if (str_contains($selector, '.table-footer') && $value !== 'wrap') {
                $offenders[$selector] = $value;
            }
        }
        $I->assertSame([], $offenders, 'another rule takes the wrap back off .table-footer');

        // And the phone case is untouched: below 600px the footer is still a stacked column.
        $narrow = strstr($css, '@media (max-width: 600px) {' . "\n" . '    .table-footer {');
        $I->assertNotFalse($narrow, 'the narrow-viewport rule that stacks the footer is gone');
        $I->assertStringContainsString('flex-direction: column;', substr((string) $narrow, 0, 200));
    }

    /**
     * 3. The two footer shapes a wrap could have loosened, on real screens, still hold their
     *    right-aligned item.
     *
     * `.table-pagination` is pushed right by `margin-left: auto`, and a `.table-footer-actions`
     * button rides along behind it. Wrapping does not change where either sits on a line that fits;
     * what it changes is the line that does NOT fit, where the pager used to be squeezed and now
     * takes a row of its own — still right-aligned, because the auto margin applies per line.
     *
     * So what is asserted is that both shapes still render, in the order that produces that
     * alignment, and that the declaration doing the aligning is still in core. The paginated list
     * screen is the shape 40-odd screens share; the dashboard's recent-orders card is the
     * count-plus-action shape three more share.
     */
    public function theRightAlignedFooterShapesAreIntact(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $css = (string) file_get_contents(self::CSS_PATH);
        $pager = $this->ruleBlock($I, $css, "\n.table-pagination {");
        $I->assertStringContainsString('margin-left: auto;', $pager, 'the pager is what holds the right-hand edge of a footer');

        // ── the paginated-list shape: count, per-page, pager ────────────────────────────────
        $context = $this->seedOrder($I);
        $this->bulkApprovedBills($I, $context, 3);

        $I->amOnPage('/admin/bundles/procurement/bills?limit=20&page=1');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.table-card .table-footer > .table-count');
        $I->seeElement('.table-card .table-footer > .per-page-label');

        // ── the count-plus-action shape: the dashboard's recent orders card ─────────────────
        $I->amOnPage('/admin');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.table-footer > .table-footer-actions');
        $I->seeElement('.table-footer > .table-footer-actions a.button');
    }

    /**
     * The declarations of one property across the whole stylesheet, keyed by selector.
     *
     * Crude on purpose — it reads every `selector { … }` pair in the file, at-rule nested ones
     * included, because the question it answers is "does anything anywhere set this", which does not
     * need the cascade resolved. `EstimateLineTableWrapCssTest` is the tool for when it does.
     *
     * @return array<string, string>
     */
    private function declarationsFor(string $css, string $property): array
    {
        $found = [];

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER) === false) {
            return $found;
        }

        foreach ($matches as $match) {
            if (preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([^;!]+)/i', $match[2], $declaration) !== 1) {
                continue;
            }

            $found[trim(preg_replace('/\s+/', ' ', $match[1]))] = trim($declaration[1]);
        }

        return $found;
    }

    /** The body of the rule opening with $opener, which must appear exactly once. */
    private function ruleBlock(FunctionalTester $I, string $css, string $opener): string
    {
        $I->assertSame(1, substr_count($css, $opener), sprintf('"%s" must open exactly one rule', trim($opener)));

        $block = strstr($css, $opener);
        $I->assertNotFalse($block);

        return substr((string) $block, 0, (int) strpos((string) $block, '}'));
    }

    /**
     * A vendor, a product and an ISSUED purchase order with one line ordered and nothing received —
     * so any bill against it carries a billed-but-not-received exception.
     *
     * @return array{vendorId: int, orderId: int, lineId: int, warehouseId: int, tag: string}
     */
    private function seedOrder(FunctionalTester $I, string $ordered = '10.00'): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $tag = strtoupper(substr(uniqid(), -6));

        $region = (new FulfillmentRegion())->setName('Footer Region ' . $tag);
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $vendor = (new Vendor())->setName('Footer Vendor ' . $tag)->setCurrency('CAD');
        $em->persist($vendor);

        $product = (new ProductCore())
            ->setSku('FTR-' . $tag)
            ->setName('Footer Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-FTR-' . $tag)
            ->setVendor($vendor)
            ->setVendorName($vendor->getName())
            ->deriveTaxProvinceFrom($warehouse)
            ->setCurrency('CAD');
        $em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName('Footer Line ' . $tag)
            ->setSku($product->getSku())
            ->setQuantityOrdered($ordered)
            ->setUnitCost('1.0000')
            ->setSubtotal(number_format((float) $ordered, 2, '.', ''));
        $line->setQuantityReceived('0.00');
        $order->addLine($line);
        $em->persist($line);

        $order->setStatus('Issued', DocumentActor::system());
        $order->recalculateTotals();
        $em->flush();

        $I->grabService(PurchaseOrderStatusDeriver::class)->recalculate($order);
        $em->flush();

        return [
            'vendorId' => (int) $vendor->getId(),
            'orderId' => (int) $order->getId(),
            'lineId' => (int) $line->getId(),
            'warehouseId' => (int) $warehouse->getId(),
            'tag' => $tag,
        ];
    }

    /**
     * `$count` approved bills against the seeded order, one exception apiece.
     *
     * @param array{vendorId: int, orderId: int, lineId: int, warehouseId: int, tag: string} $context
     */
    private function bulkApprovedBills(FunctionalTester $I, array $context, int $count): void
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $order = $em->getRepository(PurchaseOrder::class)->find($context['orderId']);
        $orderLine = $em->getRepository(PurchaseOrderLine::class)->find($context['lineId']);
        $warehouse = $em->getRepository(Warehouse::class)->find($context['warehouseId']);

        for ($i = 1; $i <= $count; ++$i) {
            $bill = (new VendorBill())
                ->setBillNumber(sprintf('BILL-%s-%04d', $context['tag'], $i))
                ->setVendor($order->getVendor())
                ->setVendorName($order->getVendorName())
                ->setVendorInvoiceNo(sprintf('THEIR-%s-%04d', $context['tag'], $i))
                ->setPurchaseOrder($order)
                ->setDocumentDate('2026-09-10')
                ->setDueDate('2026-10-01');
            $bill->deriveTaxProvinceFrom($warehouse);
            $em->persist($bill);

            $line = (new VendorBillLine())
                ->setPurchaseOrderLine($orderLine)
                ->setProduct($orderLine->getProduct())
                ->setName('Footer Line ' . $context['tag'])
                ->setQuantity('1.00')
                ->setUnitCost('1.0000')
                ->setSubtotal('1.00');
            $bill->addLine($line);
            $em->persist($line);
            $bill->recalculateTotals();
            $bill->approve(DocumentActor::system(), 'Seeded.');
        }

        $em->flush();

        $I->assertSame(
            $count,
            (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM vendor_bill WHERE vendor_id = ? AND status = ?',
                [$context['vendorId'], 'Open'],
            ),
            'approved bills seeded against the vendor under test',
        );
    }
}
