<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Entity\ProductCore;
use BarcodeBundle\Entity\ProductBarcode;
use BarcodeBundle\Repository\ProductBarcodeRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * BarcodeBundle's two screens (#607, #608), end to end through the real kernel.
 *
 * Everything here is a plain form post — `sendFormPostRequest()`, no `X-Requested-With` header —
 * because that is what a browser with scripting off sends when a submit button is pressed, and
 * because a hardware wedge scanner is exactly that: it types into the focused field and presses
 * Enter. Nothing on these screens needs JavaScript to work and nothing here pretends otherwise.
 *
 * The assertions are about `product_barcode` rows, not about flashes. Seeing a green message is not
 * evidence that anything was stored.
 */
final class BarcodeScreensCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('barcodes-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function product(FunctionalTester $I, string $sku = 'BC', string $name = 'Barcode Product'): ProductCore
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $product = (new ProductCore())
            ->setSku($sku . '-' . strtoupper(substr(uniqid(), -6)))
            ->setName($name)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->flush();

        return $product;
    }

    private function barcodes(FunctionalTester $I): ProductBarcodeRepository
    {
        return $I->grabService(ProductBarcodeRepository::class);
    }

    private function tokenOn(FunctionalTester $I, string $url): string
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    public function bothScreensRender(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);

        $I->amOnPage('/admin/bundles/barcodes');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Barcodes');

        $I->amOnPage('/admin/bundles/barcodes/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see($product->getSku());
    }

    /**
     * The empty state is a real assertion, not decoration: nothing in this feature invents a barcode
     * for anybody, so a fresh install shows an empty list and every product goes on printing its SKU.
     */
    public function theListStartsEmptyBecauseNoProductIsGivenABarcode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);

        $I->amOnPage('/admin/bundles/barcodes/product/' . $product->getId());
        $I->see('This product has no barcode');
        $I->see($product->getSku());

        $I->assertSame([], $this->barcodes($I)->forProduct($product));
    }

    /** Filter state lives in the URL, so a copied address reproduces the exact result. */
    public function listFiltersLiveInTheUrl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/barcodes?filters[code]=4006381&filters[kind]=ean&filters[party]=Northern');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="filters[code]"][value="4006381"]');
        $I->seeElement('input[name="filters[party]"][value="Northern"]');
    }

    public function attachingABarcodeStoresARowAndMakesItThePrimary(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/attach', [
            '_token' => $token,
            'code' => '4006381333931',
            'kind' => ProductBarcode::KIND_EAN,
            'party' => '',
            'note' => 'off the retail pack',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $rows = $this->barcodes($I)->forProduct($product);
        $I->assertCount(1, $rows);
        $I->assertSame('4006381333931', $rows[0]->getCode());
        $I->assertSame(ProductBarcode::KIND_EAN, $rows[0]->getKind());
        $I->assertTrue($rows[0]->isPrimary(), 'the first barcode on a product is what its labels encode');
    }

    /** Many per product, which is the whole shape of the table. */
    public function aProductCanCarryEachSuppliersOwnPartNumber(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        foreach ([
            ['4006381333931', ProductBarcode::KIND_EAN, ''],
            ['NS-4471-B', ProductBarcode::KIND_VENDOR_PART, 'Northern Supply'],
            ['AC/9912', ProductBarcode::KIND_VENDOR_PART, 'Atlantic Components'],
        ] as [$code, $kind, $party]) {
            $I->sendFormPostRequest($url . '/attach', [
                '_token' => $token,
                'code' => $code,
                'kind' => $kind,
                'party' => $party,
            ]);
        }

        $codes = array_map(
            static fn (ProductBarcode $b): string => $b->getCode(),
            $this->barcodes($I)->forProduct($product),
        );

        $I->assertCount(3, $codes);
        $I->assertContains('NS-4471-B', $codes);
        $I->assertContains('AC/9912', $codes);
    }

    /**
     * A transposed pair is the commonest typing error there is, and the check digit is the one thing
     * on this screen that can catch it. Refusing costs a retype; accepting costs a receiving line
     * booked against the wrong product months later.
     */
    public function aMistypedGtinIsRefusedAndNoRowIsWritten(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/attach', [
            '_token' => $token,
            'code' => '4006381333913',
            'kind' => ProductBarcode::KIND_EAN,
        ]);

        $I->see('check digit');
        $I->assertSame([], $this->barcodes($I)->forProduct($product), 'a refused barcode must not be stored');
    }

    public function generatingMintsAnInternalEan13InTheRangeGs1ReservesForInHouseCodes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/generate', ['_token' => $token, 'note' => 'arrived unlabelled']);
        $I->seeResponseCodeIsSuccessful();

        $rows = $this->barcodes($I)->forProduct($product);
        $I->assertCount(1, $rows);
        $I->assertSame(ProductBarcode::KIND_INTERNAL, $rows[0]->getKind());
        $I->assertMatchesRegularExpression('/^2\d{12}$/', $rows[0]->getCode());
        $I->assertTrue($rows[0]->isPrimary());
    }

    public function makingAnotherBarcodePrimaryUnmakesTheOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/attach', ['_token' => $token, 'code' => '4006381333931', 'kind' => ProductBarcode::KIND_EAN]);
        $I->sendFormPostRequest($url . '/attach', ['_token' => $token, 'code' => '10012345678902', 'kind' => ProductBarcode::KIND_GTIN]);

        $rows = $this->barcodes($I)->forProduct($product);
        $case = null;
        foreach ($rows as $row) {
            if ($row->getCode() === '10012345678902') {
                $case = $row;
            }
        }
        $I->assertInstanceOf(ProductBarcode::class, $case);

        $I->sendFormPostRequest('/admin/bundles/barcodes/' . $case->getId() . '/primary', ['_token' => $token]);

        $primaries = array_filter(
            $this->barcodes($I)->forProduct($product),
            static fn (ProductBarcode $b): bool => $b->isPrimary(),
        );
        $I->assertCount(1, $primaries, 'a product must never have two primary barcodes');
        $I->assertSame('10012345678902', array_values($primaries)[0]->getCode());
    }

    /** Deleting the last one returns the product to exactly where it started. */
    public function deletingABarcodeLeavesTheProductPrintingItsSkuAgain(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/attach', ['_token' => $token, 'code' => '4006381333931', 'kind' => ProductBarcode::KIND_EAN]);
        $only = $this->barcodes($I)->forProduct($product)[0];

        $I->sendFormPostRequest('/admin/bundles/barcodes/' . $only->getId() . '/delete', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame([], $this->barcodes($I)->forProduct($product));
        $I->amOnPage($url);
        $I->see('This product has no barcode');
    }

    /**
     * The Open box is how you get to a product without a dropdown of seven thousand of them, and it
     * takes what a scanner types: a SKU or any barcode already on any product.
     */
    public function openingAProductByScanningAnyOfItsBarcodesLandsOnItsScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/attach', [
            '_token' => $token,
            'code' => 'NS-4471-B',
            'kind' => ProductBarcode::KIND_VENDOR_PART,
            'party' => 'Northern Supply',
        ]);

        $I->amOnPage('/admin/bundles/barcodes/open?code=NS-4471-B');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals($url);

        $I->amOnPage('/admin/bundles/barcodes/open?code=' . $product->getSku());
        $I->seeCurrentUrlEquals($url);
    }

    public function openingSomethingThatIsNotAProductSaysSoAndOpensNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/barcodes/open?code=NOT-A-THING');
        $I->seeResponseCodeIsSuccessful();
        $I->see('is not a SKU and is not a barcode on any product');
    }

    /**
     * Two vendors legitimately use one part number for two different things. That is storable, and
     * scanning it must name both rather than picking one — a guess here is wrong about half the time.
     */
    public function acodeOnTwoProductsIsRefusedAndNamesBoth(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $first = $this->product($I, 'BCA');
        $second = $this->product($I, 'BCB');

        foreach ([$first, $second] as $product) {
            $url = '/admin/bundles/barcodes/product/' . $product->getId();
            $token = $this->tokenOn($I, $url);
            $I->sendFormPostRequest($url . '/attach', [
                '_token' => $token,
                'code' => 'SHARED-PART-9',
                'kind' => ProductBarcode::KIND_VENDOR_PART,
            ]);
        }

        $I->amOnPage('/admin/bundles/barcodes/open?code=SHARED-PART-9');
        $I->see('One code cannot mean two products');
        $I->see($first->getSku());
        $I->see($second->getSku());
    }

    /** The Active/Inactive kill switch: the screens read as absent, and every row stays put. */
    public function turningTheBundleInactiveHidesItsScreensWithoutLosingARow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $product = $this->product($I);
        $url = '/admin/bundles/barcodes/product/' . $product->getId();
        $token = $this->tokenOn($I, $url);

        $I->sendFormPostRequest($url . '/attach', ['_token' => $token, 'code' => '4006381333931', 'kind' => ProductBarcode::KIND_EAN]);
        $I->assertCount(1, $this->barcodes($I)->forProduct($product));

        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('BarcodeBundle');

        $I->amOnPage('/admin/bundles/barcodes');
        $I->seeResponseCodeIs(404);

        $I->amOnPage($url);
        $I->seeResponseCodeIs(404);

        $I->assertCount(1, $this->barcodes($I)->forProduct($product), 'switching the bundle off must not delete anything');
    }
}
