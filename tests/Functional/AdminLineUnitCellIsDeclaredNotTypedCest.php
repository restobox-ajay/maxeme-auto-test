<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The U/M cell states a PRODUCT line's unit; only a custom line may type one (#601, #624, #659).
 *
 * ## The defect
 *
 * One `<td>` used to render two different FIELDS depending on a condition nobody could see:
 *
 * ```
 * product HAS packaging rungs  ->  <select name="lines[N][packaging_unit_id]">   a PACKAGING rung
 * product has NO rungs         ->  <input  name="lines[N][unit]">                the BASE UNIT label
 * ```
 *
 * #659 removed the first half of that ambiguity outright: there is one vocabulary now, so the
 * selector offers `unit_id` — the product's base unit and the terms it is available in — and there
 * is no second concept for the column to be holding. The rule this file states did not change, and
 * the branch is still on the LINE KIND.
 *
 * Two concepts, one column, nothing on screen saying which one was in front of you. The text box was
 * the harmful half: LineDenomination::baseLabel() prefers a line's own typed string OVER the
 * product's declared base unit, so a typed `12P` — seen on a real order — beat the product's `EA`
 * and became what the customer's document PRINTS as the unit the base quantity is counted in.
 *
 * It corrupts no arithmetic. `sales_order_line.quantity` — the base figure the inventory layer
 * reads — comes off the unit the line names and never off this label, which is why every test below
 * asserts BOTH columns at the row: the point is that the numbers were right all along and the
 * document was describing them wrongly.
 *
 * That precedence is correct for a blank line, which has no product behind it and therefore nothing
 * declaring its unit. Applying it to a product line is the defect, so the cell now branches on the
 * LINE KIND rather than on whether a ladder happens to exist.
 *
 * ## Conducted, and paired
 *
 * Everything here drives the real screens the way an admin with scripting off does — the terms are
 * defined by posting the Units of Measure form, the lines are entered by posting the order form — and
 * every figure read back afterwards comes out of the database after an `EntityManager::clear()`,
 * never off the page. Each order carries a row that must NOT change, and each absence assertion is
 * paired with the same selector matching something on the same page (#627): `dontSeeElement()` on a
 * selector with a typo passes against any page ever written.
 */
final class AdminLineUnitCellIsDeclaredNotTypedCest
{
    /** What a custom line has typed into its U/M box, and what a product line must never be able to. */
    private const TYPED_UNIT = '12P';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('uom-cell-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('UMC-' . strtoupper(substr(uniqid(), -6)))
            ->setPrimaryEmail('buyer@uom-cell.example');
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    /** A product that DECLARES its unit — which is the whole reason its lines need not be asked. */
    private function makeProduct(FunctionalTester $I, string $sku, string $unit = 'EA'): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($sku . ' Widget')
            ->setUnit($unit)
            ->setCostPrice('1.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        // The declaration, not the label: it is what the selector opens on and what the base figure
        // is counted in. Assigned before the product is persisted, with no request in between, so
        // the entity the next EntityManager sees is not a detached copy.
        $product->setBaseUnit($this->each($I));
        $I->haveInRepository($product);
        $I->haveStockFor($product, 10000);

        return $product;
    }

    /** `EA` — built as a fixture, because this file's subject is the CELL and not the term. */
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

    /** Defines a term through the real Units of Measure form and lists it on $product. */
    private function availableUnit(FunctionalTester $I, ProductCore $product, string $code, string $factor): UnitOfMeasure
    {
        $I->amOnPage('/admin/product/units-of-measure/new'); // the form has its own page now; the list is the grid only
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => $code,
            'name' => 'Box of ' . $factor,
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => $factor,
            'rounding_precision' => '1',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $unit = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        $I->assertNotNull($unit, sprintf('the %s term was created through the screen', $code));

        $fresh = $em->find(ProductCore::class, (int) $product->getId());
        $ids = [(int) $unit->getId()];
        foreach ($em->getRepository(ProductAvailableUnit::class)->forProduct($fresh) as $row) {
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
     * `sales_order_line.unit` and `sales_order_line.quantity` straight out of SQLite.
     *
     * The columns, by name, from the row — not an entity that a UnitOfWork somewhere might still be
     * holding a copy of, and not a derived label. `quantity` IS the base figure
     * ({@see SalesOrderLine::getQuantityBase()}).
     *
     * @return array{unit: ?string, quantity_base: string}
     */
    private function rowOf(FunctionalTester $I, int $lineId): array
    {
        $row = $I->grabService(EntityManagerInterface::class)
            ->getConnection()
            ->fetchAssociative('SELECT unit, quantity FROM sales_order_line WHERE id = ?', [$lineId]);

        $I->assertIsArray($row, 'sales_order_line row ' . $lineId . ' is there to be read');

        return ['unit' => $row['unit'], 'quantity_base' => (string) $row['quantity']];
    }

    /**
     * A draft order of three lines: a product line, a CUSTOM line with a typed U/M, and a second
     * product line that nothing below touches.
     *
     * @return int the order id
     */
    private function draftOrder(FunctionalTester $I, Company $company, ProductCore $product, ProductCore $control): int
    {
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-UMC',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sku' => $product->getSku(), 'qty' => '1', 'price' => '0.50'],
                ['name' => 'Pallet deposit', 'sku' => 'DEPOSIT', 'unit' => self::TYPED_UNIT, 'qty' => '2', 'price' => '15.00'],
                ['product_id' => (string) $control->getId(), 'sku' => $control->getSku(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);

        return (int) $order->getId();
    }

    // ── What the cell renders ───────────────────────────────────────────────────────────────

    /**
     * A product line has no U/M text box; a custom line still has one.
     *
     * The negative is the whole fix, so it is stated twice over, both halves paired with the same
     * selector matching on the same page (#627):
     *
     *  - `input[type="text"][name="lines[0][unit]"]` is absent on the PRODUCT row — and the same
     *    selector shape finds the CUSTOM row's box at index 1, so the language is right.
     *  - `select[name="lines[1][unit_id]"]` is absent on the CUSTOM row — and the same
     *    selector shape finds the PRODUCT row's selector at index 0.
     *
     * The product here lists NO available units, which is the case the free-text fallback existed
     * for: it is still the case that needs no text box, because the product declares a base unit
     * whether or not anybody has listed anything beside it. Blank is allowed (#659).
     */
    public function aProductLineStatesItsUnitWhileACustomLineKeepsItsFreeTextBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Declared Unit Co');
        $product = $this->makeProduct($I, 'UMC-PLAIN-1');
        $control = $this->makeProduct($I, 'UMC-PLAIN-CTL');

        $orderId = $this->draftOrder($I, $company, $product, $control);

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        // Positive control for the whole selector language, asserted BEFORE the absences: the
        // custom line's box is a text input named exactly this shape.
        $I->seeElement('input[type="text"][name="lines[1][unit]"]');
        $I->assertSame(
            self::TYPED_UNIT,
            $I->grabAttributeFrom('input[type="text"][name="lines[1][unit]"]', 'value'),
            'the custom line is still free text, carrying what was typed into it',
        );

        // The fix.
        $I->dontSeeElement('input[type="text"][name="lines[0][unit]"]');

        // And its positive half: the product row states the unit instead, through the one selector.
        $I->seeElement('select[name="lines[0][unit_id]"]');
        $I->assertSame(
            'EA (base)',
            trim($I->grabTextFrom('select[name="lines[0][unit_id]"] option')),
            'a product listing no units offers exactly one option: the base unit it declares',
        );
        $I->assertSame(
            'EA',
            $I->grabAttributeFrom('input[type="hidden"][name="lines[0][unit]"]', 'value'),
            'the snapshot the row posts back is the declared unit, not something a keyboard put there',
        );

        // The mirror image, paired the same way: a custom line has no packaging selector, and the
        // same selector shape finds the product row's.
        $I->dontSeeElement('select[name="lines[1][unit_id]"]');
        $I->seeElement('select[name="lines[0][unit_id]"]');

        // The spare no-JS rows keep the split: the product one is a selector, the blank one a box.
        $I->seeElement('select[name="lines[3][unit_id]"]');
        $I->dontSeeElement('input[type="text"][name="lines[3][unit]"]');
        $I->seeElement('input[type="text"][name="lines[4][unit]"]');

        // One concept, so the column names it plainly again (#659).
        $I->see('U/M', '#order-form thead th');
    }

    /**
     * The selector offers the base unit and the product's own terms — and nothing else.
     *
     * The scoping assertion is the load-bearing one: a term that exists in the instance but is not
     * listed on THIS product must not appear, which is what stops a global vocabulary reaching the
     * order screen. It is paired with the same product's own term being present.
     */
    public function theSelectorNamesTheBaseUnitAndOnlyThisProductsOwnTerms(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Labelled Options Co');
        $product = $this->makeProduct($I, 'UMC-LADDER-1');
        $control = $this->makeProduct($I, 'UMC-LADDER-CTL');
        $case = $this->availableUnit($I, $product, 'CELL-BOX-12', '12');
        $pallet = $this->availableUnit($I, $product, 'CELL-PALLET-240', '240');
        // Defined in the instance, listed on the CONTROL product only — so it must not be offered
        // on this row. That is the per-product scoping, stated as an absence with a positive pair.
        $elsewhere = $this->availableUnit($I, $control, 'CELL-BAG-50', '50');

        $orderId = $this->draftOrder($I, $company, $product, $control);

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();

        $option = static fn (string $value): string => sprintf(
            'select[name="lines[0][unit_id]"] option[value="%s"]',
            $value,
        );

        $I->assertSame(
            'EA (base)',
            trim($I->grabTextFrom($option(''))),
            'the base unit says it is the base',
        );
        $I->assertSame(
            'CELL-BOX-12',
            trim($I->grabTextFrom($option((string) $case->getId()))),
            'a term is offered by its code — the count is baked into the term, not composed here',
        );
        $I->assertSame(
            'CELL-PALLET-240',
            trim($I->grabTextFrom($option((string) $pallet->getId()))),
            'and so is the next one up',
        );

        // The scoping: a term listed on another product is not offered on this row, and the
        // absence is paired with the presences above on the same render (#627).
        $I->dontSeeElement($option((string) $elsewhere->getId()));

        // Three options and no more — the base plus this product's two terms, with nothing to type.
        $I->seeNumberOfElements('select[name="lines[0][unit_id]"] option', 3);
        $I->dontSeeElement('input[type="text"][name="lines[0][unit]"]');
        $I->seeElement('input[type="text"][name="lines[1][unit]"]');
    }

    // ── Conducted ───────────────────────────────────────────────────────────────────────────

    /**
     * A product line is saved through the real form and lands with the DECLARED unit; the custom
     * line beside it keeps the one somebody typed.
     *
     * The form is posted with exactly the fields the page renders — the product row's `unit` is
     * grabbed out of the hidden input rather than invented, because the point of the fix is that
     * this is now the only value a browser can send for that row.
     */
    public function savingAProductLineWritesTheDeclaredUnitAndLeavesTheCustomLineAlone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Conducted Plain Co');
        $product = $this->makeProduct($I, 'UMC-SAVE-1');
        $control = $this->makeProduct($I, 'UMC-SAVE-CTL');

        $orderId = $this->draftOrder($I, $company, $product, $control);
        $lines = $this->linesOf($this->reloadOrder($I, $orderId));
        [$productLineId, $customLineId, $controlLineId] = [
            (int) $lines[0]->getId(),
            (int) $lines[1]->getId(),
            (int) $lines[2]->getId(),
        ];

        // Before: the control row, so the "did not change" assertion has something to compare to.
        $controlBefore = $this->rowOf($I, $controlLineId);

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $renderedUnit = $I->grabAttributeFrom('input[type="hidden"][name="lines[0][unit]"]', 'value');
        $renderedCustomUnit = $I->grabAttributeFrom('input[type="text"][name="lines[1][unit]"]', 'value');

        // The admin raises the product line to 9 and presses save. Every other box goes back exactly
        // as it was rendered, which is what a browser does.
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-UMC',
            'lines' => [
                [
                    'id' => (string) $productLineId,
                    'product_id' => (string) $product->getId(),
                    'sku' => $product->getSku(),
                    'unit_id' => '',
                    'unit' => $renderedUnit,
                    'qty' => '9',
                    'qty_rendered' => '1',
                    'price' => '0.50',
                    'price_rendered' => '0.50',
                ],
                [
                    'id' => (string) $customLineId,
                    'name' => 'Pallet deposit',
                    'sku' => 'DEPOSIT',
                    'unit' => $renderedCustomUnit,
                    'qty' => '2',
                    'price' => '15.00',
                ],
                [
                    'id' => (string) $controlLineId,
                    'product_id' => (string) $control->getId(),
                    'sku' => $control->getSku(),
                    'qty' => '7',
                    'price' => '3.25',
                ],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $productRow = $this->rowOf($I, $productLineId);
        $I->assertSame('EA', $productRow['unit'], 'sales_order_line.unit is the unit the PRODUCT declares');
        $I->assertSame(9.0, (float) $productRow['quantity_base'], 'sales_order_line.quantity — the base figure the save actually moved');

        // The custom line: the half of the precedence that is CORRECT, and the negative that proves
        // the fix was not over-applied. Nothing declares this line's unit, so what was typed stands.
        $customRow = $this->rowOf($I, $customLineId);
        $I->assertSame(self::TYPED_UNIT, $customRow['unit'], 'the custom line KEPT its free text');
        $I->assertSame(2.0, (float) $customRow['quantity_base'], 'and its base quantity is untouched');

        // The row that should NOT have changed.
        $controlRow = $this->rowOf($I, $controlLineId);
        $I->assertSame($controlBefore['unit'], $controlRow['unit'], 'the control line kept its unit');
        $I->assertSame(
            (float) $controlBefore['quantity_base'],
            (float) $controlRow['quantity_base'],
            'the control line kept its base quantity',
        );
        $I->assertSame(7.0, (float) $controlRow['quantity_base'], 'and that figure is still the seven it was entered as');
    }

    /**
     * Picking a term off the selector still stores base units, and still leaves the custom line's
     * typed U/M alone.
     *
     * This is the pair the whole cell exists to keep straight: the term decides the ARITHMETIC
     * (`quantity` = 40 x 12) and the base unit decides the WORDS (`unit` = EA). One row, two
     * columns, neither reading the other's.
     */
    public function pickingATermStoresBaseUnitsWhileTheUnitColumnStaysTheDeclaredOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Conducted Term Co');
        $product = $this->makeProduct($I, 'UMC-RUNG-1');
        $control = $this->makeProduct($I, 'UMC-RUNG-CTL');
        $case = $this->availableUnit($I, $product, 'CELL2-BOX-12', '12');

        $orderId = $this->draftOrder($I, $company, $product, $control);
        $lines = $this->linesOf($this->reloadOrder($I, $orderId));
        [$productLineId, $customLineId, $controlLineId] = [
            (int) $lines[0]->getId(),
            (int) $lines[1]->getId(),
            (int) $lines[2]->getId(),
        ];
        $controlBefore = $this->rowOf($I, $controlLineId);

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $renderedUnit = $I->grabAttributeFrom('input[type="hidden"][name="lines[0][unit]"]', 'value');

        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-UMC',
            'lines' => [
                [
                    'id' => (string) $productLineId,
                    'product_id' => (string) $product->getId(),
                    'sku' => $product->getSku(),
                    'unit_id' => (string) $case->getId(),
                    'unit' => $renderedUnit,
                    'qty' => '40',
                    'qty_rendered' => '1',
                    'price' => '6.00',
                    'price_rendered' => '0.50',
                ],
                [
                    'id' => (string) $customLineId,
                    'name' => 'Pallet deposit',
                    'sku' => 'DEPOSIT',
                    'unit' => self::TYPED_UNIT,
                    'qty' => '2',
                    'price' => '15.00',
                ],
                [
                    'id' => (string) $controlLineId,
                    'product_id' => (string) $control->getId(),
                    'sku' => $control->getSku(),
                    'qty' => '7',
                    'price' => '3.25',
                ],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $productRow = $this->rowOf($I, $productLineId);
        $I->assertSame(480.0, (float) $productRow['quantity_base'], 'sales_order_line.quantity is the BASE figure: 40 x 12');
        $I->assertSame('EA', $productRow['unit'], 'sales_order_line.unit still names what those 480 are counted in');

        $reloaded = $this->linesOf($this->reloadOrder($I, $orderId));
        $I->assertSame($case->getId(), $reloaded[0]->getUnitOfMeasure()?->getId(), 'sales_order_line.unit_id is the term that was picked');
        $I->assertSame('CELL2-BOX-12', $reloaded[0]->getDisplayUnitLabel(), 'so the document prints the term the line names');
        $I->assertSame('EA', $reloaded[0]->getBaseUnitLabel(), 'and the base figure beside it is still counted in EA');

        // The negative: the custom line kept its free text through a save that re-denominated its
        // neighbour.
        $I->assertSame(self::TYPED_UNIT, $this->rowOf($I, $customLineId)['unit'], 'the custom line KEPT its free text');

        // The row that should NOT have changed.
        $controlRow = $this->rowOf($I, $controlLineId);
        $I->assertSame($controlBefore['unit'], $controlRow['unit'], 'the control line kept its unit');
        $I->assertSame(7.0, (float) $controlRow['quantity_base'], 'the control line is still seven base units');
        $I->assertNull($reloaded[2]->getUnitOfMeasure(), 'and it names no unit at all');
    }

    // ── The quote form, which shares the rule ───────────────────────────────────────────────

    /**
     * The quote's U/M cell keeps the same split — a product row states its unit, a custom row types one.
     *
     * The quote form posts PARALLEL arrays (`line_unit[]`), so a row is identified by position
     * rather than by field name and the assertions are scoped to the row. Both halves are paired
     * on the same page for the same reason as the order form's.
     */
    public function theQuoteFormMakesTheSameSplit(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Quote Unit Co');
        $product = $this->makeProduct($I, 'UMC-QUOTE-1');
        $case = $this->availableUnit($I, $product, 'CELL3-BOX-12', '12');

        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'lines' => [
                0 => ['id' => '', 'product_id' => (string) $product->getId(), 'name' => '', 'sku' => $product->getSku(), 'qty' => '3', 'weight' => '', 'unit' => '', 'unit_id' => '', 'tax_code' => '', 'cost' => '', 'price' => '1.00', 'location' => 'Main'],
                1 => ['id' => '', 'product_id' => '', 'name' => 'Pallet deposit', 'sku' => 'DEPOSIT', 'qty' => '2', 'weight' => '', 'unit' => self::TYPED_UNIT, 'unit_id' => '', 'tax_code' => '', 'cost' => '', 'price' => '15.00', 'location' => 'Main'],
            ],
            'save_mode' => 'draft',
        ]);

        $estimate = $I->grabEntityFromRepository(Estimate::class, ['company' => $company->getId()]);
        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeResponseCodeIsSuccessful();

        $row = static fn (int $n): string => sprintf('#estimate-line-rows tr.estimate-line-row:nth-of-type(%d) ', $n);

        // Positive control for the selector language: the custom row's free-text box is there.
        $I->seeElement($row(2) . 'input[type="text"][name$="[unit]"]');
        // The fix, on the row beside it.
        $I->dontSeeElement($row(1) . 'input[type="text"][name$="[unit]"]');

        // And its positive half, paired the other way round.
        $I->seeElement($row(1) . 'select[name$="[unit_id]"]');
        $I->dontSeeElement($row(2) . 'select[name$="[unit_id]"]');

        $I->assertSame(
            'EA (base)',
            trim($I->grabTextFrom($row(1) . 'select[name$="[unit_id]"] option[value=""]')),
            'the quote labels its base option exactly as the order form does',
        );
        $I->assertSame(
            'CELL3-BOX-12',
            trim($I->grabTextFrom($row(1) . 'select[name$="[unit_id]"] option[value="' . $case->getId() . '"]')),
            'and its terms the same way',
        );

        $I->see('U/M', '#estimate-line-table thead th');
    }
}
