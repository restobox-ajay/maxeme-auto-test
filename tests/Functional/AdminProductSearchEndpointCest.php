<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * GitHub issue #399: the order and quote line-item product pickers no longer render the whole
 * catalog into the page — they fetch matches from these two endpoints as the admin types. The
 * endpoints had no coverage at all when they landed, and both regressions found afterwards were
 * things a test here would have caught:
 *
 *  1. An empty/short term skipped the LIKE entirely and returned the first 50 products of the
 *     catalog, so merely opening a picker looked exactly like the preloaded catalog #399 removed.
 *  2. company_id was read with InputBag::getInt(), which throws BadRequestException on anything
 *     FILTER_VALIDATE_INT rejects — an empty string included. The picker sends company_id="" until
 *     a company is chosen, so "no company yet" surfaced as an HTTP 400.
 */
final class AdminProductSearchEndpointCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('product-search-399@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function seedCatalog(FunctionalTester $I): void
    {
        foreach ([['Zenith Mud Tire', 'ZM-1000'], ['Boulder Trail Tire', 'BT-2000'], ['Canyon Grip Tire', 'CG-3000']] as [$name, $sku]) {
            $product = (new ProductCore())->setName($name)->setSku($sku)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $product->activate();
            $I->haveInRepository($product);
        }
    }

    /** @return list<array<string, mixed>> */
    private function grabProducts(FunctionalTester $I): array
    {
        $payload = json_decode($I->grabPageSource(), true);
        $I->assertIsArray($payload, 'The endpoint must answer with a JSON object.');
        $I->assertArrayHasKey('products', $payload);
        $I->assertIsArray($payload['products']);

        return $payload['products'];
    }

    /**
     * @param list<string> $paths
     */
    private function assertShortTermReturnsNothing(FunctionalTester $I, array $paths): void
    {
        foreach ($paths as $path) {
            $I->amOnPage($path);
            $I->seeResponseCodeIs(200);
            $I->assertSame([], $this->grabProducts($I), "A term under two characters must match nothing: {$path}");
        }
    }

    public function shortTermsReturnNoProductsOnBothEndpoints(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedCatalog($I);

        // The whole point of the floor: no term, and a one-character term, must not be answered
        // with a slice of the catalog just because they matched nothing specific.
        $this->assertShortTermReturnsNothing($I, [
            '/admin/order/products/search?q=',
            '/admin/order/products/search?q=z',
            '/admin/estimate/products/search?q=',
            '/admin/estimate/products/search?q=z',
        ]);
    }

    public function twoCharacterTermMatchesOnNameAndOnSku(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedCatalog($I);

        foreach (['/admin/order/products/search', '/admin/estimate/products/search'] as $endpoint) {
            // Matched by name ("Zenith") ...
            $I->amOnPage($endpoint . '?q=zenith');
            $I->seeResponseCodeIs(200);
            $names = array_column($this->grabProducts($I), 'name');
            $I->assertContains('Zenith Mud Tire', $names, "Name match failed on {$endpoint}");
            $I->assertNotContains('Boulder Trail Tire', $names, "Non-matching product leaked into {$endpoint}");

            // ... and by SKU, which is the other half of the acceptance criterion: a hit in either
            // field returns the product, it does not have to appear in both.
            $I->amOnPage($endpoint . '?q=BT-2000');
            $I->seeResponseCodeIs(200);
            $skuNames = array_column($this->grabProducts($I), 'name');
            $I->assertContains('Boulder Trail Tire', $skuNames, "SKU match failed on {$endpoint}");
            $I->assertNotContains('Zenith Mud Tire', $skuNames, "Non-matching product leaked into {$endpoint}");
        }
    }

    /**
     * Paging, and the reason it needs a stable sort.
     *
     * A flat cap of 50 silently hid every match past the fiftieth with nothing on screen to say so;
     * the picker now asks for the next page on "Show more". The risk paging introduces is ordering:
     * the query sorts by name, real catalogs collide heavily on name, and LIMIT/OFFSET over a
     * non-unique sort may return a row twice or skip it between pages — hence the id tiebreaker. This
     * seeds 60 products sharing ONE name so the tiebreaker is the only thing separating them, then
     * asserts the two pages are disjoint and complete.
     */
    public function pagingReturnsEveryMatchExactlyOnceEvenWhenNamesCollide(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $total = 60;
        for ($i = 0; $i < $total; $i++) {
            $product = (new ProductCore())->setName('Collide Tire')->setSku(sprintf('COL-%03d', $i))->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $product->activate();
            $I->haveInRepository($product);
        }

        foreach (['/admin/order/products/search', '/admin/estimate/products/search'] as $endpoint) {
            $I->amOnPage($endpoint . '?q=Collide');
            $I->seeResponseCodeIs(200);
            $first = json_decode($I->grabPageSource(), true);
            $I->assertCount(50, $first['products'], "the first page must be a full page: {$endpoint}");
            $I->assertTrue($first['hasMore'], "hasMore must flag the remaining matches: {$endpoint}");

            $I->amOnPage($endpoint . '?q=Collide&offset=50');
            $I->seeResponseCodeIs(200);
            $second = json_decode($I->grabPageSource(), true);
            $I->assertCount($total - 50, $second['products'], "the last page must hold the remainder: {$endpoint}");
            $I->assertFalse($second['hasMore'], "the last page must not claim more: {$endpoint}");

            $firstIds = array_column($first['products'], 'id');
            $secondIds = array_column($second['products'], 'id');

            $I->assertSame([], array_intersect($firstIds, $secondIds), "a product appeared on both pages: {$endpoint}");
            $I->assertCount($total, array_unique(array_merge($firstIds, $secondIds)), "paging lost or repeated a match: {$endpoint}");
        }
    }

    public function anEmptyCompanyIdIsPricedWithoutAPriceListRatherThanRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedCatalog($I);

        // company_id="" is what the picker sends before a company is chosen. It must be read as
        // "no price list to price against", never as a malformed request.
        foreach (['/admin/order/products/search', '/admin/estimate/products/search'] as $endpoint) {
            foreach (['', 'abc'] as $companyId) {
                $I->amOnPage($endpoint . '?q=zenith&company_id=' . $companyId . '&region=Main');
                $I->seeResponseCodeIs(200);
                $names = array_column($this->grabProducts($I), 'name');
                $I->assertContains('Zenith Mud Tire', $names, "An unusable company_id must still search: {$endpoint}");
            }
        }
    }
}
