<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The server half of #464's base-resolution table, asserted end to end against
 * admin_product_price_update.
 *
 * The price grid computes each Effective cell twice — once in the browser the instant an admin edits
 * a row, once here when the edit is saved — and the save handler deliberately does not adopt the
 * server's answer, so a disagreement between the two is not self-correcting. It survives on screen
 * until a full page reload, showing a figure the database does not hold.
 *
 * tests/Asset/PriceGridRowEffectiveJsTest.php executes the browser's recomputeRowEffective() under
 * Deno against exactly this table. This file drives the same table through the real endpoint, with a
 * real product and a real price list, and asserts what actually gets stored. The two files carry the
 * same expected figures on purpose: neither side can be "fixed" into agreement with a regression in
 * the other, because breaking the agreement turns exactly one of them red.
 *
 * What the table is about is the BASE price: default_price if that field holds a number, otherwise
 * original_price if that one does, otherwise no base at all. Neither step asks whether the number is
 * positive — is_numeric('0') and is_numeric('-5') are both true — which is the divergence #464
 * reported, since the client asked for a positive number at both steps and so resolved a different
 * base from this one whenever a product was priced at or below zero.
 */
final class AdminProductPriceRuleBaseResolutionCest
{
    /**
     * The figure the client posts in the `price` field, standing in for whatever it last computed.
     *
     * It is deliberately unrelated to every base and every rule in the table, so that a row which
     * ends up echoing it is unmistakably taking updatePrice()'s LAST resort — "no rule I can apply
     * and no base to fall back on, so keep what was posted" — rather than having computed anything.
     */
    private const POSTED = '999.99';

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function baseResolutionTable(): array
    {
        // row name, default_price, original_price, the resolved base ('' for no base at all)

        return [
            ['default 10, product 20 -> base 10', '10', '20', '10.00'],
            ['default 10, product blank -> base 10', '10', '', '10.00'],
            ['default 0, product 20 -> base 0', '0', '20', '0.00'],
            ['default 0, product 0 -> base 0', '0', '0', '0.00'],
            ['default 0, product blank -> base 0', '0', '', '0.00'],
            ['default -5, product 20 -> base -5', '-5', '20', '-5.00'],
            ['default -5, product 0 -> base -5', '-5', '0', '-5.00'],
            ['default -5, product blank -> base -5', '-5', '', '-5.00'],
            ['default blank, product 20 -> base 20', '', '20', '20.00'],
            ['default blank, product 0 -> base 0', '', '0', '0.00'],
            ['default blank, product -5 -> base -5', '', '-5', '-5.00'],
            ['default blank, product blank -> no base', '', '', ''],
            ['default 12abc, product 20 -> base 20', '12abc', '20', '20.00'],
            ['default 12abc, product blank -> no base', '12abc', '', ''],
        ];
    }

    /**
     * `Discount$ 3` reads the base out of the answer: the base is arithmetic there, so the stored
     * figure names it exactly. Every row of the table, including the eight that involve a zero or a
     * negative somewhere — the rows the browser used to refuse to compute at all.
     */
    public function discountDollarResolvesTheBaseTheSameWayForEveryPairOfPriceFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-discount-dollar@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        foreach ($this->baseResolutionTable() as $index => [$name, $defaultPrice, $originalPrice, $base]) {
            $expected = $base === '' ? self::POSTED : number_format((float) $base - 3, 2, '.', '');

            $I->assertSame(
                $expected,
                $this->save($I, $token, 'DDLR-' . $index, $defaultPrice, $originalPrice, 'Discount$', '3'),
                sprintf('Discount$ 3 over "%s" (#464).', $name),
            );
        }
    }

    /**
     * `Discount% 10` reads it a second way. Between the two, a base that is right in one branch and
     * wrong in the other cannot hide: a base of 0 gives 0.00 here and -3.00 above, and a base of -5
     * gives -4.50 here and -8.00 above.
     */
    public function discountPercentResolvesTheBaseTheSameWayForEveryPairOfPriceFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-discount-percent@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        foreach ($this->baseResolutionTable() as $index => [$name, $defaultPrice, $originalPrice, $base]) {
            $expected = $base === '' ? self::POSTED : number_format((float) $base * 0.9, 2, '.', '');

            $I->assertSame(
                $expected,
                $this->save($I, $token, 'DPCT-' . $index, $defaultPrice, $originalPrice, 'Discount%', '10'),
                sprintf('Discount%% 10 over "%s" (#464).', $name),
            );
        }
    }

    /**
     * The control. `Number` is the one rule that must ignore the base entirely, so it is 25.00 down
     * the whole table — including the two rows with no base at all, where the other rules fall back
     * to the posted figure. It fails only if a base-resolution change has leaked into a branch that
     * has no business consulting the base.
     */
    public function aNumberRuleIgnoresTheBaseForEveryPairOfPriceFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-number@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        foreach ($this->baseResolutionTable() as $index => [$name, $defaultPrice, $originalPrice]) {
            $I->assertSame(
                '25.00',
                $this->save($I, $token, 'NUM-' . $index, $defaultPrice, $originalPrice, 'Number', '25'),
                sprintf('Number 25 over "%s" must not consult the base price at all (#464).', $name),
            );
        }
    }

    /**
     * No rule at all: the base price itself, unmediated. This is the reading that names the resolved
     * base directly rather than inferring it from arithmetic, and it is also the shape of every
     * "rule that resolves to nothing" — a blank type, an unusable value, an unrecognised type all
     * land in the same branch.
     */
    public function noRuleAtAllStoresTheBasePriceForEveryPairOfPriceFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-no-rule@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        foreach ($this->baseResolutionTable() as $index => [$name, $defaultPrice, $originalPrice, $base]) {
            $expected = $base === '' ? self::POSTED : $base;

            $I->assertSame(
                $expected,
                $this->save($I, $token, 'NORULE-' . $index, $defaultPrice, $originalPrice, '', ''),
                sprintf('No rule over "%s" stores the base price (#464).', $name),
            );
        }
    }

    /**
     * A rule value the server will not accept is an ABSENT value, so the rule resolves to nothing
     * and the answer is the base price — never a computation from a half-read value, and never the
     * posted figure while there is still a base to fall back on.
     *
     * "12abc" is #464 item 4: parseFloat() read it as 12 in the browser and discounted by 12 while
     * the server discounted by nothing. "1e3" and "1E3" are item 6, the one case where the SERVER
     * was judged wrong and changed — is_numeric() accepts both, so `Discount$ 1e3` used to be a
     * thousand dollars off, from three keystrokes.
     */
    public function anUnusableRuleValueFallsBackToTheBasePrice(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-bad-value@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        foreach (['12abc', '0x1A', 'abc', '1e3', '1E3', ''] as $index => $value) {
            $I->assertSame(
                '12.50',
                $this->save($I, $token, 'BADVAL-' . $index, '12.50', '', 'Discount$', $value),
                sprintf('Discount$ "%s" is not a rule, so the base price stands (#464).', $value),
            );

            // With no base there is nothing to fall back TO, and only then does the posted figure
            // survive. The browser's equivalent of this is leaving the cell untouched.
            $I->assertSame(
                self::POSTED,
                $this->save($I, $token, 'BADVAL-NOBASE-' . $index, '', '', 'Discount$', $value),
                sprintf('Discount$ "%s" with no base price keeps what was posted (#464).', $value),
            );
        }
    }

    /**
     * The other side of that line, so the rejections above cannot be achieved by rejecting
     * everything. Plain decimals and negatives are still rule values, and `1e3`'s neighbours in the
     * grammar — a bare `3`, a `3.5`, a `.5` — must not have been caught by the exponent rule.
     */
    public function ordinaryRuleValuesAreStillApplied(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-good-value@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        $cases = [
            ['3', '9.50'],
            ['3.5', '9.00'],
            ['.5', '12.00'],
            ['0', '12.50'],
            ['-5', '17.50'],
            ['20', '-7.50'],
        ];

        foreach ($cases as $index => [$value, $expected]) {
            $I->assertSame(
                $expected,
                $this->save($I, $token, 'GOODVAL-' . $index, '12.50', '', 'Discount$', $value),
                sprintf('Discount$ %s off 12.50 (#464).', $value),
            );
        }
    }

    /**
     * An exponent in the `price` field itself is refused outright with a 422, rather than quietly
     * becoming 1000.00. This is the only branch of #464 item 6 that produces a visible error instead
     * of a fallback, because `price` is the one field with nothing to fall back to.
     */
    public function anExponentPriceIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-exponent-price@example.test');
        $token = $this->grabPriceToken($I, 'price-update');

        $product = $this->makeProduct($I, 'EXP-PRICE', '12.50', '');
        $priceList = $this->makePriceList($I, 'Exponent Price List');

        foreach (['1e3', '1E3'] as $price) {
            $I->sendAjaxPostRequest('/admin/product/price/update', [
                '_token' => $token,
                'product_id' => (string) $product->getId(),
                'price_list_id' => (string) $priceList->getId(),
                'price' => $price,
                'rule_type' => 'Number',
                'rule_value' => '12.50',
            ]);

            $I->seeResponseCodeIs(422);
            $response = json_decode($I->grabPageSource(), true);
            $I->assertFalse($response['ok'], sprintf('"%s" is not a price (#464).', $price));
        }
    }

    /**
     * The whole point of the change: bulk apply and a single save resolve the base identically.
     *
     * Each row of the table is built twice, as two separate products with the same two price fields,
     * and the same rule is applied to one through admin_product_price_update and to the other through
     * admin_product_price_bulk_apply. The two stored prices are then compared to each other as well
     * as to the figure the table predicts, so this cannot be satisfied by both endpoints being wrong
     * in the same new way, nor by the expected column being edited to match whatever they do.
     *
     * `Discount$ 3` reads the base straight out of the answer.
     */
    public function bulkApplyResolvesTheBaseLikeASingleSaveForEveryPairOfPriceFieldsOnDiscountDollar(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-discount-dollar@example.test');

        $this->assertBulkMatchesSingleAcrossTheTable($I, 'BULKDDLR', 'Discount$', '3', static fn (float $base): string => number_format($base - 3, 2, '.', ''));
    }

    /**
     * `Discount% 10` reads it a second way, so a base that is right in one branch and wrong in the
     * other cannot hide: base 0 gives 0.00 here and -3.00 above, base -5 gives -4.50 here and -8.00.
     */
    public function bulkApplyResolvesTheBaseLikeASingleSaveForEveryPairOfPriceFieldsOnDiscountPercent(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-discount-percent@example.test');

        $this->assertBulkMatchesSingleAcrossTheTable($I, 'BULKDPCT', 'Discount%', '10', static fn (float $base): string => number_format($base * 0.9, 2, '.', ''));
    }

    /**
     * The control. `Number` must ignore the base entirely, so it is 25.00 down the whole table on
     * both endpoints — including the two rows with no base at all, the one place the two endpoints
     * are allowed to differ for the other rules. It fails only if a base-resolution change has leaked
     * into a branch that has no business consulting the base.
     */
    public function bulkApplyIgnoresTheBaseOnANumberRuleForEveryPairOfPriceFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-number@example.test');
        $singleToken = $this->grabPriceToken($I, 'price-update');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        foreach ($this->baseResolutionTable() as $index => [$name, $defaultPrice, $originalPrice]) {
            $single = $this->save($I, $singleToken, 'BULKNUM-S-' . $index, $defaultPrice, $originalPrice, 'Number', '25');
            $bulk = $this->bulkApply($I, $bulkToken, 'BULKNUM-B-' . $index, $defaultPrice, $originalPrice, 'Number', '25');

            $I->assertSame('25.00', $bulk, sprintf('Bulk Number 25 over "%s" must not consult the base price at all (#464).', $name));
            $I->assertSame($single, $bulk, sprintf('Bulk and single Number 25 over "%s" must store the same price.', $name));
        }
    }

    /**
     * The one case bulk apply cannot copy from updatePrice(), recorded as a decision rather than left
     * to be discovered.
     *
     * updatePrice() ends on "no rule I can apply and no base to fall back on, so keep what was
     * posted". A bulk request carries no per-product posted price, so that third branch has no
     * argument to take. What updatePrice() actually preserves there is the number already sitting on
     * the row — the grid's `price` input is seeded from the stored effective price — so bulk apply
     * leaves the stored price standing, and reports the row in `unresolved` so the outcome is visible
     * instead of silent. Writing 0.00 was the alternative and was rejected: it would invent a price
     * nobody asked for and zero out the real customer price of every product with no base at all.
     *
     * The rule columns are still written, exactly as they are for every other row.
     */
    public function bulkApplyLeavesTheStoredPriceStandingWhenThereIsNoBaseAndNoComputableRule(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-unresolved@example.test');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        $product = $this->makeProduct($I, 'BULK-UNRESOLVED', '', '');
        $priceList = $this->makePriceList($I, 'Bulk Unresolved List');
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setPrice('77.77'));

        $response = $this->sendBulkApply($I, $bulkToken, $product->getId(), $priceList->getId(), 'Discount$', '3');

        $I->assertSame(1, $response['unresolved'], 'A row with no base and no computable rule must be reported, not silently skipped.');
        $I->assertSame('77.77', $this->storedPrice($I, $product->getId(), $priceList->getId()), 'With nothing to resolve, the price already on the row stands.');
        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount$',
            'ruleValue' => '3.00',
        ]);
    }

    /**
     * `field=type` derives a Discount% from the price already on the row when that row has no rule
     * value yet, which means dividing by the base. Now that a non-positive base is a base like any
     * other, a base of exactly 0.00 reaches that division — and PHP 8 raises DivisionByZeroError
     * rather than yielding INF, so the request would be a 500 instead of a bulk apply.
     *
     * The guard therefore tests for zero, not for sign. Zero has no percentage to read (every price
     * is infinitely far from nothing), so the derivation is abandoned for the grid's default rule
     * value of 10; the base of 0 then still resolves the price, to 0.00.
     */
    public function bulkApplyDoesNotDivideByAZeroBaseWhenDerivingARuleValue(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-zero-divide@example.test');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        $product = $this->makeProduct($I, 'BULK-ZERO-BASE', '0', '');
        $priceList = $this->makePriceList($I, 'Bulk Zero Base List');
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setPrice('4.00'));

        $this->sendBulkApplyField($I, $bulkToken, $product->getId(), $priceList->getId(), 'type', 'Discount%', '');

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount%',
            'ruleValue' => '10.00',
        ]);
        $I->assertSame('0.00', $this->storedPrice($I, $product->getId(), $priceList->getId()), '10% off a base of 0 is 0.00.');
    }

    /**
     * The other half of that guard, and the reason it is not simply the old `> 0` under a new name: a
     * NEGATIVE base divides perfectly well and yields a perfectly good percentage. -4.00 standing on
     * a base of -5 is a 20% discount, and applying it back gives -4.00 again.
     */
    public function bulkApplyDerivesARuleValueFromANegativeBase(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-negative-divide@example.test');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        $product = $this->makeProduct($I, 'BULK-NEG-BASE', '-5', '');
        $priceList = $this->makePriceList($I, 'Bulk Negative Base List');
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setPrice('-4.00'));

        $this->sendBulkApplyField($I, $bulkToken, $product->getId(), $priceList->getId(), 'type', 'Discount%', '');

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Discount%',
            'ruleValue' => '20.00',
        ]);
        $I->assertSame('-4.00', $this->storedPrice($I, $product->getId(), $priceList->getId()), '20% off a base of -5 is -4.00.');
    }

    /**
     * #464 item 7. An unrecognised rule type resolves to nothing, and a rule that resolves to nothing
     * is answered by the base price — the same branch a blank type takes in updatePrice().
     *
     * Only reachable through `field=value`, which posts no type and so inherits whatever the row
     * already carried; the type is validated against a fixed list whenever it IS posted. Before this
     * change the row kept its stale 5.00 and nothing said the rule had not applied.
     */
    public function bulkApplyFallsBackToTheBaseForAnUnrecognisedRuleType(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-unknown-type@example.test');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        $product = $this->makeProduct($I, 'BULK-UNKNOWN-TYPE', '12.50', '');
        $priceList = $this->makePriceList($I, 'Bulk Unknown Type List');
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setRuleType('Markup%')
            ->setPrice('5.00'));

        $this->sendBulkApplyField($I, $bulkToken, $product->getId(), $priceList->getId(), 'value', '', '7');

        $I->assertSame('12.50', $this->storedPrice($I, $product->getId(), $priceList->getId()), 'An unrecognised rule type resolves to the base price (#464 item 7).');

        // ...and to the same figure updatePrice() reaches through its own "rule resolves to nothing"
        // branch, over the same base. This is the one reachable route into that branch on the bulk
        // side — a non-numeric rule VALUE is refused with a 422 before any product is touched — so
        // without this row the fallback would be code no test can tell apart from a no-op.
        $singleToken = $this->grabPriceToken($I, 'price-update');
        $I->assertSame(
            '12.50',
            $this->save($I, $singleToken, 'BULK-UNKNOWN-TYPE-SINGLE', '12.50', '', '', '7'),
            'Both endpoints answer a rule that resolves to nothing with the base price.',
        );
    }

    /**
     * `No Price` and `Hide` are not rules that resolve to a price, they are an explicit absence of
     * one, and updatePrice() answers both with 0.00. They reach the resolution below only through
     * `field=value`, which inherits the row's existing type; without an arm of their own they would
     * take the new base fallback and a "No Price" row would quietly become a priced one.
     */
    public function bulkApplyKeepsNoPriceAndHideRowsAtZeroWhenOnlyTheValueIsApplied(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'bulk-base-resolution-no-price@example.test');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        foreach (['No Price', 'Hide'] as $index => $type) {
            $product = $this->makeProduct($I, 'BULK-NOPRICE-' . $index, '12.50', '');
            $priceList = $this->makePriceList($I, 'Bulk No Price List ' . $index);
            $I->haveInRepository((new ProductPricing())
                ->setProduct($product)
                ->setPriceList($priceList)
                ->setCurrency('USD')
                ->setRuleType($type)
                ->setPrice('0.00'));

            $this->sendBulkApplyField($I, $bulkToken, $product->getId(), $priceList->getId(), 'value', '', '7');

            $I->assertSame(
                '0.00',
                $this->storedPrice($I, $product->getId(), $priceList->getId()),
                sprintf('A "%s" row must not pick up the base price from a value-only bulk apply.', $type),
            );
        }
    }

    /**
     * Bulk apply refuses the same values updatePrice() refuses, and for a stronger reason: it writes
     * one rule value to every product matching the grid's active filters, so a `1e3` that slipped
     * past would not be one wrong price but a page of them.
     */
    public function bulkApplyRejectsAnExponentRuleValue(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-bulk-exponent@example.test');

        $this->makeProduct($I, 'BULK-EXP', '12.50', '');
        $priceList = $this->makePriceList($I, 'Bulk Exponent List');
        $token = $this->grabPriceToken($I, 'bulk-apply');

        foreach (['1e3', '1E3', '12abc'] as $value) {
            $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
                '_token' => $token,
                'price_list_id' => (string) $priceList->getId(),
                'field' => 'both',
                'type' => 'Discount$',
                'value' => $value,
            ]);
            $I->seeResponseCodeIs(422);

            $response = json_decode($I->grabPageSource(), true);
            $I->assertFalse($response['ok'], sprintf('"%s" is not a bulk rule value (#464).', $value));
            $I->assertSame('Value must be a valid number.', $response['message']);
        }
    }

    /** ...and still accepts the ordinary ones, so the rule above did not simply refuse everything. */
    public function bulkApplyStillAcceptsOrdinaryRuleValues(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I, 'base-resolution-bulk-ordinary@example.test');

        $this->makeProduct($I, 'BULK-OK', '12.50', '');
        $priceList = $this->makePriceList($I, 'Bulk Ordinary List');
        $token = $this->grabPriceToken($I, 'bulk-apply');

        foreach (['3', '3.5', '.5', '0', '-5'] as $value) {
            $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
                '_token' => $token,
                'price_list_id' => (string) $priceList->getId(),
                'field' => 'both',
                'type' => 'Discount$',
                'value' => $value,
            ]);
            $I->seeResponseCodeIsSuccessful();

            $response = json_decode($I->grabPageSource(), true);
            $I->assertTrue($response['ok'], sprintf('"%s" is an ordinary rule value (#464).', $value));
        }
    }

    /**
     * Posts one row through the endpoint and returns the price that was stored.
     *
     * A fresh product and price list per call, because the endpoint upserts: reusing one row would
     * mean each assertion was reading the previous row's leftovers as often as its own answer.
     */
    private function save(
        FunctionalTester $I,
        string $token,
        string $sku,
        string $defaultPrice,
        string $originalPrice,
        string $ruleType,
        string $ruleValue,
    ): string {
        $product = $this->makeProduct($I, $sku, $defaultPrice, $originalPrice);
        $priceList = $this->makePriceList($I, 'List ' . $sku);

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => self::POSTED,
            'rule_type' => $ruleType,
            'rule_value' => $ruleValue,
        ]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok'], 'The endpoint refused the row outright.');

        return (string) $response['price'];
    }

    /**
     * Runs one rule over the whole base-resolution table through BOTH endpoints and compares them.
     *
     * $expected is given the resolved base and returns the figure the table predicts, so the two
     * endpoints are pinned to an independently stated answer rather than merely to each other.
     *
     * The last two rows — the ones with no usable base at all — are where the two are deliberately
     * allowed to differ, and the difference is asserted rather than skipped: updatePrice() keeps the
     * figure the client posted, and bulk apply, which has no posted figure, leaves the row's stored
     * price alone (here that is a freshly created row, so the column default of 0.00).
     *
     * @param callable(float): string $expected
     */
    private function assertBulkMatchesSingleAcrossTheTable(
        FunctionalTester $I,
        string $prefix,
        string $ruleType,
        string $ruleValue,
        callable $expected,
    ): void {
        $singleToken = $this->grabPriceToken($I, 'price-update');
        $bulkToken = $this->grabPriceToken($I, 'bulk-apply');

        foreach ($this->baseResolutionTable() as $index => [$name, $defaultPrice, $originalPrice, $base]) {
            $single = $this->save($I, $singleToken, $prefix . '-S-' . $index, $defaultPrice, $originalPrice, $ruleType, $ruleValue);
            $bulk = $this->bulkApply($I, $bulkToken, $prefix . '-B-' . $index, $defaultPrice, $originalPrice, $ruleType, $ruleValue);

            if ($base === '') {
                $I->assertSame(self::POSTED, $single, sprintf('%s %s over "%s" keeps the posted figure when there is no base.', $ruleType, $ruleValue, $name));
                $I->assertSame('0.00', $bulk, sprintf('%s %s over "%s" has no posted figure to keep, so the new row keeps its default price.', $ruleType, $ruleValue, $name));

                continue;
            }

            $I->assertSame($expected((float) $base), $bulk, sprintf('Bulk %s %s over "%s" (#464).', $ruleType, $ruleValue, $name));
            $I->assertSame($single, $bulk, sprintf('Bulk and single %s %s over "%s" must store the same price.', $ruleType, $ruleValue, $name));
        }
    }

    /**
     * The bulk twin of save(): one fresh product, one fresh price list, and a bulk apply narrowed to
     * that single product id through the same filters[...] the grid forwards. Returns what was
     * stored, because bulk apply reports counts rather than prices.
     */
    private function bulkApply(
        FunctionalTester $I,
        string $token,
        string $sku,
        string $defaultPrice,
        string $originalPrice,
        string $ruleType,
        string $ruleValue,
    ): string {
        $product = $this->makeProduct($I, $sku, $defaultPrice, $originalPrice);
        $priceList = $this->makePriceList($I, 'List ' . $sku);

        $this->sendBulkApply($I, $token, $product->getId(), $priceList->getId(), $ruleType, $ruleValue);

        return $this->storedPrice($I, $product->getId(), $priceList->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function sendBulkApply(
        FunctionalTester $I,
        string $token,
        ?int $productId,
        ?int $priceListId,
        string $ruleType,
        string $ruleValue,
    ): array {
        return $this->sendBulkApplyField($I, $token, $productId, $priceListId, 'both', $ruleType, $ruleValue);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendBulkApplyField(
        FunctionalTester $I,
        string $token,
        ?int $productId,
        ?int $priceListId,
        string $field,
        string $ruleType,
        string $ruleValue,
    ): array {
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceListId,
            'field' => $field,
            'type' => $ruleType,
            'value' => $ruleValue,
            // The grid forwards its active filters so "apply to all pages" only touches what is on
            // screen; here it narrows the apply to the one product the case is about.
            'filters' => ['id' => (string) $productId],
        ]);
        $I->seeResponseCodeIsSuccessful();

        /** @var array<string, mixed> $response */
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok'], 'The bulk endpoint refused the row outright.');

        return $response;
    }

    /**
     * The stored price, read straight off the connection.
     *
     * Deliberately not grabEntityFromRepository(): a case that seeds a ProductPricing before the
     * request already has that object in the EntityManager's identity map, and Doctrine hands the
     * cached instance back on hydration rather than overwriting its fields — so a row the endpoint
     * had genuinely rewritten would still read as the figure the test seeded, and "the price was
     * left standing" would pass whether or not it was true. SQL sees only what was committed.
     *
     * Normalised to two decimals because SQLite returns decimal columns without trailing zeroes, and
     * every assertion here is about the figure rather than its spelling.
     */
    private function storedPrice(FunctionalTester $I, ?int $productId, ?int $priceListId): string
    {
        $connection = $I->grabService('doctrine.orm.entity_manager')->getConnection();
        $price = $connection->fetchOne(
            'SELECT price FROM product_pricing WHERE product_id = ? AND price_list_id = ?',
            [$productId, $priceListId],
        );

        return number_format((float) $price, 2, '.', '');
    }

    private function loginAsAdmin(FunctionalTester $I, string $email): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeProduct(
        FunctionalTester $I,
        string $sku,
        string $defaultPrice,
        string $originalPrice,
    ): ProductCore {
        $product = (new ProductCore())->setSku($sku)->setName('Base Resolution ' . $sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        if ($defaultPrice !== '') {
            $product->setDefaultPrice($defaultPrice);
        }
        if ($originalPrice !== '') {
            $product->setOriginalPrice($originalPrice);
        }
        $I->haveInRepository($product);

        return $product;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    /** The price page mints one token per write endpoint onto a single hidden element. */
    private function grabPriceToken(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }
}
