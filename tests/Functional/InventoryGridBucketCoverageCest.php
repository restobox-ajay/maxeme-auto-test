<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;
use App\Service\Inventory\InventoryGridColumns;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Every quantity `product_inventory` holds is reachable on `/admin/inventory`, and Available adds
 * up from what is on the page (#36).
 *
 * ## What went wrong without this
 *
 * The grid rendered seven of fourteen buckets. `reserved`, `incoming`, `quarantine`, `transfer_in`,
 * `transfer_out` and `write_off` were invisible, and four of those six ARE terms in
 * {@see ProductInventory::getAvailableQuantity()}. On the data the screen was built against the
 * arithmetic still worked — 100 − 4 − 4 = 92 — but only because every hidden bucket happened to be
 * zero. The moment one is not, the page states a figure that adds up from nothing on it, with
 * nothing to click to find out why.
 *
 * Nobody did anything wrong twice. The screen was written for the core buckets; then procurement
 * added `incoming`, receiving added `quarantine`, warehouse ops added the transfer pair, and each
 * of those changes was complete and correct in its own bundle. What was missing was anything that
 * would notice.
 *
 * ## Why this discovers its subjects instead of listing them
 *
 * A test naming the fourteen buckets in an array passes forever while the fifteenth quietly skips
 * the rule — which is exactly how the screen got to seven. So the subjects come out of Doctrine's
 * metadata: every field on ProductInventory mapped with the application's own `quantity` type is a
 * subject, and a bucket a bundle adds next year is a subject the day it is mapped. The repo already
 * has this habit twice — `ProductBaseUnitService::quantityColumns()` sweeps the entity map, and
 * `AdminListScreenConventionsCest` takes its screens from the router — and
 * `EveryDocumentDeclaresItsContractTest` is the shape followed here: discover, classify, assert,
 * and put every exclusion in a named list with a reason that is itself asserted to still be true.
 *
 * ## The three halves
 *
 * 1. Every discovered field is claimed by a column in {@see InventoryGridColumns}, or is an argued
 *    exception. This half needs no browser and fails the moment the entity gains a bucket.
 * 2. Every one of those columns actually RENDERS, carrying that bucket's own figure — asserted by
 *    driving the real screen with distinct values in every bucket, so a column showing its
 *    neighbour's number fails as loudly as a column that is missing. A registry the template does
 *    not read would otherwise satisfy (1) and change nothing.
 * 3. Available equals the signed sum of the cells on the page. That is the defect stated as an
 *    invariant, and it is checked against non-zero hidden buckets specifically, because with every
 *    hidden bucket at zero the broken screen passed too.
 *
 * @group bundle-agnostic
 *
 * Tagged for the bundles-off run (#562): with the optional inventory bundles Inactive the
 * bundle-gated terms leave getAvailableQuantity() and the page has to stop counting them in the
 * same breath. Reading the effective sign off the rendered cell rather than off the registry is
 * what makes that assertion hold in both configurations.
 */
final class InventoryGridBucketCoverageCest
{
    /**
     * Quantity-typed fields on ProductInventory that are NOT buckets this grid must show as one,
     * with the argument for each.
     *
     * The opposite of a subject list, and the distinction is the whole point: discovery is what
     * makes a NEW bucket fail, and this is the small, argued set that has been examined and found
     * to be something other than a quantity of stock held here. Each entry is asserted below to
     * STILL be true — still mapped, still typed as a quantity, and still not claimed as a bucket
     * column — so an entry that stops describing reality fails just as loudly as a missing one.
     *
     * Nothing may be added here without a sentence saying what the field is INSTEAD.
     *
     * @var array<string, string>
     */
    private const NOT_A_BUCKET = [
        'maxBackorderQuantity' =>
            'is a configured CEILING, not a quantity held. Every other field swept up here answers'
            . ' "how many units are in this state at this warehouse right now", and the answer moves'
            . ' when goods or documents move. This one answers "how many may we promise beyond'
            . ' stock", it is typed by an admin, it is nullable because NO CAP is a real and common'
            . ' setting, and nothing in the application ever adds to it or subtracts from it — the'
            . ' bucket it caps is `backorderedQuantity`, which is a bucket and has its own column.'
            . ' Giving it a bucket column would put a setting in a row of stock figures and invite'
            . ' the reading that a cap of 50 means 50 units of something. It IS on the screen, as'
            . ' one of the four backorder controls #36 broke out of the stacked cell — an editable'
            . ' control, which is what it is',
    ];

    /**
     * The grid renders every bucket the entity holds, or names the ones it does not.
     *
     * Half one: answerable from the mapping alone, so it fails on the commit that adds a bucket
     * rather than on whatever later notices the screen is short.
     */
    public function everyQuantityBucketOnTheEntityHasAGridColumn(FunctionalTester $I): void
    {
        $columns = $this->columns($I);
        $claimed = $columns->bucketFields();

        $unclaimed = [];
        foreach ($this->quantityFields($I) as $field) {
            if (isset($claimed[$field]) || isset(self::NOT_A_BUCKET[$field])) {
                continue;
            }

            $unclaimed[] = $field;
        }

        $I->assertSame([], $unclaimed, sprintf(
            "These quantity fields on %s reach no column on /admin/inventory:\n  %s\n\n"
            . "Every quantity the entity holds has to be reachable on the screen that reads it, or"
            . " Available states a figure that adds up from nothing on the page — which is exactly"
            . " what #36 was. Give each one an entry in %s (the sign it carries in"
            . " getAvailableQuantity(), 0 if availability deliberately ignores it), or, if it is not"
            . " a quantity of stock held here at all, add it to NOT_A_BUCKET with a sentence saying"
            . " what it is instead.",
            ProductInventory::class,
            implode("\n  ", $unclaimed),
            InventoryGridColumns::class,
        ));
    }

    /**
     * An exception that stopped being true reads as a considered decision while describing nothing.
     */
    public function theNamedExceptionsAreStillExcluded(FunctionalTester $I): void
    {
        $fields = $this->quantityFields($I);
        $claimed = $this->columns($I)->bucketFields();

        foreach (self::NOT_A_BUCKET as $field => $why) {
            $I->assertContains($field, $fields, sprintf(
                'ProductInventory::$%s is listed as a quantity field that is not a bucket, but it is'
                . ' not a quantity field any more (or was renamed). Remove the entry rather than'
                . ' leaving it to describe nothing.',
                $field,
            ));

            $I->assertArrayNotHasKey($field, $claimed, sprintf(
                'ProductInventory::$%s now has a bucket column, so the exception recorded here —'
                . ' "%s" — is out of date. Remove it from NOT_A_BUCKET.',
                $field,
                $why,
            ));

            $I->assertNotSame('', trim($why), sprintf('The exception for %s has no reason beside it.', $field));
        }
    }

    /**
     * Guards the discovery itself.
     *
     * A conformance test whose subject list quietly empties out passes instantly and proves
     * nothing. Fourteen buckets plus the cap is what the entity holds today; the floor is set below
     * that rather than at it, so adding a bucket does not fail here as well as everywhere it should.
     */
    public function theDiscoveryFindsTheBucketsToCheck(FunctionalTester $I): void
    {
        $fields = $this->quantityFields($I);

        $I->assertGreaterThan(
            10,
            count($fields),
            'Only ' . count($fields) . ' quantity fields discovered on ProductInventory. The mapping'
            . ' or the `quantity` Doctrine type changed under this test, and every assertion above is'
            . ' now passing over almost nothing.',
        );

        $I->assertNotEmpty(
            $this->columns($I)->bucketFields(),
            'The grid registry claims no bucket fields at all.',
        );
    }

    /**
     * Half two and half three, on the real screen: every bucket column carries its OWN figure, and
     * the signed cells add up to Available.
     *
     * Each bucket is given a different number, set through Doctrine's own metadata rather than a
     * hand-written list of setters — so a fifteenth bucket is stocked, rendered and checked here
     * without anybody editing this method. A column rendering its neighbour's value fails: that is
     * not hypothetical, it is #627, where `sales_hold` rendered in the Hold column and `hold` in
     * Sales Hold for as long as anybody cared to look, green the whole time behind `see('50')`.
     */
    public function everyBucketColumnRendersItsOwnFigureAndAvailableAddsUp(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Coverage Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku('COVERAGE-SKU')->setName('Coverage Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $stocked = $this->stockEveryBucket($I, $product, $warehouse);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->chooseEveryColumn($I);

        $columns = $this->columns($I);
        $registry = $columns->all();

        // Re-read from the database rather than trusting the entity the fixture built or the
        // response's own opinion (#624). getAvailableQuantity() on the reloaded row carries the
        // bundle gate flags this instance actually has, which is what makes the sum below correct
        // under the bundles-off run as well.
        $stored = $I->grabEntityFromRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);

        $sum = 0;
        $checked = 0;

        foreach ($registry as $key => $column) {
            if ($column['kind'] !== InventoryGridColumns::KIND_BUCKET) {
                continue;
            }

            $selector = 'td[data-column="' . $key . '"]';

            // Presence and value in one assertion, so a bucket that renders nothing at all fails
            // by NAME rather than as a bare "element not found" naming a CSS selector. Which bucket
            // has gone missing is the whole of what this test has to say.
            $rendered = $I->grabMultiple($selector);

            $I->assertCount(1, $rendered, sprintf(
                'The %s bucket (product_inventory.%s) has no cell on /admin/inventory. It is a'
                . ' quantity this warehouse holds%s, and a screen that does not show it is the'
                . ' screen #36 was filed against.',
                $column['label'],
                $column['field'],
                $column['sign'] === 0 ? '' : ' AND a term in getAvailableQuantity()',
            ));

            $I->assertSame(
                (string) $stocked[$column['field']],
                trim($rendered[0]),
                sprintf(
                    'The %s column must render product_inventory.%s and nothing else. Every bucket'
                    . ' was stocked with a different number precisely so a column showing its'
                    . ' neighbour\'s figure fails here.',
                    $column['label'],
                    $column['field'],
                ),
            );

            // The sign the CELL declares, not the one the registry nominally carries: a
            // bundle-gated term leaves getAvailableQuantity() the moment its bundle goes Inactive,
            // and the page has to stop counting it in the same breath.
            $sum += ((int) $I->grabAttributeFrom($selector, 'data-avail-sign')) * $stocked[$column['field']];
            ++$checked;
        }

        $I->assertGreaterThan(10, $checked, 'Only ' . $checked . ' bucket columns were found on the page.');

        $available = trim($I->grabTextFrom('td[data-column="available"] strong'));

        $I->assertSame(
            $I->grabService(DisplayNumber::class)->qty($stored->getAvailableQuantity()),
            $available,
            'The Available cell must be the entity\'s own getAvailableQuantity(), re-read from the database.',
        );

        $I->assertSame(
            (string) $sum,
            $available,
            'Available does not add up from the columns on the page. That is the defect #36 exists'
            . ' to end, and it is being asserted here with every hidden bucket NON-zero — with them'
            . ' all at zero the broken screen passed this too.',
        );

        // Nothing is off page when every column is shown. If this is ever non-zero here, the
        // reconciliation above was measured against a sum that quietly excused its own gap.
        $I->assertSame(
            '0',
            $I->grabAttributeFrom('td[data-column="available"]', 'data-off-page'),
            'With every column chosen there is nothing left off the page for Available to hide behind.',
        );
    }

    /**
     * The owner's own scenario: reserved, quarantine and transfer out all NON-zero, on the columns
     * an admin actually opens the page on.
     *
     * This is the defect stated in the smallest form that shows it. Before #36 this row read
     * Starting 100 and Available 81 with nothing on the page accounting for the missing 19 — the
     * seven columns that existed summed to 100, and the two buckets carrying the gap were not among
     * them. Nothing was wrong with the arithmetic; the terms were simply invisible.
     *
     * Reserved is the third and the interesting one. 40 units sit in it and Available does NOT move,
     * because nothing in this application has ever written that column and it has never been a term
     * in getAvailableQuantity(). A person who assumes otherwise reads Available as 40 short and goes
     * looking for stock that is not missing. The column says 40 and says "not counted" in the same
     * breath, which is the only way to answer that.
     *
     * The expectation is built from the signs the CELLS declare rather than fixed at 81, and that is
     * not a hedge: under the bundles-off run (#562) quarantine and transfer out genuinely leave
     * getAvailableQuantity(), the columns keep their figures because nothing is ever zeroed, and the
     * page has to stop counting them in the same breath. Both configurations are asserted by the
     * same three lines, and the number is still checked against the database either way.
     */
    public function availableReconcilesOnTheDefaultColumnsWithHiddenBucketsNonZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Reconcile Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku('RECONCILE-SKU')->setName('Reconcile Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(100);
        $inventory->setReservedQuantity(40)->setQuarantineQuantity(12)->setTransferOutQuantity(7);
        $I->haveInRepository($inventory);

        $I->haveHttpHeader('Host', 'admin.localhost');

        // No chooser call: this is the screen as it opens, on the default column set, which is the
        // only version of it most people will ever see.
        $I->amOnPage('/admin/inventory');
        $I->seeResponseCodeIsSuccessful();

        // The three buckets are ON THE PAGE with their own figures. That alone is the half of the
        // issue the owner could see: before this they were not.
        $I->assertSame('100', trim($I->grabTextFrom('td[data-column="starting"]')), 'product_inventory.quantity');
        $I->assertSame('40', trim($I->grabTextFrom('td[data-column="reserved"]')), 'product_inventory.reserved_quantity');
        $I->assertSame('12', trim($I->grabTextFrom('td[data-column="quarantine"]')), 'product_inventory.quarantine_quantity');
        $I->assertSame('7', trim($I->grabTextFrom('td[data-column="transfer_out"]')), 'product_inventory.transfer_out_quantity');

        $sign = static fn (string $key): int => (int) $I->grabAttributeFrom('td[data-column="' . $key . '"]', 'data-avail-sign');

        $I->assertSame(1, $sign('starting'), 'Starting is what everything else is measured against');
        $I->assertSame(
            0,
            $sign('reserved'),
            'Reserved is not a term in getAvailableQuantity() and never has been. If this ever becomes'
            . ' -1, something started writing the column and the page has to start subtracting it.',
        );

        $expected = 100 + ($sign('quarantine') * 12) + ($sign('transfer_out') * 7);

        $stored = $I->grabEntityFromRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);

        $I->assertSame(number_format($expected, 4, '.', ''), $stored->getAvailableQuantity(), 're-read from the database, not from the response');
        $I->assertSame(
            (string) $expected,
            trim($I->grabTextFrom('td[data-column="available"] strong')),
            'Available has to be exactly what the visible columns come to. With the bundles Active that'
            . ' is 100 − 12 quarantined − 7 transferred out = 81, and the 40 reserved changes nothing.',
        );

        // Nothing is hiding off the page: every term in the sum is one of the default columns, which
        // is what makes the default set the reconciliation set rather than "roughly what was here".
        $I->assertSame(
            '0',
            $I->grabAttributeFrom('td[data-column="available"]', 'data-off-page'),
            'the default columns cover every term in the sum, or Available cannot be reconciled from the screen as it opens',
        );

        $working = $I->grabAttributeFrom('td[data-column="available"]', 'title');
        $I->assertStringStartsWith('100 Starting', $working);
        $I->assertStringEndsWith('= ' . $expected, $working);
        $I->assertStringNotContainsString(
            'Reserved',
            $working,
            'a quantity availability does not look at has no business in the working that explains it',
        );
    }

    /**
     * Hiding a term does not break the reconciliation; it makes the grid SAY what is missing.
     *
     * The chooser is what makes fourteen buckets bearable and is also the one way the defect could
     * come back: hide a term on a row that has units in it and Available is short of everything on
     * screen, exactly as it was before. So the gap is stated on the cell, named, and asserted here.
     *
     * Pending is the term hidden, rather than one of the more interesting bundle-owned buckets,
     * precisely because it is NOT gated: it is in getAvailableQuantity() in every configuration, so
     * this reads the same under the bundles-off run.
     */
    public function aChosenColumnSetThatHidesATermSaysSoOnTheAvailableCell(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Partial Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku('PARTIAL-SKU')->setName('Partial Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(200);
        $inventory->adjustPending(8)->adjustApproved(5);
        $I->haveInRepository($inventory);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->chooseColumns($I, ['starting', 'approved', 'available']);

        $stored = $I->grabEntityFromRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);

        // 200 − 5 approved − 8 pending, and Pending is not on the page.
        $I->assertSame('187.0000', $stored->getAvailableQuantity(), 'the fixture, re-read from the database');
        $I->assertSame('200', trim($I->grabTextFrom('td[data-column="starting"]')));
        $I->assertSame('5', trim($I->grabTextFrom('td[data-column="approved"]')));
        $I->dontSeeElement('td[data-column="pending"]');
        $I->assertSame('187', trim($I->grabTextFrom('td[data-column="available"] strong')));

        $I->assertSame(
            '-8',
            $I->grabAttributeFrom('td[data-column="available"]', 'data-off-page'),
            '200 − 5 is 195 on the page against an Available of 187, and the missing 8 has to be stated rather than left to be found.',
        );
        $I->assertStringContainsString(
            'Pending',
            $I->grabTextFrom('td[data-column="available"] .inventory-off-page-note'),
            'and the bucket carrying it has to be named, or the note only says that something is wrong.',
        );
    }

    // ── Fixture and plumbing ────────────────────────────────────────────────────────────────────

    /**
     * A different number in every quantity field, written through reflection off the mapping.
     *
     * Reflection rather than named setters on purpose: a hand-written list of setters here would be
     * the same hand-kept list this whole file exists to avoid, one layer down, and a bucket added to
     * the entity would arrive in the test unstocked and silently assert 0 === 0.
     *
     * @return array<string, int> field => the value it now holds
     */
    private function stockEveryBucket(FunctionalTester $I, ProductCore $product, Warehouse $warehouse): array
    {
        $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);

        $values = [];
        $step = 0;
        foreach ($this->quantityFields($I) as $field) {
            // Distinct, all positive, and none a substring of another at these magnitudes.
            $value = 101 + (++$step * 7);
            $property = new \ReflectionProperty(ProductInventory::class, $field);
            $property->setValue($inventory, $value);
            $values[$field] = $value;
        }

        $I->haveInRepository($inventory);

        return $values;
    }

    /** Drives the real chooser form with every column ticked. */
    private function chooseEveryColumn(FunctionalTester $I): void
    {
        $this->chooseColumns($I, array_keys($this->columns($I)->all()));
    }

    /**
     * Saves a column selection through the screen's own form, then returns on the reloaded grid.
     *
     * The action and the CSRF token are read off the rendered form rather than typed here, and the
     * POST goes through sendFormPostRequest() — the suite's stand-in for what a browser with
     * JavaScript turned off sends when a submit button is pressed. submitForm() cannot be used at
     * all on an admin-host page: the crawler resolves the action against the Host header and the
     * module then refuses it as an external URL (see the helper's own docblock, and
     * AdminCompanyInfoRegionCest).
     *
     * @param list<string> $keys
     */
    private function chooseColumns(FunctionalTester $I, array $keys): void
    {
        $I->amOnPage('/admin/inventory');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest($I->grabAttributeFrom('.inventory-column-chooser form', 'action'), [
            '_token' => $I->grabAttributeFrom('.inventory-column-chooser form input[name="_token"]', 'value'),
            'columns' => $keys,
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('table.wide-price-table');
    }

    /**
     * Every field on ProductInventory mapped with the application's own `quantity` Doctrine type.
     *
     * That type — `App\Doctrine\Type\QuantityType`, NUMERIC(14,4) in the column and an int above it
     * — is what the application itself uses to say "this holds a number of goods", so it is the
     * right question to ask rather than matching on a name. Matching `*Quantity` would miss
     * `quantity` and `manualAdjustment`, which are two of the fourteen.
     *
     * @return list<string>
     */
    private function quantityFields(FunctionalTester $I): array
    {
        $metadata = $I->grabService(EntityManagerInterface::class)->getClassMetadata(ProductInventory::class);
        \assert($metadata instanceof ClassMetadata);

        $fields = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ($mapping->type === 'quantity') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function columns(FunctionalTester $I): InventoryGridColumns
    {
        return $I->grabService(InventoryGridColumns::class);
    }

    /** @see AdminInventoryCest::loginAsAdmin() for why the login form itself is skipped. */
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('inventory-coverage@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }
}
