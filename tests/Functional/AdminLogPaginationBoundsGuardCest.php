<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * `page` is attacker-supplied and arrives via getInt(), which happily returns 0 or a negative.
 * Every one of these list endpoints turns it straight into an offset — `setFirstResult(($page - 1)
 * * $limit)` — so `?page=0` asks for a negative offset and the failure surfaces as a raw 500 rather
 * than a page.
 *
 * `limit` was already handled; only `page` was exposed. The three log screens are where it was
 * found, but the same unguarded read appears on several other admin lists, so they are pinned here
 * too rather than left to be rediscovered one at a time.
 */
final class AdminLogPaginationBoundsGuardCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('pagination-bounds-guard@example.test')
            ->setRoles(['ROLE_SUPER_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * @return list<string>
     */
    public static function endpoints(): array
    {
        return [
            '/admin/audit-log',
            '/admin/email-log',
            '/admin/error-log',
            '/admin/order',
            '/admin/user',
            '/admin/category/index',
            '/admin/price-list/index',
        ];
    }

    public function nonPositivePageMustNotCrashAnyLogEndpoint(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        foreach (self::endpoints() as $path) {
            foreach (['0', '-1', '-9999'] as $page) {
                $I->amOnPage($path . '?page=' . $page);
                // 2xx or a deliberate 4xx are both fine; a 5xx is the defect.
                $I->seeResponseCodeIsBetween(200, 499);
            }
        }
    }

    /** A non-positive page must behave as page 1, not as an empty or error page. */
    public function pageZeroShowsTheFirstPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/audit-log?page=1');
        $firstPage = $I->grabPageSource();

        $I->amOnPage('/admin/audit-log?page=0');
        $I->seeResponseCodeIsSuccessful();

        // Compare the rendered rows, not the whole document: the requested page number is echoed
        // back into the pagination links, so page=0 and page=1 are legitimately not byte-identical
        // even when they show the same data.
        $rows = static fn (string $html): string => implode('|', (array) (preg_match_all('#<tbody.*?</tbody>#s', $html, $m) ? $m[0] : []));
        $I->assertSame($rows($firstPage), $rows($I->grabPageSource()), 'page=0 should show the same rows as page 1');
    }

}
