<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorAddress;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorContact;
use ProcurementBundle\Entity\VendorNote;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The rest of item 43: the balance the AP drill-down comes looking for, ONE notes list, and help
 * text a person can read.
 *
 * Conducted per #624 — the two writes below are plain browser form POSTs carrying a CSRF token
 * scraped off the page that rendered the form, and every claim about what was stored is re-read
 * from the table BY COLUMN through a raw connection afterwards, never from an entity fetched
 * beforehand. Each write also asserts a second vendor's row that must NOT have changed.
 *
 * ## The balance is not a new definition of what is owed
 *
 * `ApAgingReport` already answers "what do we owe this vendor", and AP Aging is the screen that
 * links here. So the page calls the report, and the test below reads BOTH screens and compares them
 * in cents. A vendor page that computed its own total would pass every assertion about itself and
 * still disagree with the row the admin clicked to get here; comparing the two is the only assertion
 * that can see that.
 *
 * Item 40 is deliberately respected rather than anticipated: a void bill's BALANCE is its full
 * amount and stays so on the Bills tab, because balance is amount minus payments and that is
 * correct. What a void bill is not is payable, and the Owed panel is where that shows.
 */
final class AdminVendorBalanceAndNotesCest
{
    private const VENDORS = '/admin/bundles/procurement/vendors';
    private const AGING = '/admin/bundles/procurement/ap-aging';

    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('vendor-balance-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function em(FunctionalTester $I): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $I->grabService('doctrine.orm.entity_manager');

        return $em;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $this->em($I)->getConnection();
    }

    private function makeVendor(FunctionalTester $I, string $name): Vendor
    {
        $em = $this->em($I);
        $vendor = (new Vendor())->setName($name)->setCurrency('CAD')->setStatus(Vendor::STATUS_ACTIVE);
        $em->persist($vendor);
        $em->flush();

        return $vendor;
    }

    private function liveVendor(FunctionalTester $I, Vendor $vendor): Vendor
    {
        $live = $this->em($I)->find(Vendor::class, (int) $vendor->getId());
        $I->assertInstanceOf(Vendor::class, $live);

        return $live;
    }

    /** A bill totalling $210, approved unless asked otherwise. */
    private function makeBill(FunctionalTester $I, Vendor $vendor, string $number, string $state = 'open'): VendorBill
    {
        $em = $this->em($I);
        $bill = (new VendorBill())
            ->setBillNumber($number)
            ->setVendor($this->liveVendor($I, $vendor))
            ->setVendorName($vendor->getName())
            ->setDocumentDate('2026-08-20')
            ->setDueDate('2026-09-20')
            ->setCurrency('CAD');
        $em->persist($bill);

        $line = (new VendorBillLine())
            ->setName('Balance Widget')
            ->setSku('VB-LINE')
            ->setQuantity('4.00')
            ->setUnitCost('50.0000')
            ->setSubtotal('200.00')
            ->setSortOrder(0);
        $bill->addLine($line);
        $em->persist($line);
        $bill->setTax('10.00');
        $bill->recalculateTotals();
        $em->flush();

        if ($state === 'open') {
            $bill->approve(DocumentActor::system());
            $em->flush();
        }
        if ($state === 'void') {
            $bill->approve(DocumentActor::system());
            $bill->setStatus('Void', DocumentActor::system(), 'Bill voided: Duplicate');
            $em->flush();
        }

        return $bill;
    }

    private function openVendor(FunctionalTester $I, Vendor $vendor, ?string $docs = null): void
    {
        $I->amOnPage(self::VENDORS . '/' . $vendor->getId() . ($docs === null ? '' : '?docs=' . $docs));
        $I->seeResponseCodeIsSuccessful();
    }

    /** The Owed panel's figures, as rendered. @return list<string> */
    private function owedFigures(FunctionalTester $I): array
    {
        return array_values(array_map(trim(...), $I->grabMultiple('#vendor-balance .lead strong')));
    }

    /** Money text to whole cents, so two screens can be compared without agreeing on separators. */
    private static function cents(string $money): int
    {
        return (int) round((float) str_replace([',', ' '], '', preg_replace('/[A-Z]{3}/', '', $money) ?? '0') * 100);
    }

    // ================================================================================= balance

    /**
     * The number on the vendor page is the number AP Aging showed, because it is the same number.
     *
     * Compared in cents across the two screens rather than asserted twice against a literal: a
     * literal in this file would go on passing on both screens while they drifted apart from each
     * other, which is the only failure that matters here.
     */
    public function theOwedFigureIsTheOneApAgingPrintsForTheSameVendor(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Balance Ours Supply');
        $theirs = $this->makeVendor($I, 'Balance Theirs Supply');
        $this->makeBill($I, $ours, 'BILL-BAL-OURS');
        $this->makeBill($I, $theirs, 'BILL-BAL-THEIRS');

        $this->openVendor($I, $ours);
        $onRecord = $this->owedFigures($I);
        $I->assertCount(1, $onRecord, 'one currency, one figure');

        $I->amOnPage(self::AGING . '?filters[vendor]=' . $ours->getId());
        $I->seeResponseCodeIsSuccessful();
        $onReport = array_values(array_map(
            trim(...),
            $I->grabMultiple('tr[data-vendor="' . $ours->getId() . '"] td[data-label="Total owed"]'),
        ));
        $I->assertCount(1, $onReport, 'the vendor has one aging row to compare against');

        $I->assertSame(
            self::cents($onReport[0]),
            self::cents($onRecord[0]),
            'The vendor record and AP Aging must print the same figure — they read the same report.',
        );
        // And it is the bill's own total, so neither screen is agreeing on a wrong number.
        $I->assertSame(21000, self::cents($onRecord[0]));

        // The row that must not change: the other vendor's balance is their own.
        $this->openVendor($I, $theirs);
        $I->assertSame([$onRecord[0]], $this->owedFigures($I), 'their figure is theirs, not ours doubled');
    }

    /**
     * Money is two decimals with its currency, on both screens.
     *
     * Asserted as a shape rather than as a string, because the two screens group thousands
     * differently today and the rule is the decimals and the code, not the separator.
     */
    public function theOwedFigureCarriesItsCurrencyAndTwoDecimals(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Balance Format Supply');
        $this->makeBill($I, $ours, 'BILL-FORMAT');

        $this->openVendor($I, $ours);
        $figures = $this->owedFigures($I);
        $I->assertCount(1, $figures);
        $I->assertMatchesRegularExpression('/^CAD [\d,]+\.\d{2}$/', $figures[0]);
    }

    /**
     * A void bill is not owed — and its balance is still its full amount, which is correct.
     *
     * Both halves in one case, because item 40 is precisely the distinction between them: the sweep
     * filed the full balance on a voided bill as a money defect and the owner corrected it twice.
     * Balance due is amount minus payments, full stop. What is payable is a different question, and
     * the Owed panel is the only place on this page that answers it.
     */
    public function aVoidBillIsNotOwedButKeepsItsBalanceOnTheBillsTab(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Balance Void Supply');
        $this->makeBill($I, $ours, 'BILL-VOIDED', 'void');

        $this->openVendor($I, $ours, 'bills');

        // The document's own arithmetic, untouched.
        $I->assertSame(['CAD 210.00'], array_values(array_map(
            trim(...),
            $I->grabMultiple('#vendor-documents td[data-label="Balance due"]'),
        )));
        $I->assertSame(['Void'], array_values(array_map(
            trim(...),
            $I->grabMultiple('#vendor-documents td[data-label="Status"]'),
        )));

        // And nothing is owed. Stated in words rather than as an absent element, so an empty panel
        // and a panel that failed to render do not read alike.
        $I->assertSame([], $this->owedFigures($I));
        $I->see('Nothing outstanding', '#vendor-balance');

        // Positive control on the same panel: an OPEN bill for the same vendor does produce a
        // figure, so the emptiness above is the void bill's doing and not the panel's.
        $this->makeBill($I, $ours, 'BILL-LIVE');
        $this->openVendor($I, $ours);
        $I->assertSame(['CAD 210.00'], $this->owedFigures($I));
    }

    /** A draft bill has authorised nothing, so it is not owed either. */
    public function aDraftBillIsNotOwed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Balance Draft Supply');
        $this->makeBill($I, $ours, 'BILL-DRAFT', 'draft');

        $this->openVendor($I, $ours);
        $I->assertSame([], $this->owedFigures($I));

        // Positive control, as above.
        $this->makeBill($I, $ours, 'BILL-DRAFT-CONTROL');
        $this->openVendor($I, $ours);
        $I->assertSame(['CAD 210.00'], $this->owedFigures($I));
    }

    // =================================================================================== notes

    /**
     * One notes list, like the customer record — the legacy box is off both vendor screens.
     *
     * Every absence here is paired with the positive control that makes it mean something: the
     * threaded note box IS on the detail page and the other vendor fields ARE on the create page, so
     * a mistyped selector cannot pass this by matching nothing twice.
     */
    public function neitherVendorScreenCarriesTheLegacyNotesBoxAnyMore(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Notes One List Supply');

        $this->openVendor($I, $ours);
        $I->dontSeeElement('#vendor-form textarea[name="notes"]');
        $I->dontSee('Notes (legacy field)');
        // Positive control: the threaded list's own box is there, on the same page, read by the
        // same reader. It is a plain text input, matching the customer record's own quick-add
        // note control (templates/admin/company/detail.html.twig's `.notes-form`).
        $I->seeElement('#vendor-notes input[name="note"]');

        $I->amOnPage(self::VENDORS . '/new');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('form textarea[name="notes"]');
        // Positive control: the create form is genuinely rendered and genuinely being read.
        $I->seeElement('form input[name="name"][required]');
        $I->seeElement('form input[name="account_number"]');
    }

    /**
     * The column is gone from the table, not merely off the screen.
     *
     * Read from `PRAGMA table_info` rather than from the mappings: the mappings no longer declare it
     * either way, so asking them would be asking the code under test whether it changed. And the
     * threaded table is asserted in the same breath as the positive control — `vendor_note` still
     * has `text`, so a reader that returned nothing for every table would fail here.
     */
    public function theVendorTableNoLongerHasANotesColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $vendorColumns = array_column($this->connection($I)->fetchAllAssociative('PRAGMA table_info(vendor)'), 'name');
        $noteColumns = array_column($this->connection($I)->fetchAllAssociative('PRAGMA table_info(vendor_note)'), 'name');

        $I->assertNotContains('notes', $vendorColumns, 'vendor.notes was migrated into vendor_note and dropped');
        $I->assertContains('name', $vendorColumns, 'positive control: the reader does find vendor columns');
        $I->assertContains('text', $noteColumns, 'the threaded list is where a note lives now');
        $I->assertContains('user_name', $noteColumns);
        $I->assertContains('created_at', $noteColumns);
    }

    /**
     * Saving a vendor stores what the form declares and cannot resurrect the dropped column.
     *
     * Conducted: a plain form POST with the token scraped off the page, then the `vendor` row read
     * back by column. The posted `notes` is what a stale bookmarked form or a scripted client would
     * send, and the assertion is that it changes nothing — with the fields that SHOULD have changed
     * asserted beside it, so a save that silently did nothing at all cannot pass.
     */
    public function savingAVendorWritesItsFieldsAndIgnoresAPostedLegacyNotesValue(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Notes Save Supply');
        $bystander = $this->makeVendor($I, 'Notes Bystander Supply');
        $ourId = (int) $ours->getId();
        $bystanderId = (int) $bystander->getId();

        // Editing is its own page now (the customer record's own separate Edit Profile shape),
        // not an inline form on the detail page — the token comes from there.
        $I->amOnPage(self::VENDORS . '/' . $ourId . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form input[name="_token"]', 'value');

        $I->sendFormPostRequest(self::VENDORS . '/save', [
            '_token' => $token,
            'id' => (string) $ourId,
            'name' => 'Notes Save Supply Renamed',
            'account_number' => 'ACC-SAVE-1',
            'email' => 'ap@notes-save.example',
            'phone' => '604-555-0123',
            'currency' => 'USD',
            'status' => Vendor::STATUS_INACTIVE,
            // What a stale form would still be sending. There is nowhere for it to land.
            'notes' => 'This must not be stored anywhere.',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $row = $this->connection($I)->fetchAssociative('SELECT * FROM vendor WHERE id = ?', [$ourId]);
        $I->assertIsArray($row);
        $I->assertSame('Notes Save Supply Renamed', $row['name']);
        $I->assertSame('ACC-SAVE-1', $row['account_number']);
        $I->assertSame('USD', $row['currency']);
        $I->assertSame(Vendor::STATUS_INACTIVE, $row['status']);
        $I->assertArrayNotHasKey('notes', $row, 'the dropped column cannot come back through a posted field');

        // The posted text reached no note either — the threaded list is written by its own action.
        $notes = $this->connection($I)->fetchFirstColumn('SELECT text FROM vendor_note WHERE vendor_id = ?', [$ourId]);
        $I->assertSame([], $notes);

        // The row that must not have changed.
        $other = $this->connection($I)->fetchAssociative('SELECT * FROM vendor WHERE id = ?', [$bystanderId]);
        $I->assertIsArray($other);
        $I->assertSame('Notes Bystander Supply', $other['name']);
        $I->assertSame(Vendor::STATUS_ACTIVE, $other['status']);
        $I->assertSame('CAD', $other['currency']);
    }

    /**
     * Adding a note writes a row with its author and its timestamp — the customer side's shape.
     *
     * Conducted through the real form, read back by column, and the second vendor's note list
     * asserted empty afterwards with its own note as the positive control.
     */
    public function addingANoteWritesAThreadedRowAgainstThatVendorOnly(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $ours = $this->makeVendor($I, 'Notes Add Supply');
        $bystander = $this->makeVendor($I, 'Notes Add Bystander Supply');
        $ourId = (int) $ours->getId();
        $bystanderId = (int) $bystander->getId();

        $this->openVendor($I, $ours);
        $token = (string) $I->grabAttributeFrom('#vendor-notes form[action$="/notes"] input[name="_token"]', 'value');

        $I->sendFormPostRequest(self::VENDORS . '/' . $ourId . '/notes', [
            '_token' => $token,
            'note' => "Rep is Dana. Won't ship Fridays.",
        ]);
        $I->seeResponseCodeIsSuccessful();

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT text, user_name, created_at FROM vendor_note WHERE vendor_id = ?',
            [$ourId],
        );
        $I->assertCount(1, $rows);
        $I->assertSame("Rep is Dana. Won't ship Fridays.", $rows[0]['text']);
        $I->assertNotSame('', trim((string) $rows[0]['user_name']), 'a note records who wrote it');
        $I->assertNotSame('', trim((string) $rows[0]['created_at']), 'and when');

        // The row that must not have changed: the bystander has no note.
        $I->assertSame(
            [],
            $this->connection($I)->fetchFirstColumn('SELECT text FROM vendor_note WHERE vendor_id = ?', [$bystanderId]),
        );

        // And it reaches the screen, in its own entry (the customer record's own note-entry shape).
        $this->openVendor($I, $ours);
        $onScreen = array_values(array_map(
            trim(...),
            $I->grabMultiple('#vendor-notes .note-entry-text'),
        ));
        $I->assertSame(["Rep is Dana. Won't ship Fridays."], $onScreen);
    }

    // ============================================================ help text a person can read

    /**
     * Not one database table or column name reaches the reader — and the explanation is still there.
     *
     * The names are ENUMERATED from Doctrine's own mappings rather than typed into this file, so a
     * column added to any of the seven tables the vendor screens talk about is covered on arrival.
     * Only names carrying an underscore are checked: those are unmistakably database spellings,
     * while `name`, `email` and `status` are ordinary English that belongs on a screen.
     *
     * The sentinel is the positive control, and it is also the actual requirement. The ruling was
     * to rewrite the sentence and NOT to delete the explanation — so if the text were simply
     * removed, this test would fail on the sentinel rather than pass on the absence.
     *
     * The detail page's own hard-delete explanation ("A vendor that has any purchase order... /
     * its addresses, contacts and notes") is not one of the sentinels any more: the detail page's
     * inline Delete panel was retired when the page was rebuilt to the customer record's own
     * detail-page shape, which carries no delete UI of its own at all (a customer is only ever
     * deactivated from that screen). The vendor hard-delete ROUTE is unchanged and still refuses
     * correctly — see AdminNoJsVendorMasterDataCest and BundleRowActionsCest — only its explanatory
     * copy on this particular page is gone along with the panel it was attached to.
     */
    public function theVendorScreenSpellsNoDatabaseNameAndStillExplainsItself(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Plain English Supply');

        $this->openVendor($I, $vendor);
        $text = $this->visibleText($I);

        $I->assertNotSame('', $text, 'positive control: the page text was read at all');
        $I->assertStringContainsString(
            'Only admin can see these. The vendor cannot see these notes.',
            $text,
            'The explanation must be rewritten, never deleted.',
        );

        foreach (self::databaseNames($I) as $name) {
            $I->assertStringNotContainsString(
                $name,
                $text,
                sprintf('"%s" is a database name and must not be shown to a person.', $name),
            );
        }

        // The address block's explanation now lives on its own page (the address form is a
        // separate page, matching admin/company/address_form.html.twig's own shape). It used to
        // tell the reader to type short codes rather than full names; the province and country are
        // PICKED from a country-scoped list, so there is nothing to type and the sentence explains
        // the scoping instead. Rewritten, which is what the ruling asked for — still there, which
        // is what this asserts.
        $I->amOnPage(self::VENDORS . '/' . $vendor->getId() . '/address');
        $I->assertStringContainsString(
            'The province list is the one for the country above it',
            $this->visibleText($I),
            'The explanation must be rewritten, never deleted.',
        );
    }

    /**
     * The payment-term hint, which no functional test on this suite can reach.
     *
     * It renders only when the raw-SQL `payment_term` table exists AND holds an Active row AND this
     * vendor's term was typed as free text with no id beside it. That table has no entity, so the
     * Codeception schema — built from Doctrine metadata — does not have it at all, and the screen
     * takes its free-text fallback every time. A rendered assertion would therefore pass forever
     * without ever reading the branch.
     *
     * This is also the branch the brief got wrong. It said `payment_term_id` appears only inside a
     * Twig `{# #}` comment and never reaches the browser. There IS such a comment — and there was
     * also a rendered `<code>vendor.payment_term_id</code>` in the hint below it, on an instance
     * that has payment terms configured, which every production-shaped instance does.
     *
     * So this reads the templates. A `<code>` element is the exact shape all of these took, and
     * there is no legitimate use for one on a vendor screen: it is a tag for showing code, and the
     * ruling is that this page shows none.
     */
    public function noVendorTemplateShowsCodeToTheReaderInAnyBranch(FunctionalTester $I): void
    {
        $templates = [
            'admin/company/detail.html.twig',
            'vendor_address_form.html.twig',
            'vendor_contact_form.html.twig',
            '_vendor_fields.html.twig',
            'vendor_form.html.twig',
        ];

        $seen = 0;
        foreach ($templates as $file) {
            $path = \dirname(__DIR__, 2) . '/modules/ProcurementBundle/templates/' . $file;
            $source = (string) file_get_contents($path);
            $I->assertNotSame('', $source, 'positive control: ' . $file . ' was read');
            ++$seen;

            $I->assertStringNotContainsString(
                '<code>',
                $source,
                $file . ' still shows a database name to the reader, in a branch or otherwise.',
            );
        }
        $I->assertSame(\count($templates), $seen);

        // And the explanation the <code> used to sit inside is still there, rewritten — the ruling
        // was to rewrite the sentence and NOT to delete it.
        $fields = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/modules/ProcurementBundle/templates/_vendor_fields.html.twig',
        );
        $I->assertStringContainsString('matched to a term on the list above', $fields);
    }

    /**
     * Every table and column name the vendor screens could leak, from the mappings themselves.
     *
     * @return list<string>
     */
    private static function databaseNames(FunctionalTester $I): array
    {
        $names = [];
        foreach ([
            Vendor::class,
            VendorAddress::class,
            VendorContact::class,
            VendorNote::class,
            PurchaseOrder::class,
            VendorBill::class,
            GoodsReceipt::class,
        ] as $class) {
            /** @var ClassMetadata<object> $meta */
            $meta = $I->grabService('doctrine.orm.entity_manager')->getClassMetadata($class);
            $names[] = $meta->getTableName();
            foreach ($meta->getFieldNames() as $field) {
                $names[] = $meta->getColumnName($field);
            }
            foreach ($meta->getAssociationNames() as $association) {
                foreach ($meta->getAssociationMapping($association)['joinColumns'] ?? [] as $joinColumn) {
                    $names[] = $joinColumn['name'];
                }
            }
        }

        // Only the unmistakable ones. `name`, `email`, `phone` and `status` are English words a
        // screen is entitled to print; `payment_term_id` and `goods_receipt` are not.
        return array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => str_contains($name, '_'),
        )));
    }

    /** The page as a person reads it: no markup, no scripts, whitespace collapsed. */
    private function visibleText(FunctionalTester $I): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $I->grabPageSource());

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }
}
