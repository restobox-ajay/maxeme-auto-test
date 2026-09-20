<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Enum\ProductStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * `product_core.status` is a declared set now (queue item 9), conducted per #624.
 *
 * It was a free string with a bare setStatus() and no enum, so the legal values existed only as
 * whatever eighteen comparisons happened to test for. Adding Draft to that was the change, and the
 * two silent behaviours it exposed are the first two cases below: a form whose options were a
 * hand-kept ['Active', 'Inactive'] pre-selected NOTHING for a Draft product, so the browser posted
 * the first option and an unrelated edit activated it; and an import or a replayed post could
 * write any string at all into the column.
 *
 * Everything here drives the real screens with plain form posts and a scraped CSRF token, creates
 * its own data, re-reads every claim out of `product_core` BY COLUMN afterwards, and carries a row
 * that must NOT have moved.
 */
final class ProductStatusIsEnforcedInCodeCest
{
    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('status-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{id: int, sku: string, name: string} */
    private function product(FunctionalTester $I, ProductStatus $status): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $sku = 'PSTAT-' . strtoupper(substr(uniqid(), -8));

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Status Subject ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->applyStatusChoice($status);
        $em->persist($product);
        $em->flush();

        return ['id' => (int) $product->getId(), 'sku' => $sku, 'name' => 'Status Subject ' . $sku];
    }

    /**
     * A product carrying a status this application does not know, written the only way one can be
     * written now — straight into the column, behind the entity's back. That is also exactly how
     * the rows this pattern exists for got there: the CSV import used to write the file's status
     * cell verbatim.
     *
     * @return array{id: int, sku: string, name: string}
     */
    private function productWithUnknownStatus(FunctionalTester $I, string $status): array
    {
        $row = $this->product($I, ProductStatus::Active);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('UPDATE product_core SET status = ? WHERE id = ?', [$status, $row['id']]);
        // The row was just changed underneath Doctrine, so the managed copy still says Active and
        // every later read would be served from the identity map rather than from the column.
        $em->clear();

        return $row;
    }

    private function storedStatus(FunctionalTester $I, int $productId): string
    {
        return (string) $I->grabService(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT status FROM product_core WHERE id = ?', [$productId]);
    }

    /** The option the rendered select opens on — what a browser would post if nobody touched it. */
    private function preSelectedStatus(FunctionalTester $I): string
    {
        return (string) $I->grabAttributeFrom('select[name="status"] option[selected]', 'value');
    }

    private function csrfToken(FunctionalTester $I): string
    {
        return (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');
    }

    /**
     * @param array{id: int, sku: string, name: string} $product
     * @param array<string, string> $extra
     */
    private function saveProductForm(FunctionalTester $I, array $product, array $extra = []): void
    {
        $path = '/admin/product/inventory/update/' . $product['id'];
        $I->amOnPage($path);
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest($path, array_merge([
            '_token' => $this->csrfToken($I),
            'name' => $product['name'],
            'sku' => $product['sku'],
            'visible' => 'Yes',
        ], $extra));
    }

    /**
     * The form offers every status the enum names, and opens on the one the product holds.
     *
     * This is the defect in its cheapest form. The option list was ['Active', 'Inactive'], so a
     * Draft product rendered a select with no option selected at all — and a browser posts the
     * first option of such a select, which was Active.
     */
    public function theStatusSelectOffersEveryStatusAndOpensOnTheProductsOwn(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $draft = $this->product($I, ProductStatus::Draft);

        $I->amOnPage('/admin/product/inventory/update/' . $draft['id']);
        $I->seeResponseCodeIsSuccessful();

        // Every case, anchored to the select. 'Draft' also appears in this page's own copy and in
        // list-screen filters, so a bare see('Draft') would pass without the option existing (#627).
        foreach (ProductStatus::cases() as $case) {
            $I->seeElement(sprintf('select[name="status"] option[value="%s"]', $case->value));
        }
        $I->seeNumberOfElements('select[name="status"] option', count(ProductStatus::cases()));

        // And it opens on Draft, which is the half that was broken.
        $I->assertSame(ProductStatus::Draft->value, $this->preSelectedStatus($I));
    }

    /**
     * Saving an unrelated edit on a Draft product leaves it Draft.
     *
     * Conducted as a browser would: the status posted back is whatever the select opened on,
     * untouched. Before this change that value was 'Active', and a product whose base unit nobody
     * had resolved became sellable because somebody fixed a typo in its name.
     */
    public function savingAnUnrelatedEditDoesNotActivateADraftProduct(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $subject = $this->product($I, ProductStatus::Draft);
        $bystander = $this->product($I, ProductStatus::Draft);

        $I->assertSame('Draft', $this->storedStatus($I, $subject['id']));

        $I->amOnPage('/admin/product/inventory/update/' . $subject['id']);
        $I->seeResponseCodeIsSuccessful();
        $posted = $this->preSelectedStatus($I);

        $I->sendFormPostRequest('/admin/product/inventory/update/' . $subject['id'], [
            '_token' => $this->csrfToken($I),
            'name' => 'Renamed Without Touching Status',
            'sku' => $subject['sku'],
            'visible' => 'Yes',
            'status' => $posted,
        ]);

        $I->assertSame('Draft', $this->storedStatus($I, $subject['id']), 'product_core.status is still Draft');
        $I->assertSame(
            'Renamed Without Touching Status',
            (string) $I->grabService(EntityManagerInterface::class)->getConnection()
                ->fetchOne('SELECT name FROM product_core WHERE id = ?', [$subject['id']]),
            'and the edit that was actually made did land',
        );

        // The row that must NOT have changed.
        $I->assertSame('Draft', $this->storedStatus($I, $bystander['id']), 'the other Draft product is untouched');
    }

    /** A human moving a resolved Draft to Active writes Active, and moves nothing else. */
    public function activatingADraftProductThroughTheFormWritesActive(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $subject = $this->product($I, ProductStatus::Draft);
        $bystander = $this->product($I, ProductStatus::Draft);

        $this->saveProductForm($I, $subject, ['status' => ProductStatus::Active->value]);

        $I->assertSame('Active', $this->storedStatus($I, $subject['id']));
        $I->assertSame('Draft', $this->storedStatus($I, $bystander['id']), 'the other Draft product is untouched');
    }

    /** And back the other way, so Draft is reachable from the screen rather than only from an import. */
    public function draftingAnActiveProductThroughTheFormWritesDraft(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $subject = $this->product($I, ProductStatus::Active);
        $bystander = $this->product($I, ProductStatus::Active);

        $this->saveProductForm($I, $subject, ['status' => ProductStatus::Draft->value]);

        $I->assertSame('Draft', $this->storedStatus($I, $subject['id']));
        $I->assertSame('Active', $this->storedStatus($I, $bystander['id']), 'the other Active product is untouched');
    }

    /**
     * A status the enum does not name is refused out loud, and nothing is written.
     *
     * The old setter took it. 'active', 'ACTIVE' and '12/Case' all became rows that no
     * `= 'Active'` comparison matched and that no screen explained.
     */
    public function aStatusTheEnumDoesNotKnowIsRefusedRatherThanWritten(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $subject = $this->product($I, ProductStatus::Active);
        $bystander = $this->product($I, ProductStatus::Active);

        $this->saveProductForm($I, $subject, ['status' => 'Sort Of Active']);

        $I->seeResponseCodeIs(422);
        $I->see('"Sort Of Active" is not a product status this application knows.');
        $I->assertSame('Active', $this->storedStatus($I, $subject['id']), 'nothing was written');
        $I->assertSame('Active', $this->storedStatus($I, $bystander['id']), 'and the other product is untouched');

        // Positive control for the refusal: the same form, same product, a status the enum knows.
        $this->saveProductForm($I, $subject, ['status' => ProductStatus::Inactive->value]);
        $I->assertSame('Inactive', $this->storedStatus($I, $subject['id']), 'a known status still saves');
    }

    /**
     * A row carrying a status the enum does not know HYDRATES, and is not treated as Active.
     *
     * This is the point of leaving the column a plain string rather than enum-typing it, and it is
     * the part most easily dropped: `product_core` is deployed, and the import wrote the file's own
     * status cell into it for years. A row like this one must load, must say what it says, and must
     * not be sellable.
     */
    public function aRowCarryingAnUnknownStatusHydratesAndIsNotSellable(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $stray = $this->productWithUnknownStatus($I, 'Waiting for Stock');

        $em = $I->grabService(EntityManagerInterface::class);

        $product = $em->find(ProductCore::class, $stray['id']);
        $I->assertInstanceOf(ProductCore::class, $product, 'the row hydrates rather than blowing up on load');
        $I->assertSame('Waiting for Stock', $product->getStatus(), 'and it still says what the column says');
        $I->assertNull($product->getStatusEnum(), 'getStatusEnum() returns null for a status the enum does not know');
        $I->assertFalse($product->isSellable(), 'an unrecognised status is not sellable — it is not read as Active');

        // Positive control: the same three accessors on a product the enum DOES know, so the nulls
        // above cannot be passing because the accessors are broken for everything.
        $known = $em->find(ProductCore::class, $this->product($I, ProductStatus::Active)['id']);
        $I->assertInstanceOf(ProductCore::class, $known);
        $I->assertSame(ProductStatus::Active, $known->getStatusEnum());
        $I->assertTrue($known->isSellable());
    }

    /**
     * The admin grid says the status is unrecognised instead of printing it as an ordinary badge,
     * and the form keeps the value rather than overwriting it with whatever is at the top of the
     * select.
     */
    public function anUnknownStatusIsCalledOutOnScreenAndSurvivesASave(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $stray = $this->productWithUnknownStatus($I, 'Waiting for Stock');
        $bystander = $this->product($I, ProductStatus::Active);

        $I->amOnPage('/admin/product/detail/index?filters[sku]=' . $stray['sku']);
        $I->seeResponseCodeIsSuccessful();
        // Anchored to the badge element: 'Waiting for Stock' is also in the filter box's own value
        // on this page, so a bare see() would pass with the cell rendering nothing (#627).
        $I->see('Waiting for Stock (unrecognised)', '.product-status-badge');
        $I->seeElement('.product-status-badge.danger');

        // Positive control for that absence-of-normality: a product the enum knows gets a plain
        // badge on the same screen, with no "(unrecognised)" suffix.
        $I->amOnPage('/admin/product/detail/index?filters[sku]=' . $bystander['sku']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Active', '.product-status-badge');
        $I->dontSee('(unrecognised)', '.product-status-badge');

        // And saving the stray's form, as a browser would, leaves the value alone.
        $I->amOnPage('/admin/product/inventory/update/' . $stray['id']);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame('Waiting for Stock', $this->preSelectedStatus($I), 'the select opens on the stored value');

        $I->sendFormPostRequest('/admin/product/inventory/update/' . $stray['id'], [
            '_token' => $this->csrfToken($I),
            'name' => $stray['name'],
            'sku' => $stray['sku'],
            'visible' => 'Yes',
            'status' => $this->preSelectedStatus($I),
        ]);

        $I->assertSame('Waiting for Stock', $this->storedStatus($I, $stray['id']), 'the legacy value survived the save');
        $I->assertSame('Active', $this->storedStatus($I, $bystander['id']), 'and the other product is untouched');
    }

    /**
     * A Draft product is not in the customer catalogue and is not counted there.
     *
     * The catalogue's queries ask `status = 'Active'`, so Draft falls out of them by construction —
     * which is the claim worth conducting rather than assuming, because the alternative reading of
     * those queries ("not Inactive") was true right up until Draft existed.
     */
    public function aDraftProductIsNeitherListedNorCountedInTheCustomerCatalogue(FunctionalTester $I): void
    {
        $I->haveInRepository(
            (new FulfillmentRegion())->setName('Status Catalogue Region')->setStatus('Active')->setGuestVisible(true)
        );

        $active = $this->product($I, ProductStatus::Active);
        $draft = $this->product($I, ProductStatus::Draft);
        $inactive = $this->product($I, ProductStatus::Inactive);

        $I->amOnPage('/product/index');
        $I->seeResponseCodeIsSuccessful();

        // The positive control comes first: the Active product IS on the page. Without it the two
        // absences below would pass just as well on a page that failed to render any product.
        $I->see($active['name'], '.product-card');
        $I->dontSee($draft['name'], '.product-card');
        $I->dontSee($inactive['name'], '.product-card');

        // Counted, not just listed. One product of the three, read out of the count span rather
        // than as a bare number anywhere on the page (#627).
        $I->assertSame('1 of 1', trim($I->grabTextFrom('.product-count')), 'only the Active product is counted');
    }
}
