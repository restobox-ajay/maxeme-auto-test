<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers issue #122: document number prefixes may contain a hyphen (QT-, SO-), not just letters.
 *
 * The invoice prefix joined the pair with #539. It is asserted alongside them rather than in a test
 * of its own because the screen validates and writes the prefixes as ONE set — a submission is
 * accepted or rejected whole — so a third field that was only ever posted on its own would not
 * exercise the thing that actually broke here.
 */
final class AdminDocumentPrefixesCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('doc-prefix-test@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function prefix(FunctionalTester $I, string $key): ?string
    {
        $I->grabService(AppSettings::class)->clearCache();

        return $I->grabService(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT setting_value FROM app_setting WHERE setting_key = ?', [$key]) ?: null;
    }

    public function hyphenatedPrefixesAreAccepted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'order_number_prefix' => 'SO-',
            'quote_number_prefix' => 'QT-',
            'invoice_number_prefix' => 'INV-',
        ]);

        $I->assertSame('SO-', $this->prefix($I, 'order_number_prefix'));
        $I->assertSame('QT-', $this->prefix($I, 'quote_number_prefix'));
        $I->assertSame('INV-', $this->prefix($I, 'invoice_number_prefix'));
    }

    /**
     * The invoice prefix is validated by the same rule as the other two — the validator loops over
     * every submitted prefix rather than checking two named ones, so a bad third field has to fail
     * the whole set exactly as a bad first one does.
     */
    public function anInvalidInvoicePrefixRejectsTheWholeSet(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'order_number_prefix' => 'SO-',
            'quote_number_prefix' => 'QT-',
            'invoice_number_prefix' => 'I N V',   // spaces are not allowed
        ]);

        $I->assertNull($this->prefix($I, 'invoice_number_prefix'));
        // Rejected as a set, so the two valid prefixes beside it are not written either.
        $I->assertNull($this->prefix($I, 'order_number_prefix'));
    }

    /** The screen shows INV- before any row exists, matching InvoiceNumberGenerator's own default. */
    public function theInvoicePrefixDefaultsToInv(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $I->seeElement('input[name="invoice_number_prefix"][value="INV-"]');
    }

    public function invalidPrefixesAreStillRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/settings/document-prefixes');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/settings/document-prefixes', [
            '_token' => $token,
            'order_number_prefix' => 'B A D',   // spaces are not allowed
            'quote_number_prefix' => 'QT',
            'invoice_number_prefix' => 'INV-',
        ]);

        // Rejected as a set — neither prefix is written.
        $I->assertNull($this->prefix($I, 'order_number_prefix'));
    }
}
