<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\EmailTemplate;
use App\Service\Email\ShippedEmailTemplates;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The admin panel over a catalogue that mostly is not in the database (#507).
 *
 * Every assertion here would have been impossible before: the 22 shipped templates lived as rows
 * seeded by the migration chain, and the functional suite builds its schema from entity metadata
 * and runs no migrations — so this table has always been empty in tests, and the template screen
 * has always been an empty list nobody looked at.
 *
 * That is exactly the state a fresh install is in now, which is why these tests are worth having:
 * the list, the editor, the preview and every send path have to work with nothing in the table.
 */
final class AdminEmailTemplateCatalogueCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-email-template-catalogue@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** The list is the shipped catalogue, not the table — with an empty table it still shows all 22. */
    public function theListShowsEveryShippedTemplateWithNoRowsAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->assertSame(0, $I->grabNumRecords(EmailTemplate::class), 'this test is only meaningful on an empty table');

        $I->amOnPage('/admin/email-template');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Invoice (Customer)');
        $I->see('Order Received');
        $I->see('Company Registration');
        $I->dontSee('No email templates found.');
    }

    /** Nothing customized, so everything reads Default and nothing offers a revert. */
    public function templatesWithNoRowReadAsDefaultAndOfferNoRevert(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/email-template');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Default');
        $I->dontSee('Modified');
        $I->dontSeeElement('button[formaction$="/revert"]');
    }

    /** Editing a shipped template opens pre-filled with what ships, not with blanks. */
    public function theEditorOpensAShippedTemplatePreFilled(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/email-template/order_approved/update');
        $I->seeResponseCodeIsSuccessful();

        $I->seeInField('subject', ShippedEmailTemplates::get('order_approved')['subject']);
    }

    /** The preview renders a shipped template that has no row of its own. */
    public function previewRendersATemplateWithNoRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/email-template/preview/order_approved');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Template Render Error');
    }

    /**
     * #781: every shipped template that pulls in _order_summary.html.twig or
     * _quote_summary.html.twig renders a line's `unitOfMeasure`, `id` and `quantityEntered` —
     * none of which were in getMockDataForTemplate()'s mock `lines` rows, so ANY of these ten
     * 500ed under strict_variables the moment a real line existed to print. order_approved above
     * does not touch either partial, which is exactly why that one test never caught this.
     */
    public function previewRendersEveryTemplateThatPrintsALineTable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        foreach ([
            'order_received', 'order_received_admin', 'order_status_update',
            'quote_approved_admin', 'quote_declined_admin', 'quote_provided',
            'quote_request_admin', 'quote_request_received',
        ] as $code) {
            $I->amOnPage('/admin/email-template/preview/' . $code);
            $I->seeResponseCodeIsSuccessful();
            $I->dontSee('Template Render Error');
        }
    }

    /**
     * #781: invoice_customer/invoice_self are the two templates InvoiceController's real send
     * hands an `invoice` variable to (alongside `order`) — getMockDataForTemplate() had no
     * `invoice` entry at all, so a body written against it (exactly the customized bodies this
     * instance's own database carries) threw "Variable 'invoice' does not exist" on every preview.
     * Seeded here rather than relying on the shipped file, which does not reference `invoice.*` and
     * so would not have caught this — the gap only shows up once a real body uses the variable the
     * real send actually provides.
     */
    public function previewRendersACustomizedInvoiceBodyThatReadsTheInvoiceVariable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $template = (new EmailTemplate())
            ->setCode('invoice_customer')
            ->setBody("{% extends 'emails/layout.html.twig' %}\n{% block body %}\n<p>Invoice {{ invoice.documentNumber }} for {{ invoice.company.name }}, total \${{ invoice.total }} on {{ invoice.documentDate }}.</p>\n{% endblock %}");
        $I->haveInRepository($template);

        $I->amOnPage('/admin/email-template/preview/invoice_customer');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Template Render Error');
        $I->see('INV-2026-001');
    }

    /**
     * Saving a real change writes a row holding ONLY that change — the point of the whole design.
     * The untouched fields stay null so they keep tracking the shipped versions.
     */
    public function savingAChangeStoresOnlyTheChangedField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $shipped = ShippedEmailTemplates::get('order_approved');

        $I->amOnPage('/admin/email-template/order_approved/update');
        // A direct POST rather than submitForm(): the form's action is an absolute admin.localhost
        // URL, which the Symfony module refuses to follow as external.
        $I->sendAjaxPostRequest('/admin/email-template/order_approved/update', [
            '_token' => $I->csrfToken(),
            'module' => $shipped['module'],
            'sent_to' => $shipped['sentTo'],
            'subject' => 'My own approval subject',
            'body' => $shipped['body'],
            'description' => (string) $shipped['description'],
            'status' => $shipped['status'],
        ]);

        $row = $I->grabEntityFromRepository(EmailTemplate::class, ['code' => 'order_approved']);
        $I->assertSame('My own approval subject', $row->getSubject());
        $I->assertNull($row->getBody(), 'the body was not changed, so it should not be stored');
        $I->assertNull($row->getModule(), 'the module was not changed, so it should not be stored');
    }

    /** A row that changes nothing is not a row. Saving the shipped values back leaves the table empty. */
    public function savingTheShippedValuesUnchangedStoresNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $shipped = ShippedEmailTemplates::get('order_cancelled');

        $I->amOnPage('/admin/email-template/order_cancelled/update');
        $I->sendAjaxPostRequest('/admin/email-template/order_cancelled/update', [
            '_token' => $I->csrfToken(),
            'module' => $shipped['module'],
            'sent_to' => $shipped['sentTo'],
            'subject' => $shipped['subject'],
            'body' => $shipped['body'],
            'description' => (string) $shipped['description'],
            'status' => $shipped['status'],
        ]);

        // The save has to have been ACCEPTED for a count of zero to mean anything (#594). The table
        // starts empty for this code — that is the test's own premise — so a 404, a CSRF refusal or
        // an exception on the update route leaves it at zero too, and the "store only a real diff"
        // branch under test never ran at all.
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame(
            0,
            $I->grabNumRecords(EmailTemplate::class, ['code' => 'order_cancelled']),
            'nothing differs from what ships, so there is nothing to store',
        );
    }

    /** A customized template reads Modified and offers the revert. */
    public function aCustomizedTemplateReadsModified(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new EmailTemplate())->setCode('order_approved')->setSubject('Changed'));

        $I->amOnPage('/admin/email-template');
        $I->seeResponseCodeIsSuccessful();

        $I->see('Modified');
        $I->seeElement('button[formaction$="/order_approved/revert"]');
    }

    /** Revert deletes the row, so the template goes back to inheriting. */
    public function revertingDeletesTheRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new EmailTemplate())->setCode('order_approved')->setSubject('Changed'));

        $I->amOnPage('/admin/email-template');
        $I->sendAjaxPostRequest('/admin/email-template/order_approved/revert', ['_token' => $I->csrfToken()]);

        $I->assertSame(0, $I->grabNumRecords(EmailTemplate::class, ['code' => 'order_approved']));
    }

    /** Reverting is only for templates that ship — there is nothing to revert an admin's own to. */
    public function revertingAnAdminAuthoredTemplateIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new EmailTemplate())->setCode('my_own_one')->setModule('Mine')->setSubject('S')->setBody('B'));

        $I->amOnPage('/admin/email-template');
        $I->sendAjaxPostRequest('/admin/email-template/my_own_one/revert', ['_token' => $I->csrfToken()]);

        $I->assertSame(1, $I->grabNumRecords(EmailTemplate::class, ['code' => 'my_own_one']), 'an admin-authored template must not be deleted by revert');
    }

    /** Admin-authored templates still list, after the catalogue, and read as Custom. */
    public function adminAuthoredTemplatesListAsCustom(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $I->haveInRepository((new EmailTemplate())->setCode('my_own_one')->setModule('My Very Own Template')->setSubject('S')->setBody('B'));

        $I->amOnPage('/admin/email-template');
        $I->seeResponseCodeIsSuccessful();

        $I->see('My Very Own Template');
        $I->see('Custom');
    }
}
