<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A product's unit is DECLARED on the product form now, not typed (#601), conducted per #624.
 *
 * The form used to carry two controls for one fact: a free-text `unit` box and, further down, the
 * Base Unit select #643 added. Both were writable and they could disagree — a product displaying
 * `EA` while declaring `KG`, with nothing in the app able to say which was right.
 *
 * Everything below goes through the real screen with plain form posts and a scraped CSRF token,
 * and every claim is read back out of `product_core` rather than off an entity the request already
 * mutated.
 */
final class ProductBaseUnitCest
{
    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('uom-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The four demo units, seeded the way a fresh install gets them, rather than by inserting rows
     * this test invented.
     *
     * They exist in production through Version20260909090000, but both suites build the schema from
     * the Doctrine mapping rather than by replaying migrations, so the table is there and empty.
     *
     * ## This used to open the screen, and that is exactly what stopped working
     *
     * `UnitOfMeasureController::index()` seeded `unit_of_measure` on its way to rendering, so
     * `amOnPage('/admin/product/units-of-measure')` WAS the real path — the first person to look at
     * the list created it by looking. 1970b7b0 removed that write-on-read: the rows are owned by
     * `App\Service\ReferenceData\Seeders\UnitOfMeasureSeeder` and created once, on
     * `LoginSuccessEvent`, and the screen only reads.
     *
     * `amLoggedInAs()` dispatches no such event — it installs a token straight into token storage
     * and never runs the authenticator — so the seeding has to be asked for. That is what
     * `haveSeededReferenceData()` is, and it runs the same seeder the login runs, so the rows still
     * look exactly as they do on a real install. `ReferenceDataSeedingCest` posts the real login
     * form and covers the subscriber itself.
     */
    private function seedUnits(FunctionalTester $I): void
    {
        $I->haveSeededReferenceData();
    }

    /** @return array{id: int, sku: string} */
    private function product(FunctionalTester $I, ?string $label): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $sku = 'UOM-' . strtoupper(substr(uniqid(), -8));

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Unit Declaration ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setUnit($label);
        $em->persist($product);
        $em->flush();

        return ['id' => (int) $product->getId(), 'sku' => $sku];
    }

    private function unitIdByCode(FunctionalTester $I, string $code): int
    {
        return (int) $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchOne('SELECT id FROM unit_of_measure WHERE code = ?', [$code]);
    }

    /** @return array{unit: ?string, unit_id: ?int} */
    private function storedUnit(FunctionalTester $I, int $productId): array
    {
        $row = $I->grabService('doctrine.orm.entity_manager')->getConnection()
            ->fetchAssociative('SELECT unit, unit_id FROM product_core WHERE id = ?', [$productId]);

        return [
            'unit' => $row['unit'] === null ? null : (string) $row['unit'],
            'unit_id' => $row['unit_id'] === null ? null : (int) $row['unit_id'],
        ];
    }

    /**
     * The form offers one control for the unit, and it is the declaration — not a text box.
     *
     * The absence is asserted only after the select is asserted present on the same page, so it
     * cannot pass because the page failed to render or the field was renamed (#627).
     */
    public function theProductFormDeclaresTheUnitRatherThanAskingSomebodyToTypeIt(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $product = $this->product($I, 'EA');

        $I->amOnPage('/admin/product/inventory/update/' . $product['id']);
        $I->seeResponseCodeIsSuccessful();

        // Present: the declaration, carrying the seeded units — and INSIDE the Unit of Measure
        // field, which is the whole of the second fix. The select used to sit in a "Base Unit"
        // section much further down while the U/M field held a read-only box pointing at it, so an
        // admin who found the field was told the control was somewhere else.
        $I->seeElement('select[name="unit_id"]');
        $I->seeElement(sprintf('select[name="unit_id"] option[value="%d"]', $this->unitIdByCode($I, 'BOX')));
        $I->seeElement("//label[contains(normalize-space(.), 'Unit of Measure')]//select[@name='unit_id']");

        // ONE control for the unit, and it is that one. This field has been wrong twice — first two
        // writable controls (a free-text box here, the select in a "Base Unit" section below), then
        // one control and a read-only box here pointing at it. Counting is what says neither is
        // back, and a count cannot pass vacuously the way an absence can (#627).
        $I->seeNumberOfElements('select[name="unit_id"]', 1);
        $I->dontSeeElement('input[name="unit"]');

        // And nothing points at a section that no longer exists. The text assertion above the
        // absence is its positive control: it proves text on this page is readable at all.
        $I->see('Unit of Measure');
        $I->dontSee('Declared by');
        $I->dontSeeElement('#base-unit');
    }

    /**
     * Declaring a unit through the real form moves BOTH columns, and leaves another product alone.
     *
     * `unit_id` is the declaration and `unit` is the label roughly forty readers print. The
     * point of the change is that one write moves both, so they cannot drift; the point of the
     * second product is that it moves nothing it should not.
     */
    public function declaringAUnitWritesTheDeclarationAndTheLabelTogether(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $subject = $this->product($I, 'EA');
        $bystander = $this->product($I, 'EA');
        $boxId = $this->unitIdByCode($I, 'BOX');
        $I->assertGreaterThan(0, $boxId, 'BOX is one of the four seeded units');

        $before = $this->storedUnit($I, $subject['id']);
        $I->assertSame('EA', $before['unit']);

        $I->amOnPage('/admin/product/inventory/update/' . $subject['id']);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/product/inventory/update/' . $subject['id'], [
            '_token' => $token,
            'name' => 'Unit Declaration ' . $subject['sku'],
            'sku' => $subject['sku'],
            'status' => 'Active',
            'visible' => 'Yes',
            'unit_id' => (string) $boxId,
        ]);

        $after = $this->storedUnit($I, $subject['id']);
        $I->assertSame($boxId, $after['unit_id'], 'product_core.unit_id is the unit that was declared');
        $I->assertSame('BOX', $after['unit'], 'product_core.unit followed the declaration rather than staying EA');

        // The row that must not have changed.
        $untouched = $this->storedUnit($I, $bystander['id']);
        $I->assertSame('EA', $untouched['unit'], 'the other product\'s label is untouched');
        $I->assertNull($untouched['unit_id'], 'and it declared nothing');
    }

    /**
     * A posted `unit` cannot set the label behind the declaration's back.
     *
     * The form stopped emitting the field, but a replayed post or an old bookmark still can — and
     * before this change that post would have written the label directly, recreating exactly the
     * disagreement the change removes. The declaration in the same post is what must win.
     */
    public function aPostedFreeTextUnitIsIgnoredRatherThanTrusted(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $subject = $this->product($I, 'EA');
        $boxId = $this->unitIdByCode($I, 'BOX');

        $I->amOnPage('/admin/product/inventory/update/' . $subject['id']);
        $token = (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/product/inventory/update/' . $subject['id'], [
            '_token' => $token,
            'name' => 'Unit Declaration ' . $subject['sku'],
            'sku' => $subject['sku'],
            'status' => 'Active',
            'visible' => 'Yes',
            'unit_id' => (string) $boxId,
            // What an old form, a replay, or a script would send.
            'unit' => 'SMUGGLED',
        ]);

        $after = $this->storedUnit($I, $subject['id']);
        $I->assertSame('BOX', $after['unit'], 'the label came from the declaration, not from the posted text');
        $I->assertSame($boxId, $after['unit_id']);
    }

    /** The option the rendered select actually opens on — what a browser would post untouched. */
    private function preSelectedUnitId(FunctionalTester $I): int
    {
        return (int) $I->grabAttributeFrom('select[name="unit_id"] option[selected]', 'value');
    }

    /**
     * A label that names a unit by its NAME pre-selects that unit instead of being called unmapped.
     *
     * The seeded units are code/name pairs — `EA`/`Each`, `BOX`/`Box` — and the legacy free-text
     * column holds both shapes in practice. The #601 backfill matched on CODE only, so a product
     * labelled `Each` never matched `EA`; it stayed undeclared, and the form then told the admin
     * that `Each` "is not one of the units above", which is false. The owner hit exactly this.
     *
     * The unmapped warning is asserted ABSENT here only alongside a product on which it is asserted
     * PRESENT (#627), so this cannot pass because the warning was deleted, renamed, or because the
     * page stopped rendering.
     */
    public function aLabelNamingAUnitByNameIsPreSelectedRatherThanCalledUnmapped(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $spelledOut = $this->product($I, 'Each');
        $packSize = $this->product($I, '12/Case');
        $eachId = $this->unitIdByCode($I, 'EA');
        $I->assertGreaterThan(0, $eachId, 'EA is one of the four seeded units');

        $I->amOnPage('/admin/product/inventory/update/' . $spelledOut['id']);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame($eachId, $this->preSelectedUnitId($I), 'the select opens on Each, matched by the unit\'s name');
        $I->see('Not declared yet');
        $I->dontSee('Unmapped:');

        // The positive control: the same warning, on the label that genuinely names no unit.
        $I->amOnPage('/admin/product/inventory/update/' . $packSize['id']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Unmapped:');

        // And rendering the form declared nothing. Resolving a label is a read; the write belongs
        // to a save the admin makes, and this repo does not write existing data on a GET.
        $I->assertSame(
            ['unit' => 'Each', 'unit_id' => null],
            $this->storedUnit($I, $spelledOut['id']),
            'opening the form pre-selects a unit without declaring one',
        );
    }

    /**
     * A label that names no unit at all keeps the warning, and pre-selects nothing.
     *
     * `12/Case` is the real example — it is what the product import's template guide used to
     * document the `unit` column with, and rows carrying it are in the data — and it is a PACK
     * SIZE, not a unit of measure. Widening the match from code to code-or-name must not widen it
     * into guessing. The import no longer teaches it and no longer declares anything from it
     * (ImportUnitIsNotAPackSizeCest), which is what makes this label arrive for a person to settle
     * rather than keep arriving faster than anyone can settle it.
     *
     * "Not declared yet" is asserted absent beside a product where it is asserted present (#627).
     */
    public function aPackSizeLabelIsStillUnmappedAndPreSelectsNothing(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $packSize = $this->product($I, '12/Case');
        $spelledOut = $this->product($I, 'Each');

        $I->amOnPage('/admin/product/inventory/update/' . $packSize['id']);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(0, $this->preSelectedUnitId($I), 'the select stays on "Not set" rather than guessing a unit');
        $I->see('Unmapped:');
        $I->see('12/Case');
        $I->dontSee('Not declared yet');

        // The positive control for that absence: the note does render, for a label that resolves.
        $I->amOnPage('/admin/product/inventory/update/' . $spelledOut['id']);
        $I->see('Not declared yet');
    }

    /**
     * The resolution ignores case and surrounding whitespace, on both the code and the name.
     *
     * A column an admin typed into for years holds `  ea  `, `Pr` and `pair`. Matching them exactly
     * would leave rows unmapped that name a unit perfectly clearly, which is the same defect as
     * matching on the code alone, one step smaller.
     *
     * `pair` and ` Each` are the two that can only resolve through the NAME — no seeded code spells
     * either — so they are the pair that dies under the code-only mutation.
     */
    public function resolutionIgnoresCaseAndSurroundingWhitespace(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        $cases = [
            '  ea  ' => 'EA',     // code, padded and lower-cased
            'Pr' => 'PR',         // code, wrong case
            'pair' => 'PR',       // name only: no seeded code spells PAIR
            ' Each' => 'EA',      // name only, padded
        ];

        foreach ($cases as $label => $expectedCode) {
            $product = $this->product($I, $label);
            $I->amOnPage('/admin/product/inventory/update/' . $product['id']);
            $I->seeResponseCodeIsSuccessful();

            $I->assertSame(
                $this->unitIdByCode($I, $expectedCode),
                $this->preSelectedUnitId($I),
                sprintf('the label "%s" resolves to %s', $label, $expectedCode),
            );
        }

        // The positive control for the four above: a label that resolves to nothing still does.
        $unmappable = $this->product($I, '12/Case');
        $I->amOnPage('/admin/product/inventory/update/' . $unmappable['id']);
        $I->assertSame(0, $this->preSelectedUnitId($I), 'and a pack size still resolves to nothing');
    }

    /**
     * Confirming the pre-selection by saving declares the unit — and rewrites the label to its CODE.
     *
     * Conducted per #624: the unit_id posted is the one the RENDERED form opens on, read back off
     * the page rather than computed here, so this drives what a browser with JavaScript turned off
     * would actually send when an admin presses Save without touching the field.
     *
     * `Each` becomes `EA`, because ProductBaseUnitService::syncLabel() derives the label from the
     * declared unit's code — the shape the column already holds and the shape ~40 readers print.
     * That is deliberate and it is asserted rather than tolerated: if the decision were ever
     * reversed to preserve `Each`, this is the test that has to say so.
     */
    public function confirmingThePreSelectionBySavingDeclaresTheUnit(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);
        $subject = $this->product($I, 'Each');
        $bystander = $this->product($I, 'Each');
        $eachId = $this->unitIdByCode($I, 'EA');

        $I->assertSame(['unit' => 'Each', 'unit_id' => null], $this->storedUnit($I, $subject['id']));

        $I->amOnPage('/admin/product/inventory/update/' . $subject['id']);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');
        $posted = $this->preSelectedUnitId($I);
        $I->assertSame($eachId, $posted, 'the form handed the browser Each to post back');

        $I->sendFormPostRequest('/admin/product/inventory/update/' . $subject['id'], [
            '_token' => $token,
            'name' => 'Unit Declaration ' . $subject['sku'],
            'sku' => $subject['sku'],
            'status' => 'Active',
            'visible' => 'Yes',
            // Untouched by the admin: exactly what the rendered select already held.
            'unit_id' => (string) $posted,
        ]);

        $after = $this->storedUnit($I, $subject['id']);
        $I->assertSame($eachId, $after['unit_id'], 'the save declared the unit the label already named');
        $I->assertSame('EA', $after['unit'], 'and the label became the declared unit\'s CODE');

        // The row that must not have changed: a second product with the identical label, never saved.
        $I->assertSame(
            ['unit' => 'Each', 'unit_id' => null],
            $this->storedUnit($I, $bystander['id']),
            'the other product was neither declared nor relabelled',
        );
    }

    /**
     * No stored product declares one unit and displays another.
     *
     * The data-level half of the rule its unit test states about objects. It runs here rather than
     * in PHPUnit because it needs a migrated database — and it is what would catch a row written
     * past the service by an import, a fixture, or a future caller that finds its own way to
     * `setUnit()`.
     */
    public function noStoredProductDeclaresOneUnitAndDisplaysAnother(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedUnits($I);

        $offenders = $I->grabService('doctrine.orm.entity_manager')->getConnection()->fetchAllAssociative(
            'SELECT p.id, p.sku, p.unit AS label, u.code AS declared'
            . ' FROM product_core p JOIN unit_of_measure u ON u.id = p.unit_id'
            . ' WHERE p.unit IS NULL OR UPPER(TRIM(p.unit)) <> UPPER(TRIM(u.code))',
        );

        $described = array_map(
            static fn (array $row): string => sprintf(
                '#%s %s declares %s but displays %s',
                (string) $row['id'],
                (string) ($row['sku'] ?? '(no sku)'),
                (string) $row['declared'],
                (string) ($row['label'] ?? 'NULL'),
            ),
            $offenders,
        );

        $I->assertSame([], $described, sprintf(
            "These products' unit label disagrees with the base unit they declare:\n  %s\n\n"
            . 'product_core.unit is derived from unit_id by ProductBaseUnitService::assign(), '
            . 'which is the only thing that may write either.',
            implode("\n  ", $described),
        ));
    }
}
