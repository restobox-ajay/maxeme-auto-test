<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCategory;
use App\Entity\Redirect;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\RedirectController — the /admin/redirect list, create()/update() form
 *  validation (including the duplicate-source and self-loop guards), and delete(). */
final class AdminRedirectCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-redirect-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeRedirect(FunctionalTester $I, string $sourcePath, string $destinationUrl): Redirect
    {
        $redirect = (new Redirect())
            ->setSourcePath($sourcePath)
            ->setDestinationType(Redirect::DESTINATION_TYPE_URL)
            ->setDestinationUrl($destinationUrl)
            ->setRedirectType(Redirect::REDIRECT_TYPE_PERMANENT)
            ->setQueryHandling(Redirect::QUERY_HANDLING_PASS);
        $I->haveInRepository($redirect);

        return $redirect;
    }

    private function grabToken(FunctionalTester $I, string $selector): string
    {
        return $I->grabAttributeFrom($selector, 'value');
    }

    public function indexListsRedirects(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeRedirect($I, '/redirect-index-source', '/redirect-index-destination');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect');
        $I->seeResponseCodeIsSuccessful();
        $I->see('/redirect-index-source');
        $I->see('/redirect-index-destination');
    }

    public function createRendersTheFormWithDefaults(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create');
    }

    public function creatingAUrlRedirectWithValidDataPersistsAndRedirectsToIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/create-url-source',
            'destination_type' => 'url',
            'destination_url' => '/create-url-destination',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeCurrentUrlEquals('/admin/redirect');

        $I->seeInRepository(Redirect::class, [
            'sourcePath' => '/create-url-source',
            'destinationUrl' => '/create-url-destination',
            'redirectType' => 301,
            'queryHandling' => 'pass',
        ]);
    }

    public function creatingACategoryRedirectPersistsTheCategoryAssociation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $category = (new ProductCategory())->setName('RD Category')->setStatus('Visible');
        $I->haveInRepository($category);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/create-category-source',
            'destination_type' => 'category',
            'destination_category_id' => (string) $category->getId(),
            'redirect_type' => '302',
            'query_handling' => 'strip',
        ]);
        $I->seeCurrentUrlEquals('/admin/redirect');

        $I->seeInRepository(Redirect::class, [
            'sourcePath' => '/create-category-source',
            'destinationType' => 'category',
            'destinationCategory' => $category->getId(),
            'redirectType' => 302,
            'queryHandling' => 'strip',
        ]);
    }

    public function creatingWithoutALeadingSlashFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => 'no-leading-slash',
            'destination_type' => 'url',
            'destination_url' => '/somewhere',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('must be a path starting with');

        $I->dontSeeInRepository(Redirect::class, ['sourcePath' => 'no-leading-slash']);
    }

    public function creatingWithAnAdminSourcePathFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/admin/orders',
            'destination_type' => 'url',
            'destination_url' => '/somewhere',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('admin paths are never checked');
    }

    public function creatingADuplicateSourcePathFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeRedirect($I, '/duplicate-source', '/first-destination');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/duplicate-source',
            'destination_type' => 'url',
            'destination_url' => '/second-destination',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('already exists');
    }

    public function creatingASelfLoopingRedirectFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/loop-source',
            'destination_type' => 'url',
            'destination_url' => '/loop-source',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('redirect loop');
    }

    /** Matching is path-only, so a query string on the destination doesn't escape the loop. */
    public function creatingALoopingRedirectThatOnlyAddsAQueryStringFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/loop-query-source',
            'destination_type' => 'url',
            'destination_url' => '/loop-query-source?utm_source=x',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('redirect loop');

        $I->dontSeeInRepository(Redirect::class, ['sourcePath' => '/loop-query-source']);
    }

    /** Same path, differing only by fragment — also still a loop. */
    public function creatingALoopingRedirectThatOnlyAddsAFragmentFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/loop-fragment-source',
            'destination_type' => 'url',
            'destination_url' => '/loop-fragment-source#section',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('redirect loop');
    }

    /** A path that merely shares a prefix with the source is a different page — must be allowed. */
    public function creatingARedirectToAPathSharingThePrefixIsAllowed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/prefix-source',
            'destination_type' => 'url',
            'destination_url' => '/prefix-source-extended',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeCurrentUrlEquals('/admin/redirect');

        $I->seeInRepository(Redirect::class, [
            'sourcePath' => '/prefix-source',
            'destinationUrl' => '/prefix-source-extended',
        ]);
    }

    /** An absolute URL whose path matches the source targets another origin — must be allowed. */
    public function creatingARedirectToAnAbsoluteUrlWithTheSamePathIsAllowed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/create');
        $token = $this->grabToken($I, 'input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => $token,
            'source_path' => '/absolute-source',
            'destination_type' => 'url',
            'destination_url' => 'https://example.com/absolute-source',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeCurrentUrlEquals('/admin/redirect');

        $I->seeInRepository(Redirect::class, [
            'sourcePath' => '/absolute-source',
            'destinationUrl' => 'https://example.com/absolute-source',
        ]);
    }

    public function creatingWithAnInvalidCsrfTokenIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/redirect/create', [
            '_token' => 'not-a-real-token',
            'source_path' => '/csrf-rejected',
            'destination_type' => 'url',
            'destination_url' => '/somewhere',
            'redirect_type' => '301',
            'query_handling' => 'pass',
        ]);
        $I->seeResponseCodeIs(403);

        $I->dontSeeInRepository(Redirect::class, ['sourcePath' => '/csrf-rejected']);
    }

    public function updateRendersThePrefilledFormAndPersistsChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $redirect = $this->makeRedirect($I, '/update-source', '/update-destination-original');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/' . $redirect->getId() . '/update');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('source_path', '/update-source');

        $token = $this->grabToken($I, 'input[name="_token"]');
        $I->sendAjaxPostRequest('/admin/redirect/' . $redirect->getId() . '/update', [
            '_token' => $token,
            'source_path' => '/update-source',
            'destination_type' => 'url',
            'destination_url' => '/update-destination-renamed',
            'redirect_type' => '302',
            'query_handling' => 'match',
        ]);
        $I->seeCurrentUrlEquals('/admin/redirect');

        $I->seeInRepository(Redirect::class, [
            'id' => $redirect->getId(),
            'destinationUrl' => '/update-destination-renamed',
            'redirectType' => 302,
            'queryHandling' => 'match',
        ]);
    }

    public function updatingAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect/999999999/update');
        $I->seeCurrentUrlEquals('/admin/redirect');
        $I->see('Redirect could not be found.');
    }

    public function deletingAnExistingRedirectRemovesItAndRedirectsToIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $redirect = $this->makeRedirect($I, '/delete-source', '/delete-destination');
        $id = $redirect->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect');
        $token = $this->grabToken($I, 'form[action="/admin/redirect/' . $id . '/delete"] input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/redirect/' . $id . '/delete', ['_token' => $token]);
        $I->seeCurrentUrlEquals('/admin/redirect');

        $I->dontSeeInRepository(Redirect::class, ['id' => $id]);
    }

    public function deletingWithAnInvalidCsrfTokenLeavesItInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $redirect = $this->makeRedirect($I, '/delete-csrf-source', '/delete-csrf-destination');
        $id = $redirect->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/redirect');
        $I->sendAjaxPostRequest('/admin/redirect/' . $id . '/delete', ['_token' => 'not-a-real-token']);

        $I->seeInRepository(Redirect::class, ['id' => $id]);
    }
}
