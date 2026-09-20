<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * #775 (OE-4), reopened: the 500 is not on the SAVE path — TextInput::nullableString()/
 * AbstractAdminController::rawLineAmount() already guard price/cost/sku/weight/unit/batch there
 * with is_scalar(), which refuses an array at any depth, and a save that reaches persistence goes
 * through fine. It is on the RE-RENDER path a refused save takes: `admin/_partials/
 * sales_line_row.html.twig` echoes `submitted[index].price` straight into `value="{{ ... }}"`
 * (deliberately, byte for byte — LineDenomination::boxUntouched() needs it), and printing an array
 * where a string was expected is a Twig RuntimeError ("Array to string conversion"), not a blank
 * field.
 *
 * Triggered here via an unrecognised fulfillment region — any refusal that takes the "repaint what
 * was typed" branch exercises the same vulnerable echo, and this one needs no stock/region fixture
 * at all: a company with no fulfillment regions set up refuses ANY named region.
 */
final class AdminOrderLineNestedArrayRerenderCest
{
    public function aRefusedSaveWithANestedArrayPriceDoesNotServerError(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('oe4-rerender-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $company = (new Company())->setName('OE4 Rerender Co')->setCode('OE4R-' . uniqid())->setPrimaryEmail('a@oe4r.example');
        $I->haveInRepository($company);

        $product = (new ProductCore())->setSku('OE4R-SKU')->setName('OE4 Rerender Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('OE4R-' . uniqid())->setSubtotal('20.00')->setTax('0.00')->setTotal('20.00');
        $order->addLine((new SalesOrderLine())->setProduct($product)->setName('OE4 Rerender Product')->setSku('OE4R-SKU')->setQuantity('1')->setPrice('20.00')->setSubtotal('20.00'));
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'ok');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        // "Main" is not a region this company is set up for (none is) — refused, which is exactly
        // the branch that repaints the typed lines instead of redirecting.
        $I->sendAjaxPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'billing_address_id' => '0',
            'shipping_address_id' => '0',
            'fulfillment_region' => 'Main',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'name' => 'OE4 Rerender Product',
                'qty' => '2',
                'price' => [[1]],
            ]],
            'save_mode' => 'order',
        ]);

        $I->seeResponseCodeIs(422);
        $I->see('is not a fulfillment region this company is set up for');
        $I->dontSee('Array to string conversion');
    }
}
