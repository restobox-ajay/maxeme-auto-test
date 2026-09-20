<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCategory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers the suite's own sendAjaxPostRequest(), not the application (#228).
 *
 * Codeception's InnerBrowser sends the request but never moves its crawler onto the response, so
 * every DOM assertion after one — seeElement(), dontSeeElement(), grabAttributeFrom(),
 * seeInField() — kept reading the page loaded *before* the POST. A test written that way passes
 * without exercising the POST at all: it only fails if the previous page happened to lack the
 * element too. Nothing in review shows it, and the suite stays green either way.
 *
 * Tests\Support\Helper\Functional overrides the action to route through _loadPage(). Both
 * directions are asserted here, because a fix that only moved the crawler *off* the old page would
 * satisfy half of it while leaving the assertions just as inert.
 *
 * The admin category screens are the vehicle: /admin/category and the category form render
 * different, easily distinguished markup, and the form POST answers with HTML rather than JSON.
 */
final class AjaxPostCrawlerCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('ajax-crawler-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /** The POST's own markup is what the assertions see. */
    public function domAssertionsAfterAnAjaxPostReadThePostResponse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new ProductCategory())->setName('Ajax Crawler Cat')->setStatus('Visible'));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->seeElement('.js-category-status');
        $token = $I->csrfToken();

        // A blank name fails validation, so this answers 422 with the category form re-rendered.
        $I->sendAjaxPostRequest('/admin/category/create', ['_token' => $token, 'name' => '']);

        $I->seeElement('form.form-grid input[name="name"]');
        $I->dontSeeElement('.js-category-status');
    }

    /** ...and the page it replaced is no longer what they see. */
    public function domAssertionsAfterAnAjaxPostNoLongerReadThePreviousPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $category = (new ProductCategory())->setName('Ajax Crawler Stale Cat')->setStatus('Visible');
        $I->haveInRepository($category);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/category/index');
        $I->seeElement('.js-category-status');

        // Answers with JSON, which has no DOM at all — so the row buttons from the index must be
        // gone. Before the override they were still there, and dontSeeElement() would have failed.
        $I->sendAjaxPostRequest('/admin/category/toggle/' . $category->getId(), ['_token' => 'forged']);
        $I->seeResponseCodeIs(403);

        $I->dontSeeElement('.js-category-status');
    }
}
