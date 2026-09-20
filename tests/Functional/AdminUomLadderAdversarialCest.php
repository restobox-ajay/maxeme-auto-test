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
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * An adversarial pass at the UoM "ladder" — deliberately not the friendly 12s and 24s every other
 * UoM Cest in this suite uses.
 *
 * ## What this app actually calls a ladder
 *
 * There is no chained unit here — {@see \App\Entity\UnitOfMeasure} carries one flat factor back to
 * the family base and nothing points at another unit. `BOX-11`, `CASE-143` and `PALLET-2002` are
 * three independent rows, not three links. A "ladder" is a business READING of several flat units
 * on one product that happen to nest arithmetically (143 = 13 x 11, 2002 = 14 x 143) — everything
 * this test calls a rung is really a straight shot to `EA` through {@see \App\Service\Uom\LineDenomination::factorToBase()}.
 * If the app is ever asked to prove a genuine multi-hop chain, this is not that test; it is the
 * hardest test the CURRENT flat model can be put through.
 *
 * ## Why these factors
 *
 * 11, 143 and 2002 are deliberately not round numbers the way 12/144/1728 (a dozen-gross-ladder)
 * would be — every other UoM test in this suite uses factors of 12, which a float-precision bug
 * could hide behind (12, 144 and 1728 are all exactly representable, and lucky division masks
 * drift a less friendly factor would not). 11 is prime; nothing here divides evenly except by
 * construction.
 *
 * ## What is actually adversarial
 *
 *  1. The SAME order line is switched through every rung, forward and back, seven times — not
 *     once — and the stored BASE quantity is asserted exact at every single hop, never merely at
 *     the end where an error midway could have cancelled itself out.
 *  2. The order is billed across two invoices in DIFFERENT rungs of the ladder from each other AND
 *     from the rung the order line itself is left on — the case most likely to expose a spot that
 *     reads the wrong line's current unit instead of converting through base.
 *  3. Before the exact remainder is billed, one unit MORE than what is left is deliberately
 *     requested first — the smallest possible overshoot the ladder allows (11 base units, one
 *     BOX-11) — and OverInvoicingGuard is asserted to refuse it despite three different units
 *     having touched this line's arithmetic by that point.
 *  4. The final remainder is required to land on EXACTLY "0.00", not close to it — a document this
 *     complex left even 0.0001 uninvoiced would never close.
 *  5. Displayed Ordered/Invoiced figures (see 65295dda) are checked from an invoice whose OWN
 *     lines are billed in a rung different from the order line's current one — the scenario where
 *     conflating "this invoice's unit" with "the order line's unit" would first show up.
 */
final class AdminUomLadderAdversarialCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('uom-ladder-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function theLadderHoldsExactlyAcrossEverySwitchAndEveryMixedUnitInvoice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())
            ->setName('UoM Ladder Wholesale')
            ->setCode('LADDER-' . uniqid())
            ->setPrimaryEmail('buyer@uom-ladder.example');
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company);

        $each = $this->each($I);
        $product = (new ProductCore())
            ->setSku('LADDER-' . uniqid())
            ->setName('Ladder Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setCostPrice('0.20')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setBaseUnit($each);
        $I->haveInRepository($product);
        $I->haveStockFor($product, 100000);

        // The control line: a second product on the same order, base units only, never touched by
        // anything below — the row that proves a unit switch on line 1 cannot leak onto line 2.
        $control = (new ProductCore())
            ->setSku('LADDER-CTL-' . uniqid())
            ->setName('Ladder Control Widget')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setCostPrice('1.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $control->setBaseUnit($each);
        $I->haveInRepository($control);
        $I->haveStockFor($control, 1000);

        // 11 -> 143 (13 boxes) -> 2002 (14 cases, 182 boxes). Every rung is still one flat factor
        // to EA — see the class docblock — but chosen so 13 and 14 as the "in-between" multipliers
        // are not 10, 12 or any other number a rounding bug could coincidentally get right anyway.
        $box = $this->availableUnit($I, $product, 'LDR-BOX-11', '11');
        $case = $this->availableUnit($I, $product, 'LDR-CASE-143', '143');
        $pallet = $this->availableUnit($I, $product, 'LDR-PALLET-2002', '2002');

        // --- Entry: 2 pallets, $0.50/EA — a base rate the ladder can carry cleanly at every rung
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'po_number' => 'PO-LADDER',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'unit_id' => (string) $pallet->getId(), 'qty' => '2', 'price' => '1001.00'],
                ['product_id' => (string) $control->getId(), 'qty' => '7', 'price' => '3.25'],
            ],
            'save_mode' => 'draft_recalc',
        ]);
        $order = $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
        $orderId = (int) $order->getId();
        $lineId = (int) $this->lineFor($order, $product)->getId();
        $controlLineId = (int) $this->lineFor($order, $control)->getId();

        $I->assertSame(4004.0, (float) $this->lineFor($this->reloadOrder($I, $orderId), $product)->getQuantity(), 'guard: 2 x 2002');

        // --- Every rung, seven hops, base quantity pinned exact at each one -----------------------
        // qty/price posted equal to qty_rendered/price_rendered every time — boxUntouched() reads
        // that as "nobody retyped either box", so the save re-expresses the STORED base figure
        // through the newly selected unit instead of reinterpreting whatever I posted. The price
        // posted each hop is therefore the true per-unit rate for the UNIT BEING SWITCHED TO ($0.50
        // base rate x that unit's own factor), not a stale figure — a mismatched price box would
        // itself read as a genuine retype and silently rescale the line's price, exactly the bug
        // this same mechanism exists to catch on the quantity side.
        $post = function (string $unitId, string $qty, string $price) use ($I, $orderId, $company, $lineId, $product, $control, $controlLineId): void {
            $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
                '_token' => $I->csrfToken(),
                'company_id' => (string) $company->getId(),
                'po_number' => 'PO-LADDER',
                'lines' => [
                    ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'unit_id' => $unitId, 'qty' => $qty, 'qty_rendered' => $qty, 'price' => $price, 'price_rendered' => $price],
                    ['id' => (string) $controlLineId, 'product_id' => (string) $control->getId(), 'qty' => '7', 'price' => '3.25'],
                ],
                'save_mode' => 'draft_recalc',
            ]);
        };
        // The base unit is never a selectable unit_id — an EA-denominated line stores unit_id NULL
        // (see anInvoiceIsBilledInBoxesAndItsPackingSlipShowsBothDenominations's own control-line
        // assertion), so the "back to base" hop posts unit_id '' rather than EA's own row id.
        $hops = [
            [(string) $box->getId(), '364', '5.50', 364.0, $box->getId(), 'PALLET -> BOX: 4004 / 11'],
            [(string) $case->getId(), '28', '71.50', 28.0, $case->getId(), 'BOX -> CASE: 4004 / 143'],
            ['', '4004', '0.50', 4004.0, null, 'CASE -> EA: base is base'],
            [(string) $box->getId(), '364', '5.50', 364.0, $box->getId(), 'EA -> BOX, back down'],
            [(string) $case->getId(), '28', '71.50', 28.0, $case->getId(), 'BOX -> CASE, again'],
            [(string) $pallet->getId(), '2', '1001.00', 2.0, $pallet->getId(), 'CASE -> PALLET: all the way back up'],
            [(string) $case->getId(), '28', '71.50', 28.0, $case->getId(), 'PALLET -> CASE: where invoicing starts'],
        ];
        foreach ($hops as [$unitId, $enteredQty, $enteredPrice, $expectedEntered, $expectedUnitId, $label]) {
            $post($unitId, $enteredQty, $enteredPrice);
            $reloaded = $this->reloadOrder($I, $orderId);
            $line = $this->lineFor($reloaded, $product);
            $I->assertSame(4004.0, (float) $line->getQuantity(), $label . ' — base quantity must never move');
            $I->assertSame($expectedEntered, (float) $line->getQuantityEntered(), $label . ' — re-expressed, not reinterpreted');
            $I->assertSame($expectedUnitId, $line->getUnitOfMeasure()?->getId(), $label . ' — the selected rung stuck');
            $I->assertSame('0.500000', $line->getBaseUnitRate(), $label . ' — the per-base rate held too, not just the quantity');
            $control_line = $this->lineFor($reloaded, $control);
            $I->assertSame(7.0, (float) $control_line->getQuantity(), $label . ' — the control line must never move either');
        }

        // One of the seven hops verified against what the PAGE itself renders, not just the DB —
        // the same authenticity check switchingTheUnitReExpressesTheLineRatherThanMultiplyingTheOrder
        // makes, so this file is not trusting the entity layer to agree with the screen.
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('28', trim($I->grabAttributeFrom('input[name="lines[0][qty_rendered]"]', 'value')), 'the CASE-143 rendering the ladder finished on');
        $I->seeElement('select[name="lines[0][unit_id]"] option[value="' . $case->getId() . '"][selected]');

        // --- Approve, through the real screen ------------------------------------------------------
        $I->sendAjaxPostRequest('/admin/order/update-status/' . $orderId, [
            '_token' => $I->csrfToken(),
            'status' => 'Approved',
        ]);
        $I->assertSame(SalesOrderStatus::Approved->value, $this->reloadOrder($I, $orderId)->getStatus());

        // --- Invoice #1: 10 CASE-143 of the ladder line, plus the control line in full ------------
        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $lineId, 'unit_id' => (string) $case->getId(), 'qty' => '10', 'price' => '71.50'],
                ['product_id' => (string) $control->getId(), 'sales_order_line_id' => (string) $controlLineId, 'qty' => '7', 'price' => '3.25'],
            ],
        ]);
        $invoice1 = $I->grabEntityFromRepository(Invoice::class, ['salesOrder' => $orderId, 'status' => 'Pending']);
        $I->assertNotNull($invoice1, 'guard: invoice #1 issued');
        $I->assertSame(1430.0, (float) $this->reloadOrder($I, $orderId)->invoicedQuantityFor($this->lineFor($this->reloadOrder($I, $orderId), $product)), '10 x 143');
        $I->assertSame('2574.00', $this->reloadOrder($I, $orderId)->uninvoicedQuantityFor($this->lineFor($this->reloadOrder($I, $orderId), $product)), '4004 - 1430, in base units');

        // --- The adversarial near-miss: one BOX-11 (11 base units) more than is left --------------
        // 235 x 11 = 2585, exactly 11 over the 2574 remaining — the smallest overshoot this ladder
        // can express, chosen precisely because it is NOT a round multiple of anything already
        // billed, so a guard that silently tolerated float slop would have to tolerate exactly
        // 4.27e-3 of a case to wave this through, not a convenient whole unit.
        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $lineId, 'unit_id' => (string) $box->getId(), 'qty' => '235', 'price' => '5.50'],
            ],
        ]);
        $overshootInvoice = $I->grabEntityFromRepository(Invoice::class, ['salesOrder' => $orderId, 'status' => 'Draft']);
        $I->assertSame(
            'Draft',
            $overshootInvoice->getStatus(),
            'the overshoot invoice still exists, as a Draft — the plan the whole invoice merge settled on',
        );
        $I->assertSame(
            '2574.00',
            $this->reloadOrder($I, $orderId)->uninvoicedQuantityFor($this->lineFor($this->reloadOrder($I, $orderId), $product)),
            'a refused issue must not have drawn down the remainder at all',
        );

        // --- Correct it in place: 234 x 11 = 2574, exactly the remainder --------------------------
        $I->amOnPage('/admin/invoice/edit/' . $overshootInvoice->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/edit/' . $overshootInvoice->getId(), [
            '_token' => $I->csrfToken(),
            'lines' => [
                ['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $lineId, 'unit_id' => (string) $box->getId(), 'qty' => '234', 'price' => '5.50'],
            ],
            'save_mode' => 'issue',
        ]);
        $invoice2 = $this->reloadInvoice($I, (int) $overshootInvoice->getId());
        $I->assertSame('Pending', $invoice2->getStatus(), 'the exact remainder issues cleanly');

        // --- Fully closed, exactly, in base units — never "close enough" --------------------------
        $reloaded = $this->reloadOrder($I, $orderId);
        $ladderLine = $this->lineFor($reloaded, $product);
        $I->assertSame('0.00', $reloaded->uninvoicedQuantityFor($ladderLine), 'the ladder must close on the nose, not near it');
        $I->assertTrue($reloaded->isFullyInvoiced());
        $I->assertSame(SalesOrderStatus::Invoiced->value, $reloaded->getStatus(), 'fully invoiced, not yet paid');

        // --- Displayed Ordered/Invoiced, read off invoice #2's OWN edit screen ---------------------
        // Invoice #2 bills this line in BOX-11 (see its own qty box below); the order line itself
        // currently sits on CASE-143 (where the ladder walk left it) — two different rungs on the
        // same row of the same screen. If invoicedQuantityInLineUnitFor() (or anything upstream of
        // it) ever confused "the unit THIS invoice happens to bill in" with "the unit the ORDER
        // LINE is currently denominated in", this is where it would show up first: Ordered/Invoiced
        // would come back in boxes instead of cases, or the two would disagree with each other.
        $I->amOnPage('/admin/invoice/edit/' . $invoice2->getId());
        $I->seeResponseCodeIsSuccessful();
        // guard: this invoice really does bill the ladder line in boxes, not cases
        $I->seeElement('select[name="lines[0][unit_id]"] option[value="' . $box->getId() . '"][selected]');
        $I->assertSame('28', trim($I->grabTextFrom('tr.invoice-line-row:first-child td[data-label="Ordered"]')), 'Ordered in the ORDER LINE\'S unit — CASE-143 — not this invoice\'s BOX-11');
        $I->assertSame('28', trim($I->grabTextFrom('tr.invoice-line-row:first-child td[data-label="Invoiced"]')), 'both invoices, mixed units, summed in base and re-expressed as 28 whole cases with no residue');

        // --- Pay both, and only then does the order close ------------------------------------------
        $this->recordPayment($I, (int) $invoice1->getId(), $invoice1->getTotal());
        $I->assertSame(SalesOrderStatus::Invoiced->value, $this->reloadOrder($I, $orderId)->getStatus(), 'one of two invoices paid is not enough to close it');

        $this->recordPayment($I, (int) $invoice2->getId(), $invoice2->getTotal());
        $I->assertSame(InvoicePaymentStatus::Paid, $this->reloadInvoice($I, (int) $invoice1->getId())->getPaymentStatus());
        $I->assertSame(InvoicePaymentStatus::Paid, $this->reloadInvoice($I, (int) $invoice2->getId())->getPaymentStatus());
        $I->assertSame(
            SalesOrderStatus::Closed->value,
            $this->reloadOrder($I, $orderId)->getStatus(),
            'every counting invoice paid, on a line that changed unit seven times and was billed across two more',
        );
    }

    // ------------------------------------------------------------------------------------ fixture

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

    /** Defines a term through the real Units of Measure form and lists it on $product — see AdminUomLineEntryAndDisplayCest::availableUnit(), which this mirrors exactly. */
    private function availableUnit(FunctionalTester $I, ProductCore $product, string $code, string $factor): UnitOfMeasure
    {
        $I->amOnPage('/admin/product/units-of-measure/new');
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => $code,
            'name' => $code,
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => $factor,
            'rounding_precision' => '1',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $unit = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        $I->assertNotNull($unit, sprintf('the %s unit was created through the screen', $code));

        $fresh = $em->find(ProductCore::class, (int) $product->getId());
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

    private function lineFor(SalesOrder $order, ProductCore $product): SalesOrderLine
    {
        foreach ($order->getLines() as $line) {
            if ($line->getProduct()?->getId() === $product->getId()) {
                return $line;
            }
        }

        throw new \RuntimeException(sprintf('No line for product %s on order %d', $product->getSku(), (int) $order->getId()));
    }

    private function recordPayment(FunctionalTester $I, int $invoiceId, string $amount): void
    {
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#document-payment-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $token,
            'payment_id' => '',
            'received_at' => '2026-09-13',
            'method' => 'Bank Transfer',
            'amount' => $amount,
            'comment' => 'Paying off the ladder.',
        ]);
    }

    private function reloadOrder(FunctionalTester $I, int $id): SalesOrder
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(SalesOrder::class)->find($id);
    }

    private function reloadInvoice(FunctionalTester $I, int $id): Invoice
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(Invoice::class)->find($id);
    }
}
