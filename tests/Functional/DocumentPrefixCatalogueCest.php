<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\CreditMemoNumberGenerator;
use App\Service\Document\DocumentPrefixCatalogue;
use App\Service\OrderNumberGenerator;
use App\Service\SalesReturnNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The Document Prefixes screen renders whatever registered, not a list somebody remembered (#615).
 *
 * ## Why the first test scans source instead of naming keys
 *
 * A test that asserts the eight known prefixes are on the screen passes forever while a ninth goes
 * missing — which is not a hypothetical, it is the history of this issue. Credit memos (#586) and
 * sales returns (#596) each shipped a `*_number_prefix` setting their generator honours and no
 * screen could set, and nothing failed. `docs/QUEUE.md` states the rule as "enumerate, do not
 * list".
 *
 * So the enumeration is taken from the one place that cannot be forgotten: the source of the
 * generator that reads the setting. A tenth document type gets a generator with a
 * `*_number_prefix` key in it before it gets anything else, and this test fails until that key is
 * declared by a provider.
 */
final class DocumentPrefixCatalogueCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('prefix-catalogue-test@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function storedPrefix(FunctionalTester $I, string $key): ?string
    {
        $I->grabService(AppSettings::class)->clearCache();

        return $I->grabService(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT setting_value FROM app_setting WHERE setting_key = ?', [$key]) ?: null;
    }

    /**
     * Every `*_number_prefix` setting key anywhere in shipped PHP is declared by a provider and has
     * a field on the screen.
     */
    public function everyPrefixSettingInTheSourceIsOnTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $inSource = $this->prefixKeysInSource();
        $I->assertNotEmpty($inSource, 'The source scan found no *_number_prefix keys, so it is not scanning anything.');

        $registered = $I->grabService(DocumentPrefixCatalogue::class)->keys();

        $missing = array_values(array_diff($inSource, $registered));
        $I->assertSame([], $missing, sprintf(
            'These settings are read by a number generator but no provider declares them, so no screen can set them: %s',
            implode(', ', $missing),
        ));

        $I->amOnPage('/admin/settings/document-prefixes');
        $I->seeResponseCodeIsSuccessful();
        foreach ($registered as $key) {
            $I->seeElement(sprintf('input[name="%s"]', $key));
        }
    }

    /** A bundle's prefixes are on the screen because the bundle put them there, and leave with it. */
    public function aBundlesPrefixesDisappearWhenItIsSwitchedOff(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $I->seeElement('input[name="purchase_order_number_prefix"]');
        $I->seeElement('input[name="vendor_bill_number_prefix"]');

        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('ProcurementBundle');

        $I->amOnPage('/admin/settings/document-prefixes');
        $I->dontSeeElement('input[name="purchase_order_number_prefix"]');
        $I->dontSeeElement('input[name="vendor_bill_number_prefix"]');
        // Core's are not a bundle's and never leave.
        $I->seeElement('input[name="order_number_prefix"]');
    }

    /**
     * The conducted case (#624): change the prefix on the real screen, then allocate a real number.
     *
     * A stored `app_setting` row is not proof — the generator has its own default and could be
     * ignoring the row entirely, which is exactly the state credit memos shipped in. So the number
     * is allocated afterwards through the same service the order screens call.
     */
    public function changingTheSalesOrderPrefixNumbersTheNextOrderWithIt(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'order_number_prefix' => 'ZZ-',
        ]);

        $I->assertSame('ZZ-', $this->storedPrefix($I, 'order_number_prefix'));

        $number = $I->grabService(OrderNumberGenerator::class)->next($I->grabService(EntityManagerInterface::class));
        $I->assertStringStartsWith('ZZ-', $number);

        // The row that should NOT have changed. It was never submitted, so it must still not exist
        // — "absent" and "submitted empty" are different things, and conflating them is what kept
        // a fourth field off this screen for two releases.
        $I->assertNull($this->storedPrefix($I, 'quote_number_prefix'));
        $I->assertNull($this->storedPrefix($I, 'invoice_number_prefix'));
    }

    /**
     * The two prefixes #615 existed to unlock, verified rather than assumed.
     *
     * Credit memos (#586) and sales returns (#596) each shipped a `*_number_prefix` setting their
     * generator reads and NO SCREEN COULD SET. That is the specific defect this collection closed,
     * and "the field is on the screen" does not prove it closed — a field that saves a row the
     * generator ignores looks identical. So the prefix is set through the real screen and the number
     * is then allocated through the same generator the create flows call.
     *
     * Read back BY COLUMN on both sides: `app_setting.setting_value` for what was stored, and
     * `document_number_counter.prefix` for the series the allocator actually opened — that row is
     * written by DocumentNumberAllocator from the prefix the generator handed it, so it is the
     * generator's own record of which prefix it used, not a restatement of the input.
     *
     * Two methods rather than one shared helper: a shared one would have to be told which generator
     * to call, and the point is that these are two independent generators that were each separately
     * unsettable.
     */
    public function theCreditMemoPrefixIsSettableOnTheScreenAndHonouredByItsGenerator(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $I->seeElement('input[name="credit_memo_number_prefix"]');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'credit_memo_number_prefix' => 'QCN-',
        ]);

        $I->assertSame('QCN-', $this->storedPrefix($I, 'credit_memo_number_prefix'));

        $em = $I->grabService(EntityManagerInterface::class);
        $number = $I->grabService(CreditMemoNumberGenerator::class)->next($em);

        $I->assertStringStartsWith('QCN-', $number);
        $I->assertNotSame(CreditMemoNumberGenerator::DEFAULT_PREFIX, 'QCN-', 'positive control: the test prefix is not the built-in default, so a generator ignoring the setting would fail above');

        // By column: the series the allocator opened carries the configured prefix.
        $I->assertSame(
            'QCN-',
            (string) $em->getConnection()->fetchOne(
                'SELECT prefix FROM document_number_counter WHERE kind = ?',
                ['credit_memo'],
            ),
            'document_number_counter.prefix for the credit_memo series',
        );

        // The row that should NOT have changed: the sales return prefix was not submitted, so it is
        // still absent rather than blanked.
        $I->assertNull($this->storedPrefix($I, 'sales_return_number_prefix'));
    }

    /** The same for sales returns (#596), whose number leaves the building on the side of a box. */
    public function theSalesReturnPrefixIsSettableOnTheScreenAndHonouredByItsGenerator(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $I->seeElement('input[name="sales_return_number_prefix"]');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'sales_return_number_prefix' => 'QRMA-',
        ]);

        $I->assertSame('QRMA-', $this->storedPrefix($I, 'sales_return_number_prefix'));

        $em = $I->grabService(EntityManagerInterface::class);
        $number = $I->grabService(SalesReturnNumberGenerator::class)->next($em);

        $I->assertStringStartsWith('QRMA-', $number);
        $I->assertNotSame(SalesReturnNumberGenerator::DEFAULT_PREFIX, 'QRMA-', 'positive control: the test prefix is not the built-in default');

        $I->assertSame(
            'QRMA-',
            (string) $em->getConnection()->fetchOne(
                'SELECT prefix FROM document_number_counter WHERE kind = ?',
                ['sales_return'],
            ),
            'document_number_counter.prefix for the sales_return series',
        );

        $I->assertNull($this->storedPrefix($I, 'credit_memo_number_prefix'));
    }

    /** A prefix already stored is untouched by a submission that does not carry its field. */
    public function aPrefixTheFormDidNotCarryIsLeftExactlyAsItWas(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'order_number_prefix' => 'AA-',
            'credit_memo_number_prefix' => 'CC-',
        ]);
        $I->assertSame('AA-', $this->storedPrefix($I, 'order_number_prefix'));
        $I->assertSame('CC-', $this->storedPrefix($I, 'credit_memo_number_prefix'));

        $I->amOnPage('/admin/settings/document-prefixes');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'order_number_prefix' => 'BB-',
        ]);

        $I->assertSame('BB-', $this->storedPrefix($I, 'order_number_prefix'));
        $I->assertSame('CC-', $this->storedPrefix($I, 'credit_memo_number_prefix'));
    }

    /**
     * Every `*_number_prefix` string literal in shipped PHP, deduplicated.
     *
     * Deliberately a text scan rather than a container lookup: the point is to catch a key that
     * exists in code and nowhere else, and a container can only report what someone registered.
     *
     * @return list<string>
     */
    private function prefixKeysInSource(): array
    {
        $root = \dirname(__DIR__, 2);
        $keys = [];

        foreach ([$root . '/src', $root . '/modules'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // A bundle's own tests are not shipped code and may name a key for a fixture.
                if (str_contains(str_replace('\\', '/', $file->getPathname()), '/tests/')) {
                    continue;
                }

                preg_match_all("/'([a-z0-9_]+_number_prefix)'/", (string) file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }
}
