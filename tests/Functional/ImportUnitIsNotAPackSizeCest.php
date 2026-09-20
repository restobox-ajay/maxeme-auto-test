<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\ProductImport\ProductImportResult;
use App\Service\ProductImport\ProductImportService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A pack size in the `unit` column leaves the unit UNSET and flags the product (queue item 9),
 * conducted per #624.
 *
 * The import documented its `unit` column with the example `12/Case`, which is a pack size and not
 * a unit of measure, and wrote whatever the cell said straight onto the product. The live data
 * shows what that teaches: `Anchor Bolt Sleeve M12 (bag of 50)` imported with U/M `BAG`. If `BAG`
 * is what the product is counted in, its stock is counted in bags and every quantity ever recorded
 * against it means something other than the person entering it intended.
 *
 * The owner's ruling, which is what each test below pins:
 *
 *  1. **Accept the row.** One bad cell must not kill a 5,000-row import.
 *  2. **Leave the unit unset.** `12/Case` says a case holds twelve of something — not twelve of
 *     what, and not what the base unit is. Guessing would write a number nobody typed.
 *  3. **Flag it for a human**, on the flag the product form already carries, and COUNT the flagged
 *     rows on the import summary so forty of them cannot hide inside a green result.
 *
 * ## How these are conducted
 *
 * Every claim about the data is read back out of `product_core` and `unit_of_measure` by column,
 * with raw SQL, after the import — never off an entity the import already mutated. Each file is
 * written by the test rather than checked in, so what the import was fed is visible beside what it
 * did. Every test also carries a row that must NOT move.
 *
 * The import itself is driven through `ProductImportService::import()` directly rather than by
 * posting to `/admin/product/import`: that screen queues the run and spawns `import:process` as a
 * separate OS process, which opens its own connection and cannot see this suite's uncommitted
 * transaction — the same reason AdminProductImportCest asserts only the queueing half. The one
 * thing the SCREEN owns is driven as a screen: the flag on the product form. The unified run's
 * description gaining the flagged count is its own PHPUnit test — see
 * ProductImportUnitFlaggedSurfacesOnTheUnifiedRunTest, which this test's own tail used to assert
 * before this importer moved onto the unified framework.
 */
final class ImportUnitIsNotAPackSizeCest
{
    /** The unit catalogue these tests run against. Deliberately NOT containing BAG. */
    private const SEEDED_UNITS = ['EA' => 'Each', 'BOX' => 'Box'];

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('import-unit-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * Two units, created here rather than by opening the Units of Measure screen.
     *
     * That screen seeds four — `EA`, `BOX`, `PR` and `BAG` — and `BAG` is the value the live defect
     * was found on. A catalogue containing it would make `BAG` resolve, and these tests would then
     * be asserting nothing about a value nobody has declared. Two units is the honest shape of the
     * instance the defect arrived on: some units exist, and the one the file named is not among
     * them.
     */
    private function seedUnits(FunctionalTester $I): void
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        foreach (self::SEEDED_UNITS as $code => $name) {
            $em->persist(
                (new UnitOfMeasure())
                    ->setCode($code)
                    ->setName($name)
                    ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
                    ->setFactorToFamilyBase('1')
                    ->setRoundingPrecision('1')
            );
        }

        $em->flush();
    }

    /** A product already in the catalogue, optionally declaring one of the seeded units. */
    private function product(FunctionalTester $I, string $sku, ?string $label, ?string $declaredCode = null): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Bystander ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setUnit($label);

        if ($declaredCode !== null) {
            $unit = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $declaredCode]);
            $I->assertInstanceOf(UnitOfMeasure::class, $unit);
            $product->setBaseUnit($unit);
            $product->setUnit($unit->getCode());
        }

        $em->persist($product);
        $em->flush();

        return (int) $product->getId();
    }

    /**
     * A CSV written by this test, quoted cell by cell so `bag of 50` and `12/Case` survive intact.
     *
     * @param list<list<string>> $rows
     */
    private function csvUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_unit_') . '.csv';
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                static fn (string $cell): string => '"' . str_replace('"', '""', $cell) . '"',
                $row
            ));
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
    }

    /** @param list<list<string>> $rows */
    private function import(FunctionalTester $I, array $rows): ProductImportResult
    {
        return $I->grabService(ProductImportService::class)->import(
            $this->csvUpload($rows),
            $I->grabService('doctrine.orm.entity_manager'),
            ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']
        );
    }

    /**
     * `product_core.unit` and `product_core.unit_id` for one SKU, read back from the database.
     *
     * @return array{unit: ?string, unit_id: ?int, name: ?string, status: ?string}
     */
    private function storedProduct(FunctionalTester $I, string $sku): array
    {
        $row = $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchAssociative('SELECT unit, unit_id, name, status FROM product_core WHERE sku = ?', [$sku]);

        if ($row === false) {
            return ['unit' => null, 'unit_id' => null, 'name' => null, 'status' => null];
        }

        return [
            'unit' => $row['unit'] === null ? null : (string) $row['unit'],
            'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
            'name' => $row['name'] === null ? null : (string) $row['name'],
            'status' => $row['status'] === null ? null : (string) $row['status'],
        ];
    }

    private function unitIdByCode(FunctionalTester $I, string $code): int
    {
        return (int) $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchOne('SELECT id FROM unit_of_measure WHERE code = ?', [$code]);
    }

    private function unitRowCount(FunctionalTester $I): int
    {
        return (int) $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM unit_of_measure');
    }

    /**
     * The defect itself: a pack size imports the product and declares nothing.
     *
     * All three values are ones a real file carries — the documented example, the shape the live
     * product's name spells out, and the code that product actually arrived with. None of them
     * names a term in this instance's catalogue, and the test asserts what is IN the two columns
     * rather than only what is absent: an import that silently dropped the row would satisfy
     * "unit_id is null" just as well.
     */
    public function aPackSizeImportsTheProductWithItsUnitUnset(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        // The row that must not move: it declares EA and the file below never mentions it.
        $this->product($I, 'UNIT-BYSTANDER-1', 'EA', 'EA');
        $unitsBefore = $this->unitRowCount($I);

        $result = $this->import($I, [
            ['sku', 'name', 'status', 'unit'],
            ['UNIT-PACK-1', 'Anchor Bolt Sleeve M12 (bag of 50)', 'Active', '12/Case'],
            ['UNIT-PACK-2', 'Anchor Bolt Sleeve M10 (bag of 50)', 'Active', 'bag of 50'],
            ['UNIT-PACK-3', 'Anchor Bolt Sleeve M8 (bag of 50)', 'Active', 'BAG'],
        ]);

        // Accepted, every one of them. A refused row would also leave no unit behind.
        $I->assertSame([], $result->errors, 'a unit nobody can resolve is not an error');
        $I->assertSame(3, $result->created);
        $I->assertSame(0, $result->skipped);
        $I->assertSame(3, $result->unitFlagged, 'all three rows are counted as needing a person');

        foreach (['UNIT-PACK-1' => '12/Case', 'UNIT-PACK-2' => 'bag of 50', 'UNIT-PACK-3' => 'BAG'] as $sku => $cell) {
            $stored = $this->storedProduct($I, $sku);
            $I->assertNull($stored['unit_id'], sprintf('product_core.unit_id is unset for "%s"', $cell));
            $I->assertSame($cell, $stored['unit'], 'the file\'s own words are kept as the label a person resolves from');
            $I->assertSame('Active', $stored['status'], 'the rest of the row imported in full');
        }

        $I->assertSame(
            'Anchor Bolt Sleeve M12 (bag of 50)',
            $this->storedProduct($I, 'UNIT-PACK-1')['name'],
            'the product is in the catalogue, it simply cannot be sold by the case yet'
        );

        // Nothing was invented to make those rows resolve.
        $I->assertSame($unitsBefore, $this->unitRowCount($I), 'no unit_of_measure row was created');
        $I->assertSame(0, (int) $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM unit_of_measure WHERE code = ?', ['BAG']), 'BAG was not minted from the file');

        // The row that should NOT have changed.
        $bystander = $this->storedProduct($I, 'UNIT-BYSTANDER-1');
        $I->assertSame('EA', $bystander['unit']);
        $I->assertSame($this->unitIdByCode($I, 'EA'), $bystander['unit_id']);
    }

    /**
     * The positive control: a real unit still resolves, by code AND by name.
     *
     * Without this, "stop declaring units from the import" and "stop importing units at all" are
     * the same green. The label is asserted too, because a declaration writes both columns through
     * ProductBaseUnitService::assign() — `Box` in a file means `BOX`, exactly as it does on the
     * product form.
     */
    public function aRealUnitStillResolvesAndIsDeclared(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        // The row that must not move: a product already carrying an unresolvable label.
        $this->product($I, 'UNIT-BYSTANDER-2', '12/Case');

        $result = $this->import($I, [
            ['sku', 'name', 'status', 'unit'],
            ['UNIT-REAL-1', 'Resolved By Code', 'Active', 'EA'],
            ['UNIT-REAL-2', 'Resolved By Name', 'Active', 'Box'],
        ]);

        $I->assertSame([], $result->errors);
        $I->assertSame(2, $result->created);
        $I->assertSame(0, $result->unitFlagged, 'a unit that names a term needs nobody');

        $byCode = $this->storedProduct($I, 'UNIT-REAL-1');
        $I->assertSame($this->unitIdByCode($I, 'EA'), $byCode['unit_id'], 'product_core.unit_id holds the declared unit');
        $I->assertSame('EA', $byCode['unit']);

        $byName = $this->storedProduct($I, 'UNIT-REAL-2');
        $I->assertSame($this->unitIdByCode($I, 'BOX'), $byName['unit_id'], 'a unit named in full resolves too');
        $I->assertSame('BOX', $byName['unit'], 'the label follows the declaration rather than staying "Box"');

        // The row that should NOT have changed.
        $bystander = $this->storedProduct($I, 'UNIT-BYSTANDER-2');
        $I->assertSame('12/Case', $bystander['unit']);
        $I->assertNull($bystander['unit_id']);
    }

    /**
     * A file with good rows and bad ones imports every row, and the summary says how many need work.
     *
     * The counts are the point: a 5,000-row import that reports "4 created" and says nothing else
     * hides its forty unset units. `unitFlagged` is asserted against the rows that actually carry a
     * pack size, and the good rows are re-read to prove the flagged ones did not take them down.
     */
    public function aMixedFileImportsEveryRowAndCountsTheFlaggedOnes(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $unitsBefore = $this->unitRowCount($I);

        $result = $this->import($I, [
            ['sku', 'name', 'status', 'unit'],
            ['UNIT-MIX-1', 'Good One', 'Active', 'EA'],
            ['UNIT-MIX-2', 'Pack Size One', 'Active', '12/Case'],
            ['UNIT-MIX-3', 'Good Two', 'Active', 'BOX'],
            ['UNIT-MIX-4', 'Pack Size Two', 'Active', 'bag of 50'],
        ]);

        $I->assertSame([], $result->errors);
        $I->assertSame(4, $result->totalRows, 'no row was dropped');
        $I->assertSame(4, $result->created);
        $I->assertSame(0, $result->skipped);
        $I->assertSame(2, $result->unitFlagged, 'exactly the two rows carrying a pack size');
        $I->assertSame(2, $result->warningRows, 'and each of them is a warning, never an error');

        $I->assertSame($this->unitIdByCode($I, 'EA'), $this->storedProduct($I, 'UNIT-MIX-1')['unit_id']);
        $I->assertSame($this->unitIdByCode($I, 'BOX'), $this->storedProduct($I, 'UNIT-MIX-3')['unit_id']);
        $I->assertNull($this->storedProduct($I, 'UNIT-MIX-2')['unit_id']);
        $I->assertNull($this->storedProduct($I, 'UNIT-MIX-4')['unit_id']);
        $I->assertSame('bag of 50', $this->storedProduct($I, 'UNIT-MIX-4')['unit']);

        $I->assertSame($unitsBefore, $this->unitRowCount($I), 'still no unit_of_measure row was created');

        // "the person running it is told, on the screen that reports the run" no longer has a
        // per-feature run-log page to assert against — ProductImportService::finalize() now
        // appends the same figure onto the unified ImportRun's description instead (see that
        // method's own docblock: "must not be able to hide inside an otherwise-green run"). That
        // path only runs through the real queue+import:process, which — same as everywhere else in
        // this suite — a genuinely separate OS process can't share this test's uncommitted
        // transaction with; see ProductImportUnitFlaggedSurfacesOnTheUnifiedRunTest for that half,
        // driven the same way tests/Command/ImportProcessCommandTest.php drives kill/flock.
    }

    /**
     * No import, of any shape, creates a unit of measure.
     *
     * A unit freezes the moment a document references it (#659), so a file that could mint one
     * would make a typo permanent — `CASE-12` typed as `CASE-21` becomes a term with a ratio nobody
     * can correct. The table's row count is asserted across three files, including one naming a
     * code that looks exactly like a unit somebody would want.
     */
    public function noImportCreatesAUnitOfMeasureRow(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        $before = $this->unitRowCount($I);
        $I->assertSame(count(self::SEEDED_UNITS), $before, 'the catalogue starts as this test built it');

        $this->import($I, [
            ['sku', 'name', 'unit'],
            ['UNIT-NEW-1', 'Looks Like A Unit', 'CASE-12'],
        ]);
        $this->import($I, [
            ['sku', 'name', 'unit'],
            ['UNIT-NEW-2', 'Names A Family Base', 'KG'],
        ]);
        $this->import($I, [
            ['sku', 'name', 'unit'],
            ['UNIT-NEW-3', 'Declares A Real One', 'EA'],
        ]);

        $I->assertSame($before, $this->unitRowCount($I), 'unit_of_measure is untouched by every one of them');
        foreach (['CASE-12', 'KG'] as $code) {
            $I->assertSame(0, (int) $I->grabService('doctrine.orm.entity_manager')->getConnection()
                ->fetchOne('SELECT COUNT(*) FROM unit_of_measure WHERE code = ?', [$code]),
                sprintf('"%s" was named by a file and is still not a term', $code));
        }

        // The two that named nothing are flagged rather than declared; the one that named EA is not.
        $I->assertNull($this->storedProduct($I, 'UNIT-NEW-1')['unit_id']);
        $I->assertNull($this->storedProduct($I, 'UNIT-NEW-2')['unit_id']);
        $I->assertSame($this->unitIdByCode($I, 'EA'), $this->storedProduct($I, 'UNIT-NEW-3')['unit_id']);
    }

    /**
     * The flag is the one the product form already carried, reached through the real screen.
     *
     * This is the whole of "flag it for a human": the product opens with its Unit of Measure select
     * on "Not set", naming what the file said and what to do about it. The `Unmapped` text is
     * asserted beside the select it belongs to, so it cannot pass on a page that failed to render.
     */
    public function theImportedProductIsFlaggedOnItsProductForm(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        $this->import($I, [
            ['sku', 'name', 'status', 'unit'],
            ['UNIT-FORM-1', 'Anchor Bolt Sleeve M12 (bag of 50)', 'Active', '12/Case'],
        ]);

        $id = (int) $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchOne('SELECT id FROM product_core WHERE sku = ?', ['UNIT-FORM-1']);
        $I->assertGreaterThan(0, $id);

        $I->amOnPage('/admin/product/inventory/update/' . $id);
        $I->seeResponseCodeIsSuccessful();

        // Positive control first: the field is on the page at all.
        $I->see('Unit of Measure');
        $I->seeElement('select[name="unit_id"]');

        // The flag, and the value it is about.
        $I->see('Unmapped');
        $I->see('12/Case');

        // Nothing was pre-selected for the admin: the select opens on "Not set", which is what a
        // browser would post back untouched.
        $I->seeElement('select[name="unit_id"] option[value="0"][selected]');
        $I->seeNumberOfElements('select[name="unit_id"] option[selected]', 1);
        $I->dontSee('Not declared yet');
    }

    /**
     * The documentation stops teaching a pack size, and says what the column takes instead.
     *
     * Both halves of it: the workbook an admin downloads, asserted on the bytes the route actually
     * serves, and the import screen's own field table, which said nothing about `unit` at all while
     * the workbook taught `12/Case` for it.
     *
     * The workbook's example is read from this instance's own units now, so it can only ever show a
     * term that exists — which is why the seeded `BOX` is what appears rather than a literal
     * somebody wrote into the exporter. `12/Case` survives in both places only as the
     * counter-example, which is why the absence is asserted against the EXAMPLE CELL rather than
     * against the page.
     */
    public function theImportDocumentationStopsTeachingAPackSizeAsAUnit(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        $I->amOnPage('/admin/product/import');
        $I->seeResponseCodeIsSuccessful();
        $I->see('A pack size such as');
        $I->see('is not a unit of measure');
        $I->see('The import never creates a unit.');
        $I->seeElement("//td[code[normalize-space()='unit']]");

        $I->amOnPage('/admin/product/import/template-guide');
        $I->seeResponseCodeIsSuccessful();
        $guide = $I->grabPageSource();

        $I->assertStringNotContainsString(
            '<Data ss:Type="String">12/Case</Data>',
            $guide,
            'the unit example is no longer a pack size'
        );
        $I->assertStringContainsString(
            '<Data ss:Type="String">BOX</Data>',
            $guide,
            'it is a unit this instance really has — BOX sorts first of the two seeded here'
        );
        $I->assertStringContainsString(
            'A pack size such as &quot;12/Case&quot; is not a unit of measure',
            $guide,
            'and the Instructions sheet says so outright'
        );
        $I->assertStringContainsString('The import never creates a unit.', $guide);
    }
}
