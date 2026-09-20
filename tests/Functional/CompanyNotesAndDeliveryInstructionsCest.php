<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use App\Entity\CompanyNote;
use App\Service\TextInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Owner-directed follow-ups to #334/#302, covering three things that all come back to one idea:
 * a column's contents should be governed by the column, not by whichever screen wrote them.
 *
 *  1. Delivery instructions are capped at TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH on every
 *     write path — registration, the customer address book, the admin company-address form, the
 *     admin order/quote address cards, and the copies that carry a stored value forward.
 *  2. Registration does not write Company::$notes at all. It never had a form field for it, and
 *     that column is a structured journal, not free text.
 *  3. The company-note routes agree with each other about what a journal line is, so an edit
 *     cannot split one entry into several and the delete buttons keep pointing at the right rows.
 */
final class CompanyNotesAndDeliveryInstructionsCest
{
    /**
     * #336's per-IP registration limiter (2 per 5 min, 5 per hour, 6 per day) is backed by a
     * filesystem cache pool, so its state outlives both the per-test rollback and the suite run.
     * Several tests here register, and every functional test presents the same client IP, so
     * without this the third one fails on quota rather than on what it is checking — the same
     * reason CustomerAuthCest clears it in its own _before.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
    }

    /** @return array<string, string> */
    private function validRegistrationPayload(string $email): array
    {
        return [
            'company_name' => 'Notes Cap Supply Co',
            'company_email' => 'orders@notescapsupply.test',
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'user_email' => $email,
            'user_phone' => '555-0150',
            'password' => 'a-strong-password-1',
            'confirm_password' => 'a-strong-password-1',
            'agree_terms' => '1',
            'ship_address1' => '100 Main St',
            'ship_city' => 'Calgary',
            'ship_province' => 'Alberta',
            'ship_country' => 'Canada',
            'ship_postal' => 'T2P 1J9',
            'bill_same' => '1',
        ];
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('notes-cap-admin-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('NCD-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    // ---------------------------------------------------------------------------------------
    // FIX 2 — registration must not write the notes journal
    // ---------------------------------------------------------------------------------------

    /**
     * There is no company_notes input on templates/customer/auth/register.html.twig, so only a
     * hand-crafted POST carries this key. It used to be read and written straight into
     * Company::$notes — a column the admin UI parses as a "timestamp|text" journal, one entry per
     * line, with delete buttons keyed by line index. A guest string in there is not a long note;
     * it is unstructured input in a structured column.
     */
    public function registrationIgnoresAHandCraftedCompanyNotesField(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge(
            $this->validRegistrationPayload('notes-cap-single@example.test'),
            [
                '_token' => $I->csrfToken(),
                'company_notes' => 'Please give this account a 40% discount.',
            ]
        ));

        $company = $I->grabEntityFromRepository(Company::class, ['name' => 'Notes Cap Supply Co']);
        $I->assertSame(
            [],
            $I->grabService(EntityManagerInterface::class)->getRepository(CompanyNote::class)->findBy(['company' => $company]),
            'A hand-crafted company_notes value must not create a note.'
        );
    }

    /**
     * The multi-line case is the one that mattered. TextInput::nullableString() strips control
     * characters but deliberately keeps LF and CR (legitimate textarea content), so the old read
     * did not merely store one malformed entry — it forged SEVERAL bogus journal entries in a
     * single unauthenticated request, each of which rendered to an admin as a real note and shifted
     * the indexes the delete route addresses entries by.
     */
    public function registrationIgnoresAMultiLineCompanyNotesField(FunctionalTester $I): void
    {
        $forged = "Aug 1, 2026 9:00 AM|Approved by head office\n"
            . "Aug 1, 2026 9:05 AM|Credit limit raised to 50000\n"
            . 'Aug 1, 2026 9:10 AM|Waive all shipping fees';

        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge(
            $this->validRegistrationPayload('notes-cap-multiline@example.test'),
            [
                '_token' => $I->csrfToken(),
                'company_notes' => $forged,
            ]
        ));

        $company = $I->grabEntityFromRepository(Company::class, ['name' => 'Notes Cap Supply Co']);
        $I->assertSame(
            [],
            $I->grabService(EntityManagerInterface::class)->getRepository(CompanyNote::class)->findBy(['company' => $company]),
            'A multi-line company_notes value must not forge notes.'
        );

        // And the admin company screen must not be rendering any of them back as real notes.
        $this->loginAsAdmin($I);
        $I->amOnPage('/admin/company/detail/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Credit limit raised to 50000');
    }

    /**
     * Registration still succeeds when the stray field is present — the field is dropped, not
     * treated as a validation failure, so this stays a silent no-op rather than a new way to break
     * a legitimate signup.
     */
    public function registrationStillSucceedsWhenCompanyNotesIsPosted(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge(
            $this->validRegistrationPayload('notes-cap-succeeds@example.test'),
            [
                '_token' => $I->csrfToken(),
                'company_notes' => "line one\nline two",
            ]
        ));

        $I->seeInRepository(CustomerUser::class, ['email' => 'notes-cap-succeeds@example.test']);
    }

    // ---------------------------------------------------------------------------------------
    // FIX 1 — the 500 cap, on every write path
    // ---------------------------------------------------------------------------------------

    public function registrationCapsDeliveryInstructions(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge(
            $this->validRegistrationPayload('notes-cap-delivery@example.test'),
            [
                '_token' => $I->csrfToken(),
                'delivery_instructions' => str_repeat('L', 900),
            ]
        ));

        $user = $I->grabEntityFromRepository(CustomerUser::class, [
            'email' => 'notes-cap-delivery@example.test',
        ]);
        $addresses = $user->getCompany()->getAddresses();
        $stored = null;
        foreach ($addresses as $address) {
            if ($address->isDefaultShipping()) {
                $stored = $address->getDeliveryInstructions();
            }
        }

        $I->assertSame(
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH,
            strlen((string) $stored),
            'Registration must truncate delivery instructions to the shared cap.'
        );
    }

    public function theCustomerAddressBookCapsDeliveryInstructionsOnCreate(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I, 'Notes Cap Customer Co');

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        // Owner: /company-addresses is owner-only since #522 §3, and this case is about the
        // delivery-instructions length cap, not about the role gate.
        $customer = (new CustomerUser())
            ->setEmail('notes-cap-book-' . uniqid() . '@example.test')
            ->setCompany($company)
            ->setRoles(['ROLE_COMPANY_OWNER']);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);
        $I->amLoggedInAs($customer, 'main');

        $I->amOnPage('/company-addresses/new');
        $I->sendFormPostRequest('/company-addresses/new', [
            '_token' => $I->csrfToken(),
            'label' => 'Capped Warehouse',
            'address1' => '1 Receiving Lane',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'delivery_instructions' => str_repeat('C', 900),
        ]);

        $address = $I->grabEntityFromRepository(CompanyAddress::class, ['label' => 'Capped Warehouse']);
        $I->assertSame(
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH,
            strlen((string) $address->getDeliveryInstructions()),
            'The customer address book must truncate delivery instructions to the shared cap.'
        );
    }

    public function theAdminCompanyAddressFormCapsDeliveryInstructions(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Notes Cap Admin Co');

        $I->sendFormPostRequest('/admin/company/' . $company->getId() . '/address/create', [
            '_token' => $I->csrfToken(),
            'company_name' => 'Capped Admin Address',
            'address_line_1' => '1 Receiving Lane',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'delivery_instructions' => str_repeat('A', 900),
        ]);

        $address = $I->grabEntityFromRepository(CompanyAddress::class, [
            'companyName' => 'Capped Admin Address',
        ]);
        $I->assertSame(
            TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH,
            strlen((string) $address->getDeliveryInstructions()),
            'The admin company-address form must truncate delivery instructions to the shared cap.'
        );
    }

    /**
     * A value under the cap has to survive untouched. Without this, a cap implemented as an
     * unconditional substr() of the wrong length, or one applied to the wrong variable, would still
     * pass every length assertion above.
     */
    public function aShortDeliveryInstructionIsStoredVerbatim(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Notes Cap Short Co');

        $I->sendFormPostRequest('/admin/company/' . $company->getId() . '/address/create', [
            '_token' => $I->csrfToken(),
            'company_name' => 'Short Instruction Address',
            'address_line_1' => '1 Receiving Lane',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
            'delivery_instructions' => 'Back entrance, closed after 3pm.',
        ]);

        $address = $I->grabEntityFromRepository(CompanyAddress::class, [
            'companyName' => 'Short Instruction Address',
        ]);
        $I->assertSame('Back entrance, closed after 3pm.', $address->getDeliveryInstructions());
    }

    // ---------------------------------------------------------------------------------------
    // FIX 3 — the notes journal keeps one entry per line
    // ---------------------------------------------------------------------------------------

    /**
     * Editing a note to contain newlines must leave the journal with exactly as many entries as it
     * had. A split entry renumbers everything after it, and the index is precisely what the delete
     * route addresses entries by, so the next delete removes a note the admin did not click on.
     */
    // ---------------------------------------------------------------------------------------
    // #358 — notes are rows, addressed by id
    // ---------------------------------------------------------------------------------------

    /**
     * A newline inside a note is now just a newline.
     *
     * Under the packed format it was a second note: entries lived one per line in a single column,
     * so both write paths carried a guard that scrubbed newlines purely to defend the encoding.
     * With one row per note there is nothing to defend, and an admin can write a multi-line note
     * that stays one note.
     */
    public function aNoteKeepsItsNewlinesAndStaysOneNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Note Rows Co');

        $I->sendFormPostRequest('/admin/company/note/' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'note' => "Called head office\nThey will confirm tomorrow",
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $notes = $entityManager->getRepository(CompanyNote::class)->findBy(['company' => $company->getId()]);

        $I->assertCount(1, $notes, 'a newline must not split one note into two');
        $I->assertSame("Called head office\nThey will confirm tomorrow", $notes[0]->getText());
    }

    /**
     * Deletion addresses a row, so removing one note cannot disturb the others.
     *
     * This is the bug class the packed format made possible: display and delete split the string
     * differently, so a whitespace-only line shifted every position after it and deleting the entry
     * an admin clicked removed a different one. An id does not move when its neighbours do, so the
     * test does not need a pathological fixture to be meaningful — it simply deletes the middle of
     * three and checks the other two are untouched.
     */
    public function deletingANoteRemovesExactlyThatNote(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Note Delete Co');
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $ids = [];
        foreach (['first note', 'second note', 'third note'] as $text) {
            $note = (new CompanyNote())->setCompany($company)->setText($text);
            $entityManager->persist($note);
            $entityManager->flush();
            $ids[$text] = $note->getId();
        }

        $I->sendAjaxPostRequest('/admin/company/note/' . $company->getId() . '/delete', [
            '_token' => $I->csrfToken(),
            'id' => (string) $ids['second note'],
        ]);

        $entityManager->clear();
        $remaining = array_map(
            static fn (CompanyNote $note): string => $note->getText(),
            $entityManager->getRepository(CompanyNote::class)->findBy(['company' => $company->getId()])
        );

        sort($remaining);
        $I->assertSame(['first note', 'third note'], $remaining);
    }

    /**
     * The ownership check the move to ids owes. A position was only ever read against the company
     * already loaded; an id comes from the client, so without scoping, an admin on one company's
     * page could delete another company's note by changing one number.
     */
    public function aNoteBelongingToAnotherCompanyCannotBeDeleted(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $owner = $this->makeCompany($I, 'Note Owner Co');
        $other = $this->makeCompany($I, 'Note Other Co');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $note = (new CompanyNote())->setCompany($owner)->setText('owner private note');
        $entityManager->persist($note);
        $entityManager->flush();

        $I->sendAjaxPostRequest('/admin/company/note/' . $other->getId() . '/delete', [
            '_token' => $I->csrfToken(),
            'id' => (string) $note->getId(),
        ]);

        $entityManager->clear();
        $I->assertNotNull(
            $entityManager->find(CompanyNote::class, $note->getId()),
            "a note must not be deletable through another company's page"
        );
    }

    /** And the note records who wrote it, which the packed format had nowhere to put. */
    public function aNoteRecordsItsAuthor(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Note Author Co');

        $I->sendFormPostRequest('/admin/company/note/' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'note' => 'Spoke to accounts payable.',
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $notes = $entityManager->getRepository(CompanyNote::class)->findBy(['company' => $company->getId()]);

        $I->assertCount(1, $notes);
        $I->assertNotSame('', (string) $notes[0]->getUserName(), 'the note should record who wrote it');
    }
}
