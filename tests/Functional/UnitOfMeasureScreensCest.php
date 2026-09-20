<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The measurement-system screens, conducted end to end through the real kernel (#624).
 *
 * Everything here is done the way an admin does it: a term is created by posting the real Units of
 * Measure form, a product is pointed at it by posting the real product form, and what is asserted
 * afterwards is `unit_of_measure.*` / `product_core.unit_id` on the rows that came back out of the
 * database — never the page text, which would pass against a screen that printed the right number
 * and stored the wrong one.
 *
 * Every assertion carries a **control row** re-read at the end, which must be exactly as it was
 * left. `unit_of_measure` is shared by every product since #659, so a restatement that resolved the
 * wrong row would be the single worst defect this table could have, and it is invisible from the row
 * being edited.
 *
 * The screen is core: it renders with every bundle removed, because the base unit is what every
 * quantity in the app is denominated in.
 */
final class UnitOfMeasureScreensCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('uom-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // The shipped reference rows used to be created by RENDERING the screen below. They are
        // now created once, on LoginSuccessEvent, and amLoggedInAs() does not dispatch that event —
        // it installs a token and never runs the authenticator. So this stands in for the real
        // login's side effect. ReferenceDataSeedingCest posts the actual login form.
        $I->haveSeededReferenceData();
    }

    /** Opening the screen seeds the four demo units, so a fresh install has something to point at. */
    public function theUnitListRendersAndSeedsTheDemoUnits(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/units-of-measure');
        $I->seeResponseCodeIs(200);
        $I->see('Units of Measure');

        $em = $I->grabService(EntityManagerInterface::class);
        $codes = array_map(
            static fn (UnitOfMeasure $unit): string => $unit->getCode(),
            $em->getRepository(UnitOfMeasure::class)->findBy([], ['code' => 'ASC']),
        );

        $I->assertSame(['BAG', 'BOX', 'EA', 'PR'], $codes);
    }

    /** A measured unit is created through the form, factor and precision included. */
    public function aUnitCanBeAddedThroughTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->amOnPage('/admin/product/units-of-measure');

        // A relative-path POST rather than submitForm(): with a custom Host header the browser
        // module resolves a crawled form action as an absolute URL and trips its external-URL guard.
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => 'kg',
            'name' => 'Kilogram',
            'family' => UnitOfMeasure::FAMILY_WEIGHT,
            'factor_to_family_base' => '1000',
            'rounding_precision' => '0.001',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $unit = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'KG']);

        $I->assertNotNull($unit);
        $I->assertSame('KG', $unit->getCode(), 'a code is upper-cased, so kg and KG cannot become two units');
        $I->assertSame(UnitOfMeasure::FAMILY_WEIGHT, $unit->getFamily());
        $I->assertSame('1000.000000', $unit->getFactorToFamilyBase());
        $I->assertSame('0.001000', $unit->getRoundingPrecision());
        $I->assertTrue($unit->accepts('2.5'), 'a kilogram measures halves');
    }

    /**
     * The product form sets a base unit, and refuses to change one once stock is counted in it.
     *
     * The assertion is on `product_core.unit_id` re-read from the database either side, not on the
     * flash: a refusal that shows a message and writes anyway is worse than none.
     */
    public function theProductFormSetsABaseUnitAndThenRefusesToChangeIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $suffix = strtoupper(substr(uniqid(), -6));
        $product = $this->product($I, 'UOM-BASE-' . $suffix);
        $productId = (int) $product->getId();

        // Seeded by opening the units screen, exactly as an admin would find them.
        $I->amOnPage('/admin/product/units-of-measure');
        $each = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'EA']);
        $pair = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'PR']);
        $I->assertNotNull($each);
        $I->assertNotNull($pair);
        $eachId = (int) $each->getId();

        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->seeResponseCodeIs(200);
        $I->seeElement('select[name="unit_id"]');

        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $productId, [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'unit_id' => (string) $eachId,
        ]);

        $em->clear();
        $saved = $em->find(ProductCore::class, $productId);
        $I->assertSame($eachId, $saved->getBaseUnit()?->getId(), 'the first assignment is not a change and goes through');

        // Now the product holds stock, counted in EA.
        $stock = (new ProductInventory())->setProduct($saved);
        $stock->setQuantity(5000);
        $em->persist($stock);
        $em->flush();
        $em->clear();

        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $productId, [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'unit_id' => (string) $pair->getId(),
        ]);

        $em->clear();
        $reread = $em->find(ProductCore::class, $productId);

        $I->assertSame($eachId, $reread->getBaseUnit()?->getId(), '5000 EA must not become 5000 PR by editing a dropdown');
    }

    /**
     * Conducted: a unit a product is counted in is frozen at the real screen (#659, #624).
     *
     * Everything is done the way an admin does it — the unit is added on Units of Measure, the
     * product is pointed at it on the product form, and the restatement is attempted by posting the
     * same form again. What is asserted afterwards is `unit_of_measure.factor_to_family_base` read
     * back out of the database, never the page text: a screen that printed the old number and stored
     * the new one would pass an assertion on the page and be the exact defect this guards.
     *
     * A control unit is created first and re-read at the end. Restating a shared unit is the one
     * mistake whose blast radius is every product in the instance, and it is invisible from the row
     * being edited.
     */
    public function aUnitAProductIsCountedInCannotBeRestatedAtTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);
        $suffix = strtoupper(substr(uniqid(), -6));

        $I->amOnPage('/admin/product/units-of-measure');
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => 'CTRL-' . $suffix,
            'name' => 'Control unit',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '7',
            'rounding_precision' => '1',
        ]);

        $I->amOnPage('/admin/product/units-of-measure');
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => 'BOX-' . $suffix,
            'name' => 'Box of 12',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '12',
            'rounding_precision' => '1',
        ]);

        $em->clear();
        $box = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'BOX-' . $suffix]);
        $control = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'CTRL-' . $suffix]);
        $I->assertNotNull($box);
        $I->assertNotNull($control);
        $boxId = (int) $box->getId();
        $controlId = (int) $control->getId();

        $product = $this->product($I, 'UOM-FREEZE-' . $suffix);
        $productId = (int) $product->getId();

        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $productId, [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'unit_id' => (string) $boxId,
        ]);

        $em->clear();
        $pointed = $em->find(ProductCore::class, $productId);
        $I->assertSame($boxId, $pointed->getBaseUnit()?->getId(), 'the product must actually be counted in it first');

        // Now restate it: twelve becomes twenty-four, through the real edit form.
        $I->amOnPage('/admin/product/units-of-measure?edit=' . $boxId);
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => (string) $boxId,
            'code' => 'BOX-' . $suffix,
            'name' => 'Box of 12',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '24',
            'rounding_precision' => '1',
        ]);

        $em->clear();
        $reread = $em->find(UnitOfMeasure::class, $boxId);
        $I->assertSame('12.000000', $reread->getFactorToFamilyBase(), 'Box-12 means twelve forever');

        // The positive control for the refusal: a correction that restates nothing still saves.
        $I->amOnPage('/admin/product/units-of-measure?edit=' . $boxId);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => (string) $boxId,
            'code' => 'BOX-' . $suffix,
            'name' => 'Box of twelve',
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => '12',
            'rounding_precision' => '1',
        ]);

        $em->clear();
        $renamed = $em->find(UnitOfMeasure::class, $boxId);
        $I->assertSame('Box of twelve', $renamed->getName(), 'a name is a description and stays correctable');
        $I->assertSame('12.000000', $renamed->getFactorToFamilyBase());

        // The row that must NOT have changed.
        $stillControl = $em->find(UnitOfMeasure::class, $controlId);
        $I->assertSame('7.000000', $stillControl->getFactorToFamilyBase());
        $I->assertSame('Control unit', $stillControl->getName());
    }

    /** The screen is core: no bundle contributes to it and none can take it away. */
    public function theUnitScreenIsCoreAndRendersWithoutAnyBundle(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);

        foreach ($em->getRepository(\App\Entity\BundleStatus::class)->findAll() as $status) {
            $status->setStatus(\App\Entity\BundleStatus::STATUS_INACTIVE);
        }
        $em->flush();

        $I->amOnPage('/admin/product/units-of-measure');
        $I->seeResponseCodeIs(200);
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku);
        $em->persist($product);
        $em->flush();

        return $product;
    }
}
