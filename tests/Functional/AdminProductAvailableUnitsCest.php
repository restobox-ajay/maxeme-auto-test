<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Conducted: a product's available units and its default, driven through the real form (#659, #624).
 *
 * Nothing here reads the page for a number. Every assertion is `product_available_unit` and
 * `product_core.default_unit_id` re-read out of the database after a plain form POST with a scraped
 * CSRF token, because a screen that rendered the right ticks and stored the wrong rows would pass
 * every assertion made against the HTML.
 *
 * A **control product** is built first, given its own list and its own default, and re-read at the
 * end. The whole model rests on several products sharing one global vocabulary, which makes "editing
 * A moved B" both the worst thing this table could do and completely invisible from A.
 *
 * The absence assertions are paired. "The form does not offer KG" is only evidence if the same
 * render is shown to offer BOX-12 — otherwise an empty block passes it.
 */
final class AdminProductAvailableUnitsCest
{
    private string $suffix = '';

    public function _before(FunctionalTester $I): void
    {
        $this->suffix = strtoupper(substr(uniqid(), -6));
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('avu-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The list is applied, narrowed, and the default follows it — all through the screen.
     *
     * Three posts, and the database is re-read between each: what is being proved is not that one
     * save works but that a SECOND save reconciles rather than accumulating, which is where a
     * delete-and-reinsert would show up as a duplicate and a plain insert as a growing list.
     */
    public function theAvailableUnitsListIsAppliedAndNarrowedThroughTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $each = $this->unit($I, 'AVU-EA-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit($I, 'AVU-BOX12-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '12');
        $pallet = $this->unit($I, 'AVU-PAL-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '240');
        $eachId = (int) $each->getId();
        $boxId = (int) $box->getId();
        $palletId = (int) $pallet->getId();

        // The control: its own product, its own list, its own default.
        $control = $this->product($I, 'AVU-CTRL-' . $this->suffix);
        $controlId = (int) $control->getId();
        $this->saveProduct($I, $controlId, ['unit_id' => (string) $eachId]);
        $this->saveProduct($I, $controlId, [
            'unit_id' => (string) $eachId,
            'available_units_submitted' => '1',
            'available_unit_ids' => [(string) $boxId],
            'default_unit_id' => (string) $boxId,
        ]);

        $subject = $this->product($I, 'AVU-SUBJ-' . $this->suffix);
        $subjectId = (int) $subject->getId();
        $this->saveProduct($I, $subjectId, ['unit_id' => (string) $eachId]);

        // Both boxes ticked, default on the box.
        $this->saveProduct($I, $subjectId, [
            'unit_id' => (string) $eachId,
            'available_units_submitted' => '1',
            'available_unit_ids' => [(string) $boxId, (string) $palletId],
            'default_unit_id' => (string) $boxId,
        ]);

        $em->clear();
        $I->assertSame(
            ['AVU-BOX12-' . $this->suffix, 'AVU-PAL-' . $this->suffix],
            $this->storedCodes($I, $subjectId),
        );
        $I->assertSame($boxId, $em->find(ProductCore::class, $subjectId)->getDefaultUnit()?->getId());

        // Narrowed to the pallet, default cleared back to the base unit.
        $this->saveProduct($I, $subjectId, [
            'unit_id' => (string) $eachId,
            'available_units_submitted' => '1',
            'available_unit_ids' => [(string) $palletId],
            'default_unit_id' => '0',
        ]);

        $em->clear();
        $I->assertSame(['AVU-PAL-' . $this->suffix], $this->storedCodes($I, $subjectId), 'the box row is gone, not duplicated');
        $I->assertNull($em->find(ProductCore::class, $subjectId)->getDefaultUnit());

        // Blank is allowed: a product may declare nothing at all.
        $this->saveProduct($I, $subjectId, [
            'unit_id' => (string) $eachId,
            'available_units_submitted' => '1',
            'default_unit_id' => '0',
        ]);

        $em->clear();
        $I->assertSame([], $this->storedCodes($I, $subjectId));

        // The rows that must NOT have changed.
        $I->assertSame(['AVU-BOX12-' . $this->suffix], $this->storedCodes($I, $controlId));
        $I->assertSame($boxId, $em->find(ProductCore::class, $controlId)->getDefaultUnit()?->getId());
    }

    /**
     * A unit from another family is refused at the screen, and nothing is written.
     *
     * The positive control is in the same post: the quantity unit beside it must still be applied is
     * NOT asserted — a refusal is all-or-nothing on purpose, because a half-applied list written by
     * the very save that reported it was refused is worse than either outcome. So what is asserted
     * is that the list is exactly as it was before the post.
     */
    public function aUnitFromAnotherFamilyIsRefusedAtTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $each = $this->unit($I, 'AVX-EA-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit($I, 'AVX-BOX-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '6');
        $kilo = $this->unit($I, 'AVX-KG-' . $this->suffix, UnitOfMeasure::FAMILY_WEIGHT, '1000');

        $product = $this->product($I, 'AVX-SUBJ-' . $this->suffix);
        $productId = (int) $product->getId();
        $this->saveProduct($I, $productId, ['unit_id' => (string) $each->getId()]);
        $this->saveProduct($I, $productId, [
            'unit_id' => (string) $each->getId(),
            'available_units_submitted' => '1',
            'available_unit_ids' => [(string) $box->getId()],
            'default_unit_id' => '0',
        ]);

        $em->clear();
        $I->assertSame(['AVX-BOX-' . $this->suffix], $this->storedCodes($I, $productId), 'the starting state');

        $this->saveProduct($I, $productId, [
            'unit_id' => (string) $each->getId(),
            'available_units_submitted' => '1',
            'available_unit_ids' => [(string) $box->getId(), (string) $kilo->getId()],
            'default_unit_id' => '0',
        ]);

        $em->clear();
        $I->assertSame(
            ['AVX-BOX-' . $this->suffix],
            $this->storedCodes($I, $productId),
            'a weight unit on a counted product is a conversion the app cannot perform, and nothing is written',
        );
    }

    /**
     * The form offers this product's family and not the whole vocabulary.
     *
     * Asserted on the input elements by value, never with see() on a code: this feature is entirely
     * short codes and numbers, and a substring match over the page would find one inside another
     * (#627).
     */
    public function theFormOffersOnlyTheProductsOwnFamily(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $each = $this->unit($I, 'AVF-EA-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '1');
        $box = $this->unit($I, 'AVF-BOX-' . $this->suffix, UnitOfMeasure::FAMILY_QUANTITY, '12');
        $litre = $this->unit($I, 'AVF-L-' . $this->suffix, UnitOfMeasure::FAMILY_VOLUME, '1');

        $product = $this->product($I, 'AVF-SUBJ-' . $this->suffix);
        $productId = (int) $product->getId();

        // Before a base unit is declared there is no family, so there is nothing to offer at all.
        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('input[name="available_units_submitted"]');

        $this->saveProduct($I, $productId, ['unit_id' => (string) $each->getId()]);

        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->seeElement('input[name="available_units_submitted"]');
        $I->seeElement('input[name="available_unit_ids[]"][value="' . $box->getId() . '"]');
        $I->dontSeeElement('input[name="available_unit_ids[]"][value="' . $litre->getId() . '"]');
        // The base unit is available by definition and is never a row, so it is not a tick box.
        $I->dontSeeElement('input[name="available_unit_ids[]"][value="' . $each->getId() . '"]');
    }

    /** @return list<string> the codes on `product_available_unit` for this product, re-read */
    private function storedCodes(FunctionalTester $I, int $productId): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $rows = $em->createQuery(
            'SELECT u.code FROM ' . ProductAvailableUnit::class . ' a JOIN a.product p JOIN a.unit u '
            . 'WHERE p.id = :id ORDER BY u.factorToFamilyBase ASC'
        )->setParameter('id', $productId)->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /** Posts the product form, scraping the token off the page the admin is looking at. */
    private function saveProduct(FunctionalTester $I, int $productId, array $fields): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $product = $em->find(ProductCore::class, $productId);

        $I->amOnPage('/admin/product/inventory/update/' . $productId);
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $productId, [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'name' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
        ] + $fields);
    }

    private function unit(FunctionalTester $I, string $code, string $family, string $factor): UnitOfMeasure
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $unit = (new UnitOfMeasure())
            ->setCode($code)
            ->setName('Unit ' . $code)
            ->setFamily($family)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision('1');
        $em->persist($unit);
        $em->flush();

        return $unit;
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
