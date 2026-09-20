<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The price grid must show the price the system actually holds, negatives included.
 *
 * `productToPriceRow()` does not print `product_pricing.price` — it recomputes the Effective column
 * from rule + base on every page load, so the figure stays right when a base price has moved since
 * the rule was saved. That recompute carried its own `$computed < 0 ? 0` floor, written in May,
 * months before #458 removed the floor from the write endpoints and #462 removed it from the
 * customer-facing resolvers.
 *
 * Both of those changes swept the paths that decide or serve a price. This one only decides what an
 * admin is *shown*, so nothing pointed at it and it kept flooring. The result: a price stored as
 * -10.00 and charged to the customer as -10.00, displayed as $0.00 on the single screen an admin
 * uses to check prices — the grid disagreeing with both the database and the storefront.
 *
 * Display-only. No data was ever corrupted by it, which is exactly why it survived.
 */
final class AdminPriceGridDisplayFloorGuardCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('price-grid-floor-guard@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }


    /**
     * The text of the visible Effective cell for a price list.
     *
     * Deliberately not a substring search over the whole page: the row also carries a hidden
     * `js-price-input` seeded from `effectiveRaw`, which holds the *stored* price. A page-wide
     * assertion therefore passes on the stored value even when the visible cell has been floored —
     * it was silently doing exactly that for the Discount% case here before this was tightened.
     */
    private function effectiveCellText(FunctionalTester $I, int $priceListId): string
    {
        $html = $I->grabPageSource();
        $pattern = '#<span class="price-effective js-price-effective"\s+data-price-list-id="' . $priceListId . '">([^<]*)</span>#';

        $I->assertSame(1, preg_match($pattern, $html, $m), 'expected exactly one Effective cell for price list ' . $priceListId);

        return trim($m[1]);
    }

    /**
     * A discount larger than the base is the canonical way to reach a negative price, and it is the
     * shape #458 made legal on purpose.
     */
    public function aNegativeEffectivePriceIsDisplayedRatherThanFlooredToZero(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = (new PriceList())->setName('Display Floor List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $product = (new ProductCore())
            ->setSku('DISPLAY-FLOOR-1')
            ->setName('Display Floor Guard Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setDefaultPrice('10.00');
        $I->haveInRepository($product);

        // Discount$ 20 against a base of 10 computes -10.00, which is what the write endpoints
        // store and what the customer is charged.
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setRuleType('Discount$')
            ->setRuleValue('20.00')
            ->setPrice('-10.00'));

        // ?lists= is required: the grid renders no price-list columns at all until the request
        // names the ones to show ("Default: show none until the user selects price groups").
        $I->amOnPage('/admin/product/price/index?lists=' . $priceList->getId() . '&filters[sku]=DISPLAY-FLOOR-1');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            '-10.00',
            $this->effectiveCellText($I, (int) $priceList->getId()),
            'the grid must show the negative price it holds, not a floored one',
        );
    }

    /** The floor must not survive on the percentage branch either — same block, same clamp. */
    public function aNegativeFromADiscountPercentIsAlsoDisplayed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = (new PriceList())->setName('Display Floor Pct List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $product = (new ProductCore())
            ->setSku('DISPLAY-FLOOR-2')
            ->setName('Display Floor Guard Product Pct')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setDefaultPrice('10.00');
        $I->haveInRepository($product);

        // 150% off a base of 10 is -5.00.
        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setRuleType('Discount%')
            ->setRuleValue('150.00')
            ->setPrice('-5.00'));

        $I->amOnPage('/admin/product/price/index?lists=' . $priceList->getId() . '&filters[sku]=DISPLAY-FLOOR-2');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            '-5.00',
            $this->effectiveCellText($I, (int) $priceList->getId()),
            'the percentage branch must not floor either',
        );
    }

    /** A positive price is unchanged — the fix removes a floor, it does not alter arithmetic. */
    public function anOrdinaryPositivePriceStillDisplaysNormally(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = (new PriceList())->setName('Display Floor Positive List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        $product = (new ProductCore())
            ->setSku('DISPLAY-FLOOR-3')
            ->setName('Display Floor Guard Product Positive')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setDefaultPrice('100.00');
        $I->haveInRepository($product);

        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setCurrency('USD')
            ->setRuleType('Discount$')
            ->setRuleValue('25.00')
            ->setPrice('75.00'));

        $I->amOnPage('/admin/product/price/index?lists=' . $priceList->getId() . '&filters[sku]=DISPLAY-FLOOR-3');
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('75.00', $this->effectiveCellText($I, (int) $priceList->getId()));
    }
}
