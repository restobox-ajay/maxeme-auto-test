<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use CustomHeaderFooterBundle\Service\CustomHeaderFooterStore;
use CustomHeaderFooterBundle\Tests\FunctionalTester;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Boots the real Symfony kernel/container/Twig and hits real routes against a throwaway
 * SQLite database (var/data_test.db, configured via .env.test — never the
 * dev database at var/data_dev.db). This proves the customer_head_top /
 * customer_body_end injection points actually render on a live customer page, never leak
 * onto the admin side, respect the Bundle Management Active/Inactive switch, and that the
 * admin save form really persists what an admin submits.
 */
final class CustomHeaderFooterCest
{
    private const HEADER_MARKER = '<meta name="e2e-header-marker" content="1">';
    private const FOOTER_MARKER = '<script id="e2e-footer-marker">/* footer */</script>';

    public function _before(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $schemaTool->updateSchema($em->getMetadataFactory()->getAllMetadata(), true);

        foreach ($em->getRepository(AppSetting::class)->findBy(['settingKey' => ['custom_header_html', 'custom_footer_html']]) as $row) {
            $em->remove($row);
        }
        $em->flush();
        $em->clear();

        // Reset to "this bundle is switched on", which is what every test below but the Inactive one
        // assumes. It used to be done by DELETING the status row, because absence of a row meant
        // Active — that was the whole default. The default is now the other way round: a bundle with
        // no row is INERT until somebody activates it, so deleting the row here reset the bundle to
        // OFF and the two render tests failed on an empty page for the most confusing possible
        // reason. Written explicitly instead, through the one activation method the Activate button
        // and `app:bundle:activate` both use.
        $I->grabService(BundleStatusRepository::class)->activate('CustomHeaderFooterBundle');
        $em->clear();

        $I->grabService(AppSettings::class)->clearCache();
    }

    public function headerAndFooterHtmlRenderOnARealCustomerPage(FunctionalTester $I): void
    {
        $store = $I->grabService(CustomHeaderFooterStore::class);
        $store->saveHeaderHtml(self::HEADER_MARKER);
        $store->saveFooterHtml(self::FOOTER_MARKER);

        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInSource(self::HEADER_MARKER);
        $I->seeInSource(self::FOOTER_MARKER);

        $html = $I->grabPageSource();
        $I->assertLessThan(
            strpos($html, '</head>'),
            strpos($html, self::HEADER_MARKER),
            'expected the custom header HTML to render inside <head>',
        );
        $I->assertGreaterThan(
            strripos($html, '<body'),
            strpos($html, self::FOOTER_MARKER),
            'expected the custom footer HTML to render after the opening <body> tag',
        );
        $I->assertLessThan(
            strripos($html, '</body>'),
            strpos($html, self::FOOTER_MARKER),
            'expected the custom footer HTML to render before </body>',
        );
    }

    public function headerAndFooterHtmlNeverAppearOnTheAdminSide(FunctionalTester $I): void
    {
        $store = $I->grabService(CustomHeaderFooterStore::class);
        $store->saveHeaderHtml(self::HEADER_MARKER);
        $store->saveFooterHtml(self::FOOTER_MARKER);

        // /admin/login is PUBLIC_ACCESS (see config/packages/security.yaml), so this needs no
        // auth — but it does need the admin host: AdminHostSubscriber 404s any /admin/* request
        // that doesn't arrive on ADMIN_HOST (admin.localhost by default), so this also doubles
        // as a check that the admin host itself never leaks customer-side injected content.
        // The Symfony module's in-process client can't open absolute/external URLs, so the host
        // is set via a server parameter instead of an absolute URL.
        $I->haveServerParameter('HTTP_HOST', 'admin.localhost');
        $I->amOnPage('/admin/login');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInSource(self::HEADER_MARKER);
        $I->dontSeeInSource(self::FOOTER_MARKER);
    }

    public function nothingRendersWhenTheBundleIsToggledInactiveInBundleManagement(FunctionalTester $I): void
    {
        $store = $I->grabService(CustomHeaderFooterStore::class);
        $store->saveHeaderHtml(self::HEADER_MARKER);
        $store->saveFooterHtml(self::FOOTER_MARKER);

        $I->grabService(BundleStatusRepository::class)
            ->ensureBySource('CustomHeaderFooterBundle')
            ->setStatus(BundleStatus::STATUS_INACTIVE);
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInSource(self::HEADER_MARKER);
        $I->dontSeeInSource(self::FOOTER_MARKER);
    }

    public function anAdminCanSaveNewHeaderAndFooterHtmlThroughTheRealAdminForm(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $admin = (new AdminUser())->setEmail('e2e-admin@example.test')->setPassword('not-checked-by-amLoggedInAs');
        $em->persist($admin);
        $em->flush();

        $I->amLoggedInAs($admin, 'admin');
        $I->haveServerParameter('HTTP_HOST', 'admin.localhost');
        $I->amOnPage('/admin/bundles/custom-header-footer');
        $I->seeResponseCodeIsSuccessful();

        // submitForm() resolves the form's action against the current page's absolute URI
        // (http://admin.localhost/...), which Codeception's in-process Symfony client refuses
        // to open — it only allow-lists domains declared via routing `host:` requirements, and
        // this app's admin/customer split is enforced by AdminHostSubscriber instead (see
        // config/packages/security.yaml / src/EventSubscriber/AdminHostSubscriber.php), so no
        // route declares one. Reading the real rendered CSRF token and posting to a relative
        // path exercises the exact same form/controller contract without hitting that guard.
        $token = $I->grabValueFrom('form[action$="/admin/bundles/custom-header-footer/save"] input[name="_token"]');
        $I->sendAjaxPostRequest('/admin/bundles/custom-header-footer/save', [
            '_token' => $token,
            'header_html' => '<meta name="from-admin-form-header" content="1">',
            'footer_html' => '<meta name="from-admin-form-footer" content="1">',
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->see('Custom header and footer HTML updated.');

        $store = $I->grabService(CustomHeaderFooterStore::class);
        $I->assertSame('<meta name="from-admin-form-header" content="1">', $store->getHeaderHtml());
        $I->assertSame('<meta name="from-admin-form-footer" content="1">', $store->getFooterHtml());
    }

    public function savingWithoutACsrfTokenIsRejected(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $admin = (new AdminUser())->setEmail('e2e-admin-no-csrf@example.test')->setPassword('not-checked-by-amLoggedInAs');
        $em->persist($admin);
        $em->flush();

        $I->amLoggedInAs($admin, 'admin');
        // No _token field at all — the controller must reject this the same way it would
        // reject a forged/expired one, rather than silently accepting an untokened save.
        // Must hit the admin host directly (see the other tests' comment on AdminHostSubscriber)
        // or this would 404 before ever reaching the controller and "pass" for the wrong reason.
        $I->haveServerParameter('HTTP_HOST', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/bundles/custom-header-footer/save', [
            'header_html' => '<meta name="should-not-be-saved" content="1">',
        ]);
        // The test client follows the controller's redirect back to the index automatically,
        // landing on 200 — the meaningful proof this reached the controller (rather than
        // 404'ing before it got that far) is the flash error message on that final page.
        $I->seeResponseCodeIsSuccessful();
        $I->see('Your session expired. Please try again.');

        $store = $I->grabService(CustomHeaderFooterStore::class);
        $I->assertSame('', $store->getHeaderHtml());
    }
}
