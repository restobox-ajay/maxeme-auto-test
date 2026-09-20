<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers the checkout_coupons_enabled AppSetting (#34): when an admin switches coupons off,
 * the coupon field must not render at checkout AND the /checkout/coupon route must refuse the
 * code instead of applying it — hiding the input alone would leave the POST route usable.
 *
 * Each test sets both coupon settings explicitly rather than relying on a starting value:
 * this suite shares one persistent SQLite connection (and the AppSettings cache) across the
 * whole run, so a literal default assertion would depend on test execution order — the same
 * reasoning HeaderLogoOnlyCest and CartHoldConfigCest document.
 */
final class CustomerCheckoutCouponConfigCest
{
    private const COUPONS_JSON = '[{"code":"SAVE10","type":"percent","value":10,"min_subtotal":0,"label":"10% off"}]';

    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Coupon Config Warehouse')->setStatus('Active');
        $I->haveInRepository($region);

        $company = (new Company())->setName('Coupon Config Co')->setCode('COUPON-' . uniqid());
        $I->haveInRepository($company);

        $companyRegion = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active');
        $I->haveInRepository($companyRegion);

        // A resolvable default price is what makes the subtotal (and therefore any discount)
        // non-zero — an unpriced row would route checkout into an Estimate instead.
        $this->product = (new ProductCore())
            ->setSku('COUPON-CFG-SKU')
            ->setName('Coupon Config Product')
            ->setDefaultPrice('100.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);

        $inventory = (new ProductInventory())
            ->setProduct($this->product)
            // Stock sits in the warehouse serving the region, not in the region (#546).
            ->setWarehouse($I->grabService(WarehouseFulfillmentRegionService::class)->warehouseForRegionNameOrCreate($region->getName(), 'BC', 'CA'))
            ->setQuantity(20);
        $I->haveInRepository($inventory);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('coupon-config-test@example.test')
            ->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    public function couponFieldIsHiddenAndCodeIsRejectedWhenCouponsAreDisabled(FunctionalTester $I): void
    {
        $this->setSetting($I, 'checkout_coupons', self::COUPONS_JSON);
        $this->setSetting($I, 'checkout_coupons_enabled', 'No');
        $this->fillCart($I);

        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('.checkout-coupon-row');
        $I->dontSee('Coupon Code:');
        $I->dontSeeElement('input[name="coupon_code"]');

        // The coupon form is gone, so take the 'customer_checkout' token from one of the other
        // checkout forms — every form on the page signs with that same token id.
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/checkout/coupon', [
            '_token' => $token,
            'coupon_code' => 'SAVE10',
        ]);

        // The route redirects back to /checkout, which the client follows — so the refusal flash
        // is on this response (it is consumed by that render and would be gone on a re-GET).
        // No selector: base.html.twig renders flashes into a `hidden` container for the JS
        // toaster, and a selector-scoped see() does not match text inside it.
        $I->see('Coupon codes are not accepted at this time.');
        $I->dontSee('Discount:');
        $I->dontSee('-$10.00');

        // And nothing was stashed in the session either: a fresh load is still undiscounted.
        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Discount:');
        $I->dontSee('-$10.00');
    }

    public function couponFieldRendersAndCodeAppliesWhenCouponsAreEnabled(FunctionalTester $I): void
    {
        $this->setSetting($I, 'checkout_coupons', self::COUPONS_JSON);
        $this->setSetting($I, 'checkout_coupons_enabled', 'Yes');
        $this->fillCart($I);

        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('.checkout-coupon-row');
        $I->seeElement('input[name="coupon_code"]');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/checkout/coupon', [
            '_token' => $token,
            'coupon_code' => 'SAVE10',
        ]);

        $I->amOnPage('/checkout');
        $I->seeResponseCodeIsSuccessful();
        $I->see('SAVE10', '.checkout-coupon-row');
        $I->see('Discount:');
        $I->see('-$10.00');
    }

    private function fillCart(FunctionalTester $I): void
    {
        // The cart page only renders a 'customer_cart' token once the cart has items, so take it
        // from the product detail page — the add-to-cart entry point for an empty cart.
        $I->amOnPage('/product/detail/' . $this->product->getId());
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/cart/add', [
            '_token' => $token,
            'sku' => $this->product->getSku(),
            'qty' => 1,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function setSetting(FunctionalTester $I, string $key, string $value): void
    {
        /** @var EntityManagerInterface $em */
        $em = $I->grabService(EntityManagerInterface::class);
        $setting = $em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey($key)->setName($key);
            $em->persist($setting);
        }

        $setting->setSettingValue($value);
        $em->flush();

        // AppSettings caches every row; the admin screens clear it on write, so a direct write
        // has to do the same or the request would still read the previous value.
        $I->grabService(AppSettings::class)->clearCache();
    }
}
