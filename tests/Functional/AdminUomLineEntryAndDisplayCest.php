<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The one selector and the printed line, conducted end to end (#601, #644, #659, #624).
 *
 * Everything here is done the way an admin with JavaScript switched off does it — the TERM is
 * defined by posting the real Units of Measure form, it is listed on the product by posting the real
 * product form, the line is entered by posting the real order form, and the values read back
 * afterwards are `sales_order_line.quantity`, `.quantity_entered` and `.unit_id` out of the
 * database. Never the page text: a screen that printed 40 and stored 40 boxes as 40 eaches would
 * pass a text assertion and lose 440 units.
 *
 * Where the page IS the subject — the printed document — the assertion is on the CELL, grabbed by
 * position, never `see('40')`, which passes against 1400.
 *
 * The three things this covers:
 *
 *  1. a line can be entered in a unit other than the base, with scripting off;
 *  2. changing the selector RE-EXPRESSES the line rather than reinterpreting it;
 *  3. the document prints `40  BOX-12  $6.00  $240.00` — and the packing slip prints both.
 *
 * #659 changed two visible things and nothing else. The dropdown is populated from the PRODUCT'S
 * available units rather than from a per-product packaging ladder, and the U/M cell prints the
 * unit's CODE rather than `CASE(12)` — a term carries its own count now, so the bracketed pack size
 * would state the same fact twice.
 */
final class AdminUomLineEntryAndDisplayCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('uom-line-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('UOM-' . strtoupper(substr(uniqid(), -6)))
            ->setPrimaryEmail('buyer@uom-line.example');
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $sku): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($sku . ' Widget')
            ->setUnit('EA')
            ->setCostPrice('1.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        // Declared, not typed. It is the second half of every ratio the line save resolves through,
        // and it is what the product's available units are drawn from.
        $product->setBaseUnit($this->each($I));
        $I->haveInRepository($product);
        $I->haveStockFor($product, 10000);

        return $product;
    }

    /**
     * `EA`, the base unit every product here is counted in.
     *
     * Built as a fixture rather than through the screen, and deliberately without issuing a request:
     * the caller assigns it to a product and persists in the same breath, and a request in between
     * would hand back an entity the next EntityManager does not know.
     */
    private function each(FunctionalTester $I): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'EA']);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }

        $each = (new UnitOfMeasure())
            ->setCode('EA')
            ->setName('Each')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('1')
            ->setRoundingPrecision('1');
        $I->haveInRepository($each);

        return $each;
    }

    /**
     * Defines a global term through the real Units of Measure form and returns it.
     *
     * The form is on its own page now: the list screen carries the grid and nothing else, because
     * a create form inside the height-clamped `.content-frame` left the grid clipped to nothing.
     * Same form, same `save()` action, one page along.
     */
    private function defineUnit(FunctionalTester $I, string $code, string $name, string $factor): UnitOfMeasure
    {
        $I->amOnPage('/admin/product/units-of-measure/new');
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => $code,
            'name' => $name,
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => $factor,
            'rounding_precision' => '1',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $unit = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        $I->assertNotNull($unit, sprintf('the %s unit was created through the screen', $code));

        return $unit;
    }

    /**
     * Defines a term through the real Units of Measure screen and lists it on $product.
     *
     * Two statements, because #659 makes them two: the term and its ratio are instance-wide, and
     * which products may be expressed in it is per product. A term that existed but was not listed
     * on this product would be refused by the line save — that scoping is the whole enhancement.
     *
     * The listing goes through `ProductAvailableUnitService` rather than through the product form,
     * because the product form rebuilds the whole product from its post and this file's subject is
     * the LINE. Driving that form is `AdminProductAvailableUnitsCest`'s job, and it conducts it
     * there against `product_available_unit` directly.
     */
    private function availableUnit(FunctionalTester $I, ProductCore $product, string $code, string $factor): UnitOfMeasure
    {
        $unit = $this->defineUnit($I, $code, 'Box of ' . $factor, $factor);

        $em = $I->grabService(EntityManagerInterface::class);
        $fresh = $em->find(ProductCore::class, (int) $product->getId());
        $I->assertInstanceOf(ProductCore::class, $fresh);

        $ids = [(int) $unit->getId()];
        foreach ($em->getRepository(\App\Entity\ProductAvailableUnit::class)->forProduct($fresh) as $row) {
            $ids[] = (int) $row->getUnit()->getId();
        }

        $I->grabService(\App\Service\Uom\ProductAvailableUnitService::class)
            ->apply($fresh, array_values(array_unique($ids)), null);
        $em->flush();
        $em->clear();

        return $em->find(UnitOfMeasure::class, (int) $unit->getId());
    }

    private function reloadOrder(FunctionalTester $I, int $id): SalesOrder
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();

        return $em->find(SalesOrder::class, $id);
    }

    /** @return list<SalesOrderLine> */
    private function linesOf(SalesOrder $order): array
    {
        return array_values($order->getLines()->toArray());
    }

    /**
     * A draft order with one line on $product and one control line on $control, both in base units.
     *
     * The control line exists on every scenario below for one reason: it is the row that must NOT
     * change when the first line is re-denominated, and it is invisible from the row being edited.
     *
     * @return array{0: int, 1: Company}
     */
    private function draftOrderWithTwoLines(FunctionalTester $I, Company $company, ProductCore $product, ProductCore $control): array
    {
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-UOM',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sku' => $product->getSku(), 'qty' => '1', 'price' => '0.50'],
                ['product_id' => (string) $control->getId(), 'sku' => $control->getSku(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);

        return [(int) $order->getId(), $company];
    }

    // ── Entry ───────────────────────────────────────────────────────────────────────────────

    /**
     * A line is entered in boxes through the real order form, with scripting off, and what lands in
     * the database is the BASE figure plus what the human actually said.
     *
     * The U/M cell is a plain `<select>` whose options are the row's own product's AVAILABLE units,
     * so the whole flow is: save a line, come back to the form, pick the unit, type the quantity in
     * it. That is the loop AdminNoJsLineLoopCest already establishes; this is what the loop carries.
     */
    public function aLineIsEnteredInBoxesAndStoredInBaseUnits(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Box Entry Co');
        $product = $this->makeProduct($I, 'UOM-ENTRY-1');
        $control = $this->makeProduct($I, 'UOM-ENTRY-CTL');
        $case = $this->availableUnit($I, $product, 'ENTRY-BOX-12', '12');
        // The control product is available in the same TERM — which is the point of a global
        // vocabulary — and its line still names none.
        $this->availableUnit($I, $control, 'ENTRY-BOX-6', '6');

        [$orderId] = $this->draftOrderWithTwoLines($I, $company, $product, $control);

        // The selector is on the page, with the base unit first and the product's own units after.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="lines[0][unit_id]"]');
        $I->seeElement('select[name="lines[0][unit_id]"] option[value="' . $case->getId() . '"]');
        $I->assertSame(
            'ENTRY-BOX-12',
            trim($I->grabTextFrom('select[name="lines[0][unit_id]"] option[value="' . $case->getId() . '"]')),
            'the option is the term CODE — the count is baked into it, so there is nothing to compose',
        );
        // Scoped to the product: the control's own term is not offered on this row.
        $I->dontSeeElement('select[name="lines[0][unit_id]"] option[value="' . $this->unitIdByCode($I, 'ENTRY-BOX-6') . '"]');

        // 40 cases at $6.00 a case.
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-UOM',
            'lines' => [
                [
                    'id' => (string) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getId(),
                    'product_id' => (string) $product->getId(),
                    'sku' => $product->getSku(),
                    'unit_id' => (string) $case->getId(),
                    'qty' => '40',
                    'qty_rendered' => '1',
                    'price' => '6.00',
                    'price_rendered' => '0.50',
                ],
                [
                    'id' => (string) $this->linesOf($this->reloadOrder($I, $orderId))[1]->getId(),
                    'product_id' => (string) $control->getId(),
                    'sku' => $control->getSku(),
                    'qty' => '7',
                    'price' => '3.25',
                ],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $lines = $this->linesOf($this->reloadOrder($I, $orderId));

        $I->assertSame(480.0, (float) $lines[0]->getQuantity(), 'sales_order_line.quantity is the BASE figure: 40 x 12');
        $I->assertSame(40.0, (float) $lines[0]->getQuantityEntered(), 'sales_order_line.quantity_entered is what the human said');
        $I->assertSame($case->getId(), $lines[0]->getUnitOfMeasure()?->getId(), 'sales_order_line.unit_id');
        $I->assertSame('0.500000', $lines[0]->getBaseUnitRate(), 'sales_order_line.price is per the BASE unit: 6.00 / 12');
        $I->assertSame(240.0, (float) $lines[0]->getSubtotal(), 'sales_order_line.subtotal: 480 x 0.50');

        // The row that must NOT have changed.
        $I->assertSame(7.0, (float) $lines[1]->getQuantity(), 'the control line is still seven base units');
        $I->assertTrue($lines[1]->isEnteredInBaseUnits(), 'the control line still names no unit at all');
        $I->assertSame(7.0, (float) $lines[1]->getQuantityEntered(), 'so its entered figure IS its base figure');
        $I->assertNull($lines[1]->getUnitOfMeasure(), 'the control line names no unit');
        $I->assertSame(3.25, (float) $lines[1]->getPrice());
    }

    /**
     * **Changing the selector RE-EXPRESSES the line; it does not reinterpret it.**
     *
     * Switching BOX-12 to PALLET-240 with the quantity box untouched must leave 480 base units on the
     * order and simply call them 2 pallets. Reading the untouched 40 as pallets would put 9,600
     * units on the order — twenty times what was agreed — without anybody typing a digit. That is
     * the defect #644 names outright, and it is silent.
     *
     * The negative half is asserted in the same test: a quantity the admin actually TYPES is read in
     * the unit they selected, because that is the only reading of "3" that can mean 3 pallets.
     */
    public function switchingTheUnitReExpressesTheLineRatherThanMultiplyingTheOrder(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Re-express Co');
        $product = $this->makeProduct($I, 'UOM-REX-1');
        $control = $this->makeProduct($I, 'UOM-REX-CTL');
        $case = $this->availableUnit($I, $product, 'REX-BOX-12', '12');
        $pallet = $this->availableUnit($I, $product, 'REX-PALLET-240', '240');

        [$orderId] = $this->draftOrderWithTwoLines($I, $company, $product, $control);
        $lineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getId();
        $controlLineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[1]->getId();

        $post = function (array $firstLine) use ($I, $orderId, $company, $control, $controlLineId): void {
            $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
                '_token' => $I->csrfToken(),
                'company_id' => (string) $company->getId(),
                'po_number' => 'PO-UOM',
                'lines' => [
                    $firstLine,
                    ['id' => (string) $controlLineId, 'product_id' => (string) $control->getId(), 'sku' => $control->getSku(), 'qty' => '7', 'price' => '3.25'],
                ],
                'save_mode' => 'draft_recalc',
            ]);
        };

        // 40 cases of 12.
        $post([
            'id' => (string) $lineId,
            'product_id' => (string) $product->getId(),
            'unit_id' => (string) $case->getId(),
            'qty' => '40',
            'price' => '6.00',
        ]);
        $I->assertSame(480.0, (float) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getQuantity(), 'guard: the line starts at 480 base units');

        // What the form actually renders back into the two boxes — grabbed from the page rather
        // than assumed, because that is what a browser posts.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $renderedQty = $I->grabAttributeFrom('input[name="lines[0][qty_rendered]"]', 'value');
        $renderedPrice = $I->grabAttributeFrom('input[name="lines[0][price_rendered]"]', 'value');
        $I->assertSame(40.0, (float) $renderedQty, 'the quantity box asks in the line unit');
        $I->assertSame(6.0, (float) $renderedPrice, 'the price box asks per the line unit');

        // The admin changes ONE dropdown and presses save. Both boxes come back untouched.
        $post([
            'id' => (string) $lineId,
            'product_id' => (string) $product->getId(),
            'unit_id' => (string) $pallet->getId(),
            'qty' => $renderedQty,
            'qty_rendered' => $renderedQty,
            'price' => $renderedPrice,
            'price_rendered' => $renderedPrice,
        ]);

        $lines = $this->linesOf($this->reloadOrder($I, $orderId));
        $I->assertSame(480.0, (float) $lines[0]->getQuantity(), 'the ORDER did not change size — this is the whole test');
        $I->assertSame(2.0, (float) $lines[0]->getQuantityEntered(), '480 base units are 2 pallets of 240');
        $I->assertSame($pallet->getId(), $lines[0]->getUnitOfMeasure()?->getId());
        $I->assertSame('0.500000', $lines[0]->getBaseUnitRate(), 'the stored per-base rate is untouched by re-denominating');
        $I->assertSame(240.0, (float) $lines[0]->getSubtotal());
        $I->assertSame(7.0, (float) $lines[1]->getQuantity(), 'the control line is still seven base units');

        // The other half: a TYPED quantity is read in the unit the selector names.
        $post([
            'id' => (string) $lineId,
            'product_id' => (string) $product->getId(),
            'unit_id' => (string) $pallet->getId(),
            'qty' => '3',
            'qty_rendered' => '2',
            'price' => '120.00',
            'price_rendered' => '120.00',
        ]);

        $lines = $this->linesOf($this->reloadOrder($I, $orderId));
        $I->assertSame(720.0, (float) $lines[0]->getQuantity(), '3 pallets of 240, because the admin typed the 3');
        $I->assertSame(3.0, (float) $lines[0]->getQuantityEntered());
    }

    /**
     * The resolved per-base price and the line total are on the page BEFORE the order is committed.
     *
     * Server-rendered, so an admin with scripting off sees it too — which is the point: the design
     * knowingly rounds a per-box price into a per-unit one, and the whole bargain is that the
     * rounding happens in front of a person rather than on an invoice weeks later.
     *
     * $10.00 a box of 12 is the case that makes it visible: it stores as 0.833333 and totals
     * $400.00, and both figures are printed where they were typed.
     */
    public function theFormShowsTheResolvedPerBasePriceAndLineTotalBeforeSaving(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Resolved Preview Co');
        $product = $this->makeProduct($I, 'UOM-PREV-1');
        $control = $this->makeProduct($I, 'UOM-PREV-CTL');
        $case = $this->availableUnit($I, $product, 'PREV-BOX-12', '12');

        [$orderId] = $this->draftOrderWithTwoLines($I, $company, $product, $control);
        $lineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getId();
        $controlLineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[1]->getId();

        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'unit_id' => (string) $case->getId(), 'qty' => '40', 'price' => '10.00'],
                ['id' => (string) $controlLineId, 'product_id' => (string) $control->getId(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $line = $this->linesOf($this->reloadOrder($I, $orderId))[0];
        $I->assertSame('0.833333', $line->getBaseUnitRate(), '10.00 / 12, stored at six places');
        $I->assertSame(400.0, (float) $line->getSubtotal(), '0.833333 x 480 = 399.99984, rounded once at the line');

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $hint = trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('.js-order-resolved-price')) ?? '');
        $I->assertSame('per PREV-BOX-12 · = $0.833333 / EA · line total $400.00', $hint);

        // And the resolved base quantity, beside the box that produced it.
        $I->assertSame('= 480 EA', trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('.js-order-base-qty')) ?? ''));
    }

    // ── Display ─────────────────────────────────────────────────────────────────────────────

    /**
     * The printed sales order says `40  DOC-BOX-12  $6.00  $240.00` — the conventional line.
     *
     * The base quantity is deliberately NOT printed in parentheses (#601, settled 2026-09-09): at
     * six decimals the derived per-unit-of-sale price is exact, so the three figures check out on
     * the document's own face. That absence is asserted, not assumed.
     *
     * Cells are grabbed by position. `see('40')` would pass against a document reading 1400.
     */
    public function theSalesOrderDocumentPrintsTheConventionalLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Printed Order Co');
        $product = $this->makeProduct($I, 'UOM-DOC-1');
        $control = $this->makeProduct($I, 'UOM-DOC-CTL');
        $case = $this->availableUnit($I, $product, 'DOC-BOX-12', '12');

        [$orderId] = $this->draftOrderWithTwoLines($I, $company, $product, $control);
        $lineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getId();
        $controlLineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[1]->getId();

        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'unit_id' => (string) $case->getId(), 'qty' => '40', 'price' => '6.00'],
                ['id' => (string) $controlLineId, 'product_id' => (string) $control->getId(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $I->amOnPage('/admin/order/document/' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        // Column order: Name, U/M, SKU, Ordered, Invoiced, Remaining, Price, Subtotal — the
        // quantity-ish columns sit immediately left of Price, matching the detail screen's own
        // Name/Unit/SKU/.../Qty/Price shape, not bunched at the far left (#full-parity follow-up).
        $row = '.invoice-table tbody tr:first-child ';
        $I->assertSame('DOC-BOX-12', trim($I->grabTextFrom($row . 'td:nth-child(2)')), 'U/M is the term the line names; the count is in its code');
        $I->assertSame('40', trim($I->grabTextFrom($row . 'td:nth-child(4)')), 'Ordered, in the line unit');
        $I->assertSame('$6.00', trim($I->grabTextFrom($row . 'td:nth-child(7)')), 'the price is per BOX-12, derived from the stored per-EA rate');
        $I->assertSame('$240.00', trim($I->grabTextFrom($row . 'td:nth-child(8)')), '40 x 6.00 checks out on the face of the document');

        // The row that must not have changed: the base-unit line prints exactly as it always did.
        $control_row = '.invoice-table tbody tr:nth-child(2) ';
        $I->assertSame('EA', trim($I->grabTextFrom($control_row . 'td:nth-child(2)')));
        $I->assertSame('7', trim($I->grabTextFrom($control_row . 'td:nth-child(4)')));
        $I->assertSame('$3.25', trim($I->grabTextFrom($control_row . 'td:nth-child(7)')));

        $I->dontSee('(480 EA)');
    }

    /**
     * A document keeps printing the TERM it was written against, forever.
     *
     * This is why a unit freezes once referenced (#659): a supplier moving from twelves to
     * twenty-fours gets a NEW term, `FROZEN-BOX-24`, and the product is repointed at it. An order
     * raised in March and one raised in June name different terms and mean different quantities, and
     * the old document reads its own.
     *
     * That is also what made the bracketed `CASE(12)` unnecessary: the count is in the code, so the
     * March document is self-describing without printing a factor beside a name that could be reused.
     *
     * The old term's freeze is asserted too, from the screen that would otherwise change it: once a
     * document names it, the Units of Measure form refuses the edit.
     */
    public function aDocumentKeepsPrintingTheTermItWasWrittenAgainst(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Restacked Supplier Co');
        $product = $this->makeProduct($I, 'UOM-FROZEN-1');
        $control = $this->makeProduct($I, 'UOM-FROZEN-CTL');
        $case12 = $this->availableUnit($I, $product, 'FROZEN-BOX-12', '12');

        [$orderId] = $this->draftOrderWithTwoLines($I, $company, $product, $control);
        $lineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getId();
        $controlLineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[1]->getId();

        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'unit_id' => (string) $case12->getId(), 'qty' => '40', 'price' => '6.00'],
                ['id' => (string) $controlLineId, 'product_id' => (string) $control->getId(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        // The supplier restacks. A NEW term, because the old one is referenced now.
        $case24 = $this->availableUnit($I, $product, 'FROZEN-BOX-24', '24');

        $I->amOnPage('/admin/product/units-of-measure?edit=' . $case12->getId());
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => (string) $case12->getId(),
            'code' => 'FROZEN-BOX-12',
            'name' => 'Box of 12',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '24',
            'rounding_precision' => '1',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $reread = $em->find(UnitOfMeasure::class, (int) $case12->getId());
        $I->assertNotNull($reread);
        $I->assertSame('12.000000', $reread->getFactorToFamilyBase(), 'a term a document references may not be restated');

        // The March order still prints what it was written against.
        $I->amOnPage('/admin/order/document/' . $orderId);
        $I->assertSame('FROZEN-BOX-12', trim($I->grabTextFrom('.invoice-table tbody tr:first-child td:nth-child(2)')));

        // A NEW order on the new rung prints the new pack size, on the same product.
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-JUNE',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sku' => $product->getSku(), 'unit_id' => (string) $case24->getId(), 'qty' => '10', 'price' => '12.00'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $june = $em->getRepository(SalesOrder::class)->findOneBy(['poNumber' => 'PO-JUNE']);
        $I->assertNotNull($june);
        $I->assertSame(240.0, (float) $this->linesOf($june)[0]->getQuantity(), '10 boxes of 24');

        $I->amOnPage('/admin/order/document/' . $june->getId());
        $I->assertSame('FROZEN-BOX-24', trim($I->grabTextFrom('.invoice-table tbody tr:first-child td:nth-child(2)')));

        // And the March order, re-read, is exactly as it was.
        $I->amOnPage('/admin/order/document/' . $orderId);
        $I->assertSame('40', trim($I->grabTextFrom('.invoice-table tbody tr:first-child td:nth-child(4)')));
        $I->assertSame('FROZEN-BOX-12', trim($I->grabTextFrom('.invoice-table tbody tr:first-child td:nth-child(2)')));
    }

    /**
     * An invoice is raised in boxes, prints the conventional line, and its packing slip prints BOTH.
     *
     * The packing slip is the one exception #601 keeps (settled 2026-09-09): invoices, orders and
     * quotes print three figures and no base quantity, but the warehouse counts in either
     * denomination and that is what a packing slip is for — the picker takes 20 boxes off the
     * pallet, and whoever checks the delivery counts 240 eaches.
     *
     * The invoice is deliberately raised for HALF the order, which is what makes the derivation
     * real: 20 boxes is what the invoice bills, not a copy of the order's 40.
     */
    public function anInvoiceIsBilledInBoxesAndItsPackingSlipShowsBothDenominations(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Billed In Boxes Co');
        $product = $this->makeProduct($I, 'UOM-INV-1');
        $control = $this->makeProduct($I, 'UOM-INV-CTL');
        $case = $this->availableUnit($I, $product, 'INV-BOX-12', '12');

        [$orderId] = $this->draftOrderWithTwoLines($I, $company, $product, $control);
        $lineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getId();
        $controlLineId = (int) $this->linesOf($this->reloadOrder($I, $orderId))[1]->getId();

        // 40 cases, and the order is approved so it can be invoiced against.
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'unit_id' => (string) $case->getId(), 'qty' => '40', 'price' => '6.00'],
                ['id' => (string) $controlLineId, 'product_id' => (string) $control->getId(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'order',
        ]);
        $I->assertSame(480.0, (float) $this->linesOf($this->reloadOrder($I, $orderId))[0]->getQuantity(), 'guard: the order line is 480 base units');

        // The Convert to Invoice screen offers the remainder in the LINE's unit.
        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        // A live select now, not fixed text: the row is freely editable (#full-parity, 2026-09-13)
        // just like a standalone line, so the U/M cell holds the same unit dropdown every other
        // sell-side screen renders — read off the SELECTED option, not the cell's whole text.
        $I->seeElement('select[name="lines[0][unit_id]"] option[value="' . $case->getId() . '"][selected]');
        $I->assertSame(
            'INV-BOX-12',
            trim($I->grabTextFrom('select[name="lines[0][unit_id]"] option[value="' . $case->getId() . '"]')),
            'the U/M column carries the term',
        );
        $I->assertSame('40', trim($I->grabAttributeFrom('input[name="lines[0][qty]"]', 'value')), 'the box is pre-filled in boxes, not in 480 eaches');

        // Bill half of it: 20 boxes.
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $lineId, 'unit_id' => (string) $case->getId(), 'qty' => '20', 'qty_rendered' => '40', 'price' => '6.00', 'price_rendered' => '6.00'],
                ['product_id' => (string) $control->getId(), 'sales_order_line_id' => (string) $controlLineId, 'qty' => '7', 'qty_rendered' => '7', 'price' => '3.25', 'price_rendered' => '3.25'],
            ],
            'save_mode' => 'issue',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $order = $em->find(SalesOrder::class, $orderId);
        $invoice = $order->getInvoices()->first();
        $I->assertNotFalse($invoice, 'the invoice was raised');
        $invoiceLines = array_values($invoice->getLines()->toArray());

        $I->assertSame(240.0, (float) $invoiceLines[0]->getQuantity(), 'invoice_line.quantity is the BASE figure: 20 x 12');
        $I->assertSame(20.0, (float) $invoiceLines[0]->getQuantityEntered(), 'invoice_line.quantity_entered is the 20 boxes billed');
        $I->assertSame($case->getId(), $invoiceLines[0]->getUnitOfMeasure()?->getId(), 'invoice_line.unit_id came from the order line');
        $I->assertSame('0.500000', $invoiceLines[0]->getBaseUnitRate(), 'the untouched price box billed the order rate untouched');
        $I->assertSame(120.0, (float) $invoiceLines[0]->getSubtotal(), '240 x 0.50');

        // The row that must not have changed: the control line billed in base units, as always.
        $I->assertSame(7.0, (float) $invoiceLines[1]->getQuantity());
        $I->assertNull($invoiceLines[1]->getUnitOfMeasure());

        // The printed invoice: the conventional line, and NO base quantity in parentheses.
        $I->amOnPage('/admin/invoice/print/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $row = '.invoice-table tbody tr:first-child ';
        $I->assertSame('20', trim($I->grabTextFrom($row . 'td:nth-child(4)')));
        $I->assertSame('INV-BOX-12', trim($I->grabTextFrom($row . 'td:nth-child(2)')));
        $I->assertSame('$6.00', trim($I->grabTextFrom($row . 'td:nth-child(5)')));
        $I->assertSame('$120.00', trim($I->grabTextFrom($row . 'td:nth-child(6)')));
        $I->dontSee('(240 EA)');

        // The packing slip: BOTH denominations, because the warehouse counts in either.
        $I->amOnPage('/admin/invoice/packing-slip/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $slipRow = '.product-table tbody tr:first-child ';
        $I->assertSame('20', trim($I->grabTextFrom($slipRow . 'td:nth-child(1)')));
        $I->assertSame(
            'INV-BOX-12 240 EA',
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom($slipRow . 'td:nth-child(3)')) ?? ''),
            'the picker takes 20 boxes; whoever checks the delivery counts 240 eaches',
        );

        // The Ordered/Invoiced columns, back on the Convert to Invoice screen for what is left:
        // both read in the line's OWN unit, never the stored base figure. Invoiced reading "240"
        // beside an Ordered of "40" would say more was billed than was ever ordered — the actual
        // shape of the bug this guards, since SalesOrder::invoicedQuantityFor() answers in base
        // units and only invoicedQuantityInLineUnitFor() re-expresses it in boxes.
        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('40', trim($I->grabTextFrom('tr.invoice-line-row:first-child td[data-label="Ordered"]')));
        $I->assertSame('20', trim($I->grabTextFrom('tr.invoice-line-row:first-child td[data-label="Invoiced"]')));

        // The unit itself sticks through an edit round trip: the edit screen pre-fills the SAME
        // unit_id (lineRowsFromInvoice() carries it back), and re-posting it unchanged must not
        // silently re-express the line in base units.
        $I->amOnPage('/admin/invoice/edit/' . $invoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="lines[0][unit_id]"] option[value="' . $case->getId() . '"][selected]');
        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoice->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $lineId, 'unit_id' => (string) $case->getId(), 'qty' => '20', 'qty_rendered' => '20', 'price' => '6.00', 'price_rendered' => '6.00'],
                ['product_id' => (string) $control->getId(), 'sales_order_line_id' => (string) $controlLineId, 'qty' => '7', 'qty_rendered' => '7', 'price' => '3.25', 'price_rendered' => '3.25'],
            ],
            'save_mode' => 'draft',
        ]);

        $em->clear();
        $editedInvoiceLine = $em->find(Invoice::class, $invoice->getId())->getLines()->first();
        $I->assertSame($case->getId(), $editedInvoiceLine->getUnitOfMeasure()?->getId(), 'the unit survived the edit');
        $I->assertSame(20.0, (float) $editedInvoiceLine->getQuantityEntered(), 'still 20 boxes, not silently re-expressed');
        $I->assertSame(240.0, (float) $editedInvoiceLine->getQuantity(), 'and the base figure still agrees with it: 20 x 12');
    }

    /**
     * The same one selector on a quote, driven through the real form with scripting off.
     *
     * The quote is the document that converts into an order, so a unit it cannot express is a unit
     * the order inherits wrongly — which is why it is in this phase rather than after it.
     */
    public function aQuoteLineIsEnteredInBoxesAndPrintsTheConventionalLine(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quoted In Boxes Co');
        $product = $this->makeProduct($I, 'UOM-QUOTE-1');
        $control = $this->makeProduct($I, 'UOM-QUOTE-CTL');
        $case = $this->availableUnit($I, $product, 'QUOTE-BOX-12', '12');

        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Main',
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $product->getId(), 'name' => '', 'sku' => $product->getSku(), 'qty' => '1', 'price' => '0.50'],
                1 => ['id' => '', 'product_id' => (string) $control->getId(), 'name' => '', 'sku' => $control->getSku(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(\App\Entity\Estimate::class, ['company' => $company->getId()]);
        $estimateId = (int) $estimate->getId();

        // The selector is on the quote form too, once the row's product lists a unit.
        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name$="[unit_id]"] option[value="' . $case->getId() . '"]');

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $lineIds = array_map(
            static fn ($line): string => (string) $line->getId(),
            array_values($em->find(\App\Entity\Estimate::class, $estimateId)->getLines()->toArray()),
        );

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimateId, [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => $lineIds[0], 'product_id' => (string) $product->getId(), 'name' => '', 'sku' => $product->getSku(), 'unit_id' => (string) $case->getId(), 'qty' => '40', 'price' => '6.00'],
                1 => ['id' => $lineIds[1], 'product_id' => (string) $control->getId(), 'name' => '', 'sku' => $control->getSku(), 'unit_id' => '', 'qty' => '7', 'price' => '3.25'],
            ],
            'action' => 'save',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $lines = array_values($em->find(\App\Entity\Estimate::class, $estimateId)->getLines()->toArray());

        $I->assertSame(480.0, (float) $lines[0]->getQuantity(), 'estimate_line.quantity is the BASE figure');
        $I->assertSame(40.0, (float) $lines[0]->getQuantityEntered(), 'estimate_line.quantity_entered');
        $I->assertSame($case->getId(), $lines[0]->getUnitOfMeasure()?->getId(), 'estimate_line.unit_id');
        $I->assertSame('0.500000', $lines[0]->getBaseUnitRate(), '6.00 a box of 12, stored per EA');
        $I->assertSame(240.0, (float) $lines[0]->getSubtotal());

        // The row that must not have changed.
        $I->assertSame(7.0, (float) $lines[1]->getQuantity());
        $I->assertNull($lines[1]->getUnitOfMeasure());

        $I->amOnPage('/admin/estimate/quote/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();
        $row = '.invoice-table tbody tr:first-child ';
        $I->assertSame('40', trim($I->grabTextFrom($row . 'td:nth-child(4)')));
        $I->assertSame('QUOTE-BOX-12', trim($I->grabTextFrom($row . 'td:nth-child(2)')));
        $I->assertSame('$6.00', trim($I->grabTextFrom($row . 'td:nth-child(5)')));
        $I->assertSame('$240.00', trim($I->grabTextFrom($row . 'td:nth-child(6)')));
    }

    /** The id of a term by code, so an absence can be asserted against a real option value. */
    private function unitIdByCode(FunctionalTester $I, string $code): int
    {
        $unit = $I->grabService(EntityManagerInterface::class)
            ->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        $I->assertNotNull($unit, sprintf('%s must exist for its absence from the picker to mean anything', $code));

        return (int) $unit->getId();
    }
}
