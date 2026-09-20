<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers the admin Cart visibility page (§18): read-only listing + detail view over the new
 *  Cart/CartItem tables, over real HTTP with a real logged-in admin session. */
final class AdminCartCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-cart-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    public function indexListsCartsAndDetailShowsItsItems(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $region = (new FulfillmentRegion())->setName('Admin Cart Test Region')->setStatus('Active');
        $I->haveInRepository($region);

        $company = (new Company())->setName('Cart Admin Co')->setCode('CART-ADMIN-' . uniqid());
        $I->haveInRepository($company);

        $product = (new ProductCore())->setSku('ADMIN-CART-SKU')->setName('Admin Cart Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $cart = (new Cart())->setSessionId('admin-cart-test-session')->setCompany($company);
        $I->haveInRepository($cart);

        $item = (new CartItem())->setCart($cart)->setProduct($product)->setFulfillmentRegion($region)->setQuantity(6);
        $I->haveInRepository($item);

        $cartId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'admin-cart-test-session']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Carts');
        $I->see('Cart Admin Co');

        // The item count read out of THIS cart's row, by id, rather than see('1') (#594). Every
        // rendered grid carries a '1' somewhere — an id, a page number, a timestamp — so the old
        // assertion held whether the column summed per cart, summed every cart item in the
        // database, or printed 0.
        $I->assertSame(
            '1',
            trim($I->grabTextFrom('//tbody/tr[td[1][normalize-space()="' . $cartId . '"]]/td[4]')),
            'the Items column counts the items in this cart and no other',
        );

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts/' . $cartId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('ADMIN-CART-SKU');
        $I->see('Admin Cart Test Region');

        // Likewise the quantity: cart_item.quantity, read out of the line's own cell. see('6')
        // matched the id, the clock and anything else on the page.
        $I->assertSame(
            '6',
            trim($I->grabTextFrom('//tbody/tr[td[1][contains(., "ADMIN-CART-SKU")]]/td[3]')),
            'cart_item.quantity, not whatever else on the page happens to contain a 6',
        );
    }

    /**
     * The grid narrows to one customer's cart, and the other customer's cart is GONE.
     *
     * The list joined the grid model in #621 and gained filters with it; this is the half that says
     * they filter. Two carts are created, both are asserted onto the unfiltered page first — the
     * positive control, without which "the other row is absent" is equally true of a page that
     * rendered nothing — and then the company filter is applied and the second cart has to be
     * missing. A filter proved only by the row it keeps is a filter that could be returning
     * everything.
     *
     * The request is the plain GET the form would send: the controls that make that possible with
     * JavaScript off are asserted to be on the page first. `submitForm()` cannot be used on an
     * admin screen here — the crawler resolves the action against the admin.localhost Host header
     * and the module refuses it as external; see AdminNoJsVendorMasterDataCest.
     */
    public function filteringByCompanyLeavesTheOtherCompanysCartOut(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $wanted = (new Company())->setName('Cart Filter Wanted Co')->setCode('CFW-' . uniqid());
        $other = (new Company())->setName('Cart Filter Other Co')->setCode('CFO-' . uniqid());
        $I->haveInRepository($wanted);
        $I->haveInRepository($other);

        $I->haveInRepository((new Cart())->setSessionId('cart-filter-wanted-session')->setCompany($wanted));
        $I->haveInRepository((new Cart())->setSessionId('cart-filter-other-session')->setCompany($other));

        $wantedId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-filter-wanted-session']);
        $otherId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-filter-other-session']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement($this->rowFor($wantedId));
        $I->seeElement($this->rowFor($otherId));

        // What a browser with scripting off needs: a GET form, the box joined to it, and a button.
        $I->seeElement('form#cart-filters[method="get"]');
        $I->seeElement('thead tr.filter-row input[name="filters[company]"][form="cart-filters"]');
        $I->seeElement('form#cart-filters button[type="submit"]');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts?filters%5Bcompany%5D=Cart+Filter+Wanted');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement($this->rowFor($wantedId));
        $I->dontSeeElement($this->rowFor($otherId));
        $I->assertSame(
            'Cart Filter Wanted Co',
            trim($I->grabTextFrom($this->rowFor($wantedId) . '/td[@data-label="Customer"]')),
        );

        // The footer counts the filtered grid, not the table. Read whole out of its own element,
        // never see()n: 'of 1' is inside 'of 12' (#627).
        $I->assertSame(
            'Showing 1 to 1 of 1 carts',
            trim((string) preg_replace('/\s+/', ' ', $I->grabTextFrom('.table-footer .table-count'))),
        );
    }

    /** The "Not empty" checkbox became a textbox search on the exact item count. */
    public function filteringByItemCountLeavesOtherCountsOut(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $region = (new FulfillmentRegion())->setName('Cart Item Count Region')->setStatus('Active');
        $I->haveInRepository($region);
        $productA = (new ProductCore())->setSku('CART-ITEM-COUNT-SKU-A')->setName('Cart Item Count Product A')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $productB = (new ProductCore())->setSku('CART-ITEM-COUNT-SKU-B')->setName('Cart Item Count Product B')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($productA);
        $I->haveInRepository($productB);

        $twoItems = (new Cart())->setSessionId('cart-item-count-two');
        $I->haveInRepository($twoItems);
        $I->haveInRepository((new CartItem())->setCart($twoItems)->setProduct($productA)->setFulfillmentRegion($region)->setQuantity(1));
        $I->haveInRepository((new CartItem())->setCart($twoItems)->setProduct($productB)->setFulfillmentRegion($region)->setQuantity(2));

        $empty = (new Cart())->setSessionId('cart-item-count-zero');
        $I->haveInRepository($empty);

        $twoId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-item-count-two']);
        $emptyId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-item-count-zero']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts');
        $I->seeElement('thead tr.filter-row input[name="filters[itemCount]"][form="cart-filters"][type="number"]');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts?filters%5BitemCount%5D=2');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement($this->rowFor($twoId));
        $I->dontSeeElement($this->rowFor($emptyId));
    }

    /** The "Holding now" checkbox became a dropdown covering holding/expired/no-hold. */
    public function filteringByHoldStatusSeparatesHoldingExpiredAndNone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $holding = (new Cart())->setSessionId('cart-hold-status-holding')->setHoldExpiresAt(new \DateTimeImmutable('+1 day'));
        $expired = (new Cart())->setSessionId('cart-hold-status-expired')->setHoldExpiresAt(new \DateTimeImmutable('-1 day'));
        $none = (new Cart())->setSessionId('cart-hold-status-none');
        $I->haveInRepository($holding);
        $I->haveInRepository($expired);
        $I->haveInRepository($none);

        $holdingId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-hold-status-holding']);
        $expiredId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-hold-status-expired']);
        $noneId = $I->grabFromRepository(Cart::class, 'id', ['sessionId' => 'cart-hold-status-none']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts');
        $I->seeElement('thead tr.filter-row select[name="filters[held]"][form="cart-filters"]');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts?filters%5Bheld%5D=holding');
        $I->seeElement($this->rowFor($holdingId));
        $I->dontSeeElement($this->rowFor($expiredId));
        $I->dontSeeElement($this->rowFor($noneId));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts?filters%5Bheld%5D=expired');
        $I->seeElement($this->rowFor($expiredId));
        $I->dontSeeElement($this->rowFor($holdingId));
        $I->dontSeeElement($this->rowFor($noneId));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/carts?filters%5Bheld%5D=none');
        $I->seeElement($this->rowFor($noneId));
        $I->dontSeeElement($this->rowFor($holdingId));
        $I->dontSeeElement($this->rowFor($expiredId));
    }

    /** The one row this cart renders as, by its id in the ID cell. */
    private function rowFor(int $cartId): string
    {
        return '//tbody/tr[td[@data-label="ID"][normalize-space()="' . $cartId . '"]]';
    }
}
