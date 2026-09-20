<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A refused save hands back what the admin typed, not what the database holds.
 *
 * > *"The unified template - if rejected should print back all users inputs plus rejected reason."*
 *
 * Before this, `OrderController::edit()` re-rendered from the **saved order**, so a refused save
 * silently replaced every typed figure with the stored one and the admin retyped the lot. The fix
 * is one merge at the top of the shared line row: posted values win over stored ones.
 *
 * ## What this pins, and why that is the interesting part
 *
 * The repaint echoes the posted bytes back unchanged, and the hidden `*_rendered` twins are
 * asserted byte for byte rather than by value.
 *
 * On THIS path the stakes are modest: both the box and its twin come from the same posted string,
 * so reformatting one would make `LineDenomination::boxUntouched()` read an untouched box as
 * edited — but the visible value is what the person typed, so the figure stored would be the one
 * they meant anyway. The reason to hold the line here is the general case: elsewhere the twin
 * carries precision the box rounds off for display (stored 2.3456, box showing 2.35), and there a
 * broken comparison drops digits. A repaint that reformats is the easiest way to introduce that,
 * so the rule is simply never to reformat on this path.
 */
final class ARefusedSaveRepaintsWhatYouTypedCest
{
    private const REGION = 'Repaint Region';

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('repaint-admin@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    public function aRefusedOrderSaveRepaintsTheTypedQuantityAndPrice(FunctionalTester $I): void
    {
        ['orderId' => $orderId, 'lineId' => $lineId, 'companyId' => $companyId, 'productId' => $productId] = $this->anOrderWithOneLine($I);

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIs(200);

        // The control: the stored figures are what the screen shows before anything is typed.
        $storedQty = $I->grabAttributeFrom('input[name="lines[0][qty]"]', 'value');
        $storedPrice = $I->grabAttributeFrom('input[name="lines[0][price]"]', 'value');
        $I->assertNotSame('7.5', $storedQty, 'the fixture must not already hold the typed figure');
        $I->assertNotSame('19.99', $storedPrice, 'the fixture must not already hold the typed figure');

        $version = (string) $I->grabAttributeFrom('input.js-order-version', 'value');

        // The refusal has to be a LINE refusal: a missing customer is caught earlier and answered
        // with a REDIRECT, and a redirect re-reads stored values, so there is nothing to repaint.
        //
        // This posts one row carrying typed figures but naming no product — which is what
        // ValidOrderLines calls a blank line, so the save is refused and the screen re-renders.
        // It is also exactly the row whose typed figures used to vanish.
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $companyId,
            'version' => $version,
            'save_mode' => 'order',
            'lines' => [
                0 => [
                    'id' => (string) $lineId,
                    'product_id' => '',
                    'name' => '',
                    'sku' => '',
                    'qty' => '7.5',
                    'qty_rendered' => '7.5',
                    'price' => '19.99',
                    'price_rendered' => '19.99',
                    'tax_code' => 'E',
                ],
            ],
        ]);

        // The typed values come back — NOT the figures still sitting in the database.
        $I->assertSame('7.5', $I->grabAttributeFrom('input[name="lines[0][qty]"]', 'value'), 'the typed quantity');
        $I->assertSame('19.99', $I->grabAttributeFrom('input[name="lines[0][price]"]', 'value'), 'the typed price');
        // The hidden twins, and these are the ones that matter: a repaint that reformatted 7.5 into
        // 7.5000 here would make boxUntouched() read an untouched box as edited.
        $I->assertSame('7.5', $I->grabAttributeFrom('input[name="lines[0][qty_rendered]"]', 'value'));
        $I->assertSame('19.99', $I->grabAttributeFrom('input[name="lines[0][price_rendered]"]', 'value'));

    }

    public function anOrdinaryEditScreenStillShowsTheStoredValues(FunctionalTester $I): void
    {
        // The negative control. `submitted` is empty on a GET, so the repaint path must be totally
        // inert — if it were not, this screen would render blank boxes over real data.
        ['orderId' => $orderId, 'lineId' => $lineId] = $this->anOrderWithOneLine($I);

        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIs(200);

        $I->assertSame('4', $I->grabAttributeFrom('input[name="lines[0][qty]"]', 'value'));
        $I->assertSame('5.00', $I->grabAttributeFrom('input[name="lines[0][price]"]', 'value'));
        $I->assertSame((string) $lineId, $I->grabAttributeFrom('input[name="lines[0][id]"]', 'value'));
        $I->assertNotSame('', $I->grabAttributeFrom('input[name="lines[0][qty_rendered]"]', 'value'));
    }

    /** @return array{orderId: int, lineId: int, companyId: int, productId: int} */
    private function anOrderWithOneLine(FunctionalTester $I): array
    {
        $suffix = strtoupper(substr(uniqid(), -8));

        // Its own region per order: the name carries a UNIQUE index on LOWER(TRIM(name)), so a
        // shared one would collide the second time this runs.
        $regionName = self::REGION . ' ' . $suffix;
        $I->haveInRepository((new FulfillmentRegion())->setName($regionName));

        $company = (new Company())
            ->setName('Repaint Wholesale ' . $suffix)
            ->setCode('RPT-' . $suffix);
        $I->haveInRepository($company);

        $product = (new ProductCore())
            ->setSku('RPT-SKU-' . $suffix)
            ->setName('Repaint Widget')
            ->setUnit('EA')
            // Exempt, so nothing here needs a tax province and no figure below is a tax figure.
            ->setSalesTaxCode('E')
            ->setDefaultPrice('5.00')
            ->setOriginalPrice('5.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('RPT-SO-' . $suffix)
            ->setFulfillmentRegion($regionName)
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setTotal('20.00');
        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku((string) $product->getSku())
            ->setLocation($regionName)
            ->setTaxCode('E')
            ->setQuantity('4')
            ->setPrice('5.00')
            ->setSubtotal('20.00');
        $order->addLine($line);
        $order->approve(DocumentActor::system());
        $I->haveInRepository($order);

        return ['orderId' => (int) $order->getId(), 'lineId' => (int) $line->getId(), 'companyId' => (int) $company->getId(), 'productId' => (int) $product->getId()];
    }
}
