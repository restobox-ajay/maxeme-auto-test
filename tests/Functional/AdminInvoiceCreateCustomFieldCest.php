<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomFieldDefinition;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Repository\CustomFieldValueRepository;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Admin-defined custom fields on the invoice CREATE screen — the form half of #624's sell-side
 * custom fields, the half b13bb337 deliberately left off while the two create screens were being
 * merged into one (862fdedc).
 *
 * Storage already shipped and AdminInvoiceEstimateCustomFieldCest proves it: the object type is
 * offered, a value persists into `custom_field_value_invoice` and does not leak sideways. What that
 * file could not cover is the SCREEN, because there was not one yet. This is that file.
 *
 * ## Conducted, not asserted at (#624)
 *
 * Every definition here is made through the real /admin/custom-fields screens and every invoice is
 * raised through a real invoice create screen, with plain form POSTs carrying a CSRF token scraped
 * from the rendered page. Nothing calls CustomFieldRenderer, and nothing writes a value row through
 * the repository — that would prove the storage this Cest is not about.
 *
 * What is asserted is the COLUMN: `custom_field_value_invoice.value`, read back by SQL through a
 * second connection read. An entity the request left in memory proves what the request computed and
 * not what was stored.
 *
 * Both save paths are covered, because they are two different operations rather than two spellings
 * of one: `InvoiceController::create()` mints an invoice out of typed lines, and
 * `InvoiceController::createFromOrder()` hands the work to OrderInvoicingService and draws down a
 * sales order. A custom field wired into one of them and not the other is silent everywhere else.
 *
 * ## The regression this file exists for
 *
 * `renderStandaloneForm()` is the only re-render funnel in this whole feature that can LOSE what an
 * admin typed. Every other screen answers a refusal with a redirect, and a redirect re-reads the
 * stored values — but this is an ADD screen, so there is nothing stored to fall back on. Without the
 * Request being handed to CustomFieldRenderer::renderFields(), a refusal over an unrelated field
 * repaints every custom field box empty and everything typed into them is gone, with no error
 * naming them. `aRefusedSaveHandsBackEveryValueTheAdminHadTyped()` is that proof, on two different
 * refusal branches and on all five field types the renderer knows — because a plain text box and a
 * ticked checkbox do not round-trip the same way at all.
 *
 * ## #627
 *
 * Not one value here is asserted with a bare see(). Every one is read off the element that holds it
 * — an input by its posted name, a textarea's own text, a select's selected option — or out of the
 * database column it landed in. Every absence assertion is paired with a positive control on the
 * SAME element: the unticked checkbox is asserted present AND unchecked, and the unselected option
 * is asserted present AND not selected, so a selector that has quietly stopped matching fails here
 * rather than passing as an absence.
 *
 * @group bundle-agnostic
 */
final class AdminInvoiceCreateCustomFieldCest
{
    private const STANDALONE_SCREEN = '/admin/invoice/create?company_id=%d';
    private const STANDALONE_SAVE = '/admin/invoice/create';
    private const ORDER_SCREEN = '/admin/invoice/create?order_id=%d';

    /** The five field types the renderer knows, as this file's own definitions. */
    private const FIELD_SET = [
        'cf_inv_claim' => [CustomFieldDefinition::FIELD_TYPE_TEXT, 'Freight Claim Reference', ''],
        'cf_inv_notes' => [CustomFieldDefinition::FIELD_TYPE_TEXTAREA, 'Billing Notes', ''],
        'cf_inv_pallets' => [CustomFieldDefinition::FIELD_TYPE_NUMBER, 'Pallet Count', ''],
        'cf_inv_rush' => [CustomFieldDefinition::FIELD_TYPE_CHECKBOX, 'Rush Billing', ''],
        'cf_inv_hold' => [CustomFieldDefinition::FIELD_TYPE_CHECKBOX, 'Hold For Pickup', ''],
        'cf_inv_grade' => [CustomFieldDefinition::FIELD_TYPE_SELECT, 'Service Grade', "Standard\nExpedited\nWhite Glove"],
    ];

    private ?Company $company = null;
    private ?ProductCore $product = null;
    private int $seq = 0;

    /**
     * Codeception builds ONE instance of a Cest and runs every method on it, so anything left on
     * $this by the previous method is still there. Both fixtures are re-made per test; the counter
     * that keeps emails and document numbers unique is the only thing carried over on purpose.
     */
    public function _before(FunctionalTester $I): void
    {
        $this->company = null;
        $this->product = null;
        ++$this->seq;

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('inv-create-cf-' . $this->seq . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // The address is not decoration: an invoice billing taxable goods with no known tax province
        // is REFUSED before it reaches the custom field save, so without one every save test here
        // would be asserting against the refusal branch instead of the save.
        $company = (new Company())
            ->setName('Invoice Custom Field Co ' . $this->seq)
            ->setCode('ICF-' . $this->seq . '-' . uniqid());
        $I->haveInRepository($company);
        $company->addAddress(
            (new CompanyAddress())
                ->setLabel('HQ')
                ->setFirstName('Ivy')
                ->setLastName('Cartwright')
                ->setAddressLine1('4 Custom Field Way')
                ->setCity('Toronto')
                ->setProvince('ON')
                ->setCountry('CA')
                ->setPostalCode('M4B1B5')
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true)
        );
        $I->grabService(EntityManagerInterface::class)->flush();
        $this->company = $company;

        $product = (new ProductCore())
            ->setSku('ICF-SKU-' . $this->seq . '-' . uniqid())
            ->setName('Invoice Custom Field Widget')
            ->setSalesTaxCode('G')
            ->setDefaultPrice('5.00')
            ->setOriginalPrice('5.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $this->product = $product;
    }

    // ──────────────────────────────────────────────────────────── 1. the boxes are on the screen

    /**
     * An invoice definition renders on BOTH entry points of the one create screen, and an ORDER
     * definition renders on neither.
     *
     * The sales order field being set changes nothing about these: they are the invoice's fields on
     * both paths, from the same definitions. The absence is the object type scoping and has a
     * positive control of exactly the same shape on the same form — the invoice box, which is there.
     */
    public function theInvoiceFieldsRenderOnBothEntryPointsAndTheOrdersOwnDoNot(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_inv_claim', 'Freight Claim Reference');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ORDER, 'cf_ord_route', 'Routing Guide');
        $order = $this->approvedOrder($I);

        foreach ([
            'the standalone screen' => sprintf(self::STANDALONE_SCREEN, $this->company->getId()),
            'the order-linked screen' => sprintf(self::ORDER_SCREEN, $order->getId()),
        ] as $what => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            // The card, in the form — not merely the words somewhere on a page whose sidebar says
            // "Invoice" on every screen in the application.
            $I->see('Custom Fields', '#invoice-create-form section.order-form-panel h2');
            $I->seeElement('#invoice-create-form input[name="custom_field[cf_inv_claim]"]');
            // The control for the absence below: the same selector shape, on the same form, for the
            // definition that belongs to a different object type.
            $I->dontSeeElement('#invoice-create-form input[name="custom_field[cf_ord_route]"]');
            $I->assertNotEmpty($what, 'named for the failure message');
        }
    }

    /**
     * A screen with no invoice definitions at all renders no "Custom Fields" card.
     *
     * The guard, conducted rather than read: an empty heading on every invoice in an installation
     * that defines none is what the `{% if %}` in the template is for. The positive control is the
     * same page a moment later, with a definition, asserted through the identical selector.
     */
    public function theCardIsAbsentUntilThereIsADefinitionToPutInIt(FunctionalTester $I): void
    {
        $url = sprintf(self::STANDALONE_SCREEN, $this->company->getId());

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        // The control: the form really did render its other panels, so the absence below is the
        // missing definition and not a blank page.
        $I->seeElement('#invoice-create-form input[name="po_number"]');
        $I->dontSee('Custom Fields', '#invoice-create-form section.order-form-panel h2');

        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_inv_claim', 'Freight Claim Reference');

        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Custom Fields', '#invoice-create-form section.order-form-panel h2');
    }

    // ──────────────────────────────────────────────────── 2. save path one: standalone create

    /**
     * Every field type the standalone create screen posts lands in `custom_field_value_invoice`.
     *
     * One POST, one assertion per slug, read back by SQL. A fumbled name leaves its own row absent
     * or null while every other field saves perfectly, which is the failure that is otherwise
     * silent — and the five types do not round-trip alike: a checkbox posts its hidden twin's '0'
     * when it is not ticked, and a select posts one of its own options or nothing at all.
     */
    public function theStandaloneScreenStoresEveryTypedFieldInTheInvoiceColumn(FunctionalTester $I): void
    {
        $this->createFieldSet($I);

        $invoiceId = $this->raiseStandaloneInvoice($I, [
            'cf_inv_claim' => 'CLAIM-77-ALPHA',
            'cf_inv_notes' => 'Bill the freight claim separately.',
            'cf_inv_pallets' => '18.5',
            'cf_inv_rush' => '1',
            'cf_inv_hold' => '0',
            'cf_inv_grade' => 'Expedited',
        ]);

        $I->assertSame(
            [
                'cf_inv_claim' => 'CLAIM-77-ALPHA',
                'cf_inv_grade' => 'Expedited',
                'cf_inv_hold' => '0',
                'cf_inv_notes' => 'Bill the freight claim separately.',
                'cf_inv_pallets' => '18.5',
                'cf_inv_rush' => '1',
            ],
            $this->storedValuesBySlug($I, $invoiceId),
            'the standalone create screen wrote every typed box into custom_field_value_invoice',
        );
    }

    // ────────────────────────────────────────────── 3. save path two: raised against an order

    /**
     * The same boxes, on the other entry point, saved by the other operation.
     *
     * createFromOrder() does not mint the invoice itself — OrderInvoicingService does, and draws the
     * order down — so the custom field save is a different piece of code on a different document,
     * and it is asserted against the same column. The drawn-down line is asserted too: a custom
     * field save that quietly broke the invoicing it rides on would otherwise pass here.
     */
    public function theOrderLinkedScreenStoresEveryTypedFieldInTheInvoiceColumn(FunctionalTester $I): void
    {
        $this->createFieldSet($I);
        $order = $this->approvedOrder($I);

        $invoiceId = $this->raiseInvoiceFromOrder($I, $order, [
            'cf_inv_claim' => 'CLAIM-88-BRAVO',
            'cf_inv_notes' => 'Drawn down from the order.',
            'cf_inv_pallets' => '4',
            'cf_inv_rush' => '0',
            'cf_inv_hold' => '1',
            'cf_inv_grade' => 'White Glove',
        ]);

        $I->assertSame(
            [
                'cf_inv_claim' => 'CLAIM-88-BRAVO',
                'cf_inv_grade' => 'White Glove',
                'cf_inv_hold' => '1',
                'cf_inv_notes' => 'Drawn down from the order.',
                'cf_inv_pallets' => '4',
                'cf_inv_rush' => '0',
            ],
            $this->storedValuesBySlug($I, $invoiceId),
            'the order-linked create screen wrote every typed box into custom_field_value_invoice',
        );

        // The control that this really was the order-linked operation and not a standalone invoice
        // that happened to save: the invoice the custom fields hang off draws down the order.
        $I->assertSame(
            (int) $order->getId(),
            (int) $this->connection($I)->fetchOne('SELECT sales_order_id FROM invoice WHERE id = ?', [$invoiceId]),
            'the invoice carrying those values is the one raised against the order',
        );
    }

    // ────────────────────────────────────────────────────────────── 4. THE REGRESSION

    /**
     * A refused save hands back every value the admin had typed.
     *
     * This is the whole reason the invoice half needed its own wiring rather than a copy of the
     * order form's. `renderStandaloneForm()` answers a refusal by RE-RENDERING itself — six paths
     * through it and not one of them redirects — and this is an add screen, so there is no stored
     * value to repaint from. Hand the renderer no Request and every box comes back empty: the admin
     * is told about the one field that was wrong and silently loses the six that were right.
     *
     * Two refusal branches, deliberately: an unknown charge type is refused at the very top of the
     * save, before a line has been read, and an empty line set is refused most of the way down, with
     * a half-built invoice in memory. Both go through the same funnel, and the funnel is the claim.
     *
     * Five field types, because they come back through five different pieces of HTML — an attribute,
     * an element's text, a bare `checked`, and a `selected` on one option out of several.
     */
    public function aRefusedSaveHandsBackEveryValueTheAdminHadTyped(FunctionalTester $I): void
    {
        $this->createFieldSet($I);

        $typed = [
            'cf_inv_claim' => 'CLAIM-99-CHARLIE',
            'cf_inv_notes' => 'Do not lose this on a refusal.',
            'cf_inv_pallets' => '12.25',
            'cf_inv_rush' => '1',
            'cf_inv_hold' => '0',
            'cf_inv_grade' => 'Expedited',
        ];

        foreach ([
            // Refused at the door: a charge row naming a type this app has no line for.
            'the charge-type refusal' => [
                'lines' => [0 => ['product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '5.00']],
                'charge_lines' => [0 => ['label' => 'Crating', 'amount' => '25.00', 'type' => 'wibble']],
            ],
            // Refused on a different rule entirely, same validator: an After Tax fee that is not
            // also marked Exempt (assertValid()'s own rule, run through the same ValidChargeRows
            // constraint errorFor() uses) — not the old "no lines at all" refusal, which #d74d2864
            // deliberately removed app-wide (zero lines is a valid invoice).
            'the after-tax-taxable refusal' => [
                'lines' => [0 => ['product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '5.00']],
                'charge_lines' => [0 => ['label' => 'Late Fee', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'G', 'placement' => 'after_tax_line']],
            ],
        ] as $what => $extra) {
            $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
            $I->seeResponseCodeIsSuccessful();

            $I->sendFormPostRequest(self::STANDALONE_SAVE, array_merge([
                '_token' => $I->csrfToken(),
                'company_id' => (string) $this->company->getId(),
                'save_mode' => 'issue',
                'invoice_date' => '2026-09-12',
                'custom_field' => $typed,
            ], $extra));

            // The guard on the whole test: this really is a refusal, and it really did come back as
            // the form rather than as a redirect to somewhere with stored values to read.
            $I->seeResponseCodeIs(422);
            $I->seeElement('#invoice-create-form div.form-error-banner');
            $I->assertSame(
                0,
                $this->rowCount($I, 'custom_field_value_invoice'),
                sprintf('%s: a refused save stored nothing, so everything below is the re-render', $what),
            );

            // A plain text box, and a number box, both by the attribute that holds the value.
            $I->assertSame(
                'CLAIM-99-CHARLIE',
                $I->grabAttributeFrom('#invoice-create-form input[name="custom_field[cf_inv_claim]"]', 'value'),
                sprintf('%s: the text box came back holding what was typed', $what),
            );
            $I->assertSame(
                '12.25',
                $I->grabAttributeFrom('#invoice-create-form input[name="custom_field[cf_inv_pallets]"]', 'value'),
                sprintf('%s: and so did the number box', $what),
            );

            // A textarea, which carries its value as its own text and not as an attribute at all.
            $I->assertSame(
                'Do not lose this on a refusal.',
                trim($I->grabTextFrom('#invoice-create-form textarea[name="custom_field[cf_inv_notes]"]')),
                sprintf('%s: the textarea came back holding what was typed', $what),
            );

            // A ticked checkbox, which carries nothing but a bare `checked`, and an unticked one
            // beside it as the control: both boxes are asserted PRESENT, so "not checked" cannot
            // pass by the element having disappeared.
            $I->seeElement('#invoice-create-form input[type="checkbox"][name="custom_field[cf_inv_rush]"][checked]');
            $I->seeElement('#invoice-create-form input[type="checkbox"][name="custom_field[cf_inv_hold]"]');
            $I->dontSeeElement('#invoice-create-form input[type="checkbox"][name="custom_field[cf_inv_hold]"][checked]');

            // A select, where the value is a `selected` on one option out of four. The option that
            // was NOT chosen is asserted present and unselected, on the same select, for the same
            // reason.
            $I->assertSame(
                'Expedited',
                $I->grabAttributeFrom('#invoice-create-form select[name="custom_field[cf_inv_grade]"] option[selected]', 'value'),
                sprintf('%s: the select came back on the option that was chosen', $what),
            );
            $I->seeElement('#invoice-create-form select[name="custom_field[cf_inv_grade]"] option[value="Standard"]');
            $I->dontSeeElement('#invoice-create-form select[name="custom_field[cf_inv_grade]"] option[value="Standard"][selected]');
        }

        // And the point of keeping them: the same boxes, posted again from the page that came back,
        // save. A re-render that held the values but could not be submitted would fail here.
        $invoiceId = $this->raiseStandaloneInvoice($I, $typed);
        $I->assertSame(
            [
                'cf_inv_claim' => 'CLAIM-99-CHARLIE',
                'cf_inv_grade' => 'Expedited',
                'cf_inv_hold' => '0',
                'cf_inv_notes' => 'Do not lose this on a refusal.',
                'cf_inv_pallets' => '12.25',
                'cf_inv_rush' => '1',
            ],
            $this->storedValuesBySlug($I, $invoiceId),
            'the values that survived the refusals are the values that got stored',
        );
    }

    /**
     * A box the admin deliberately EMPTIED comes back empty, rather than repainting the value that
     * was in it a moment ago.
     *
     * The other half of the re-render: "what was typed wins" has to mean the empty string too, which
     * is why the renderer reads the posted bag with array_key_exists and not `??`. The positive
     * control is the box beside it, which was filled in and does come back.
     */
    public function aBoxEmptiedBeforeARefusalComesBackEmpty(FunctionalTester $I): void
    {
        $this->createFieldSet($I);

        $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
        $I->sendFormPostRequest(self::STANDALONE_SAVE, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'save_mode' => 'issue',
            'lines' => [0 => ['product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '5.00']],
            // The refusal trigger: an After Tax fee that is not also Exempt — zero lines is no
            // longer a refusal (#d74d2864), so this is the same still-valid rule the other refusal
            // test in this file now uses.
            'charge_lines' => [0 => ['label' => 'Late Fee', 'amount' => '25.00', 'type' => 'fee', 'taxClass' => 'G', 'placement' => 'after_tax_line']],
            'custom_field' => ['cf_inv_claim' => '', 'cf_inv_notes' => 'Still here.'],
        ]);

        $I->seeResponseCodeIs(422);
        $I->assertSame(
            '',
            $I->grabAttributeFrom('#invoice-create-form input[name="custom_field[cf_inv_claim]"]', 'value'),
            'the box that was cleared came back cleared',
        );
        $I->assertSame(
            'Still here.',
            trim($I->grabTextFrom('#invoice-create-form textarea[name="custom_field[cf_inv_notes]"]')),
            'the control: the box beside it that was filled in came back filled in',
        );
    }

    // ──────────────────────────────────────────────────────── 5. what must NOT have changed

    /**
     * Raising one invoice leaves another invoice's values exactly as they were, row for row.
     *
     * Both invoices carry the same definitions, and the second is raised through the OTHER entry
     * point — so a save that wrote by definition alone, or that reached for "the newest invoice",
     * would be caught whichever screen it was written on.
     *
     * Whole rows are compared and not just values: a value that stayed the same while its
     * definition_id or invoice_id was rewritten underneath it would pass a value-only check.
     */
    public function raisingAnInvoiceLeavesAnotherInvoicesValuesAlone(FunctionalTester $I): void
    {
        $this->createFieldSet($I);

        $bystanderId = $this->raiseStandaloneInvoice($I, [
            'cf_inv_claim' => 'BYSTANDER-CLAIM',
            'cf_inv_grade' => 'Standard',
            'cf_inv_rush' => '1',
        ]);
        $bystanderBefore = $this->valueRowsFor($I, $bystanderId);
        $I->assertNotSame([], $bystanderBefore, 'guard: the bystander really does hold rows to leave alone');

        $order = $this->approvedOrder($I);
        $targetId = $this->raiseInvoiceFromOrder($I, $order, [
            'cf_inv_claim' => 'TARGET-CLAIM',
            'cf_inv_grade' => 'White Glove',
            'cf_inv_rush' => '0',
        ]);

        $I->assertNotSame($bystanderId, $targetId, 'guard: these are two different invoices');
        $I->assertSame(
            ['cf_inv_claim' => 'TARGET-CLAIM', 'cf_inv_grade' => 'White Glove', 'cf_inv_rush' => '0'],
            $this->storedValuesBySlug($I, $targetId),
            'the invoice that was raised carries what was typed on it',
        );
        $I->assertSame(
            $bystanderBefore,
            $this->valueRowsFor($I, $bystanderId),
            'and the invoice nobody touched is exactly as it was, row for row',
        );
    }

    /**
     * A value typed on the invoice create screen is the INVOICE's, and is invisible to the quote and
     * the order.
     *
     * One slug, three definitions, three object types — the collision the old shared value table
     * keyed on a bare `object_id` could not tell apart. Asserted both ways: through the
     * objectType-agnostic read, and by counting rows in the three tables, because that is the claim
     * the per-object-type split actually makes.
     */
    public function anInvoiceValueIsInvisibleToTheQuoteAndTheOrder(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_shared_slug', 'Shared Slug Invoice');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_shared_slug', 'Shared Slug Estimate');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ORDER, 'cf_shared_slug', 'Shared Slug Order');

        $invoiceId = $this->raiseStandaloneInvoice($I, ['cf_shared_slug' => 'INVOICE ONLY']);

        $values = $I->grabService(CustomFieldValueRepository::class);
        $I->assertSame(
            ['cf_shared_slug' => 'INVOICE ONLY'],
            $values->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_INVOICE, $invoiceId),
            'the control: the invoice really does hold the value, under that exact slug',
        );
        $I->assertSame(
            ['cf_shared_slug' => 'INVOICE ONLY'],
            $this->storedValuesBySlug($I, $invoiceId),
            'and the column says the same thing',
        );

        $I->assertSame(1, $this->rowCount($I, 'custom_field_value_invoice'), 'one row, in the invoice table');
        $I->assertSame(0, $this->rowCount($I, 'custom_field_value_estimate'), 'none in the quote table');
        $I->assertSame(0, $this->rowCount($I, 'custom_field_value_order'), 'and none in the order table');
    }

    // ───────────────────────────────────────────────────────────── fixtures and readers

    /**
     * A definition made the way an admin makes one: the real screen, the real POST, the real CSRF
     * token, and a redirect to the index to prove it was accepted rather than re-rendered with
     * errors.
     */
    private function createDefinition(
        FunctionalTester $I,
        string $objectType,
        string $slug,
        string $label,
        string $fieldType = CustomFieldDefinition::FIELD_TYPE_TEXT,
        string $options = '',
    ): void {
        $I->amOnPage('/admin/custom-fields/create');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/custom-fields/create', [
            '_token' => $I->csrfToken(),
            'object_type' => $objectType,
            'slug' => $slug,
            'label' => $label,
            'field_type' => $fieldType,
            'options' => $options,
            'visible_on_add' => '1',
            'visible_on_edit' => '1',
        ]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');
    }

    /** The five field types, as six invoice definitions (two checkboxes, so one can be the control). */
    private function createFieldSet(FunctionalTester $I): void
    {
        foreach (self::FIELD_SET as $slug => [$fieldType, $label, $options]) {
            $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, $slug, $label, $fieldType, $options);
        }
    }

    /**
     * An invoice raised through the REAL standalone create screen, carrying the custom field boxes
     * that screen rendered. Returns its id.
     *
     * @param array<string, string> $customFields slug => typed value
     */
    private function raiseStandaloneInvoice(FunctionalTester $I, array $customFields): int
    {
        $I->amOnPage(sprintf(self::STANDALONE_SCREEN, $this->company->getId()));
        $I->seeResponseCodeIsSuccessful();

        $highWaterMark = (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');

        $I->sendFormPostRequest(self::STANDALONE_SAVE, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-12',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '2', 'price' => '5.00'],
            ],
            'custom_field' => $customFields,
        ]);

        return $this->invoiceRaisedAfter($I, $highWaterMark);
    }

    /**
     * An invoice raised through the REAL Convert to Invoice screen, drawing the order down. Returns
     * its id.
     *
     * @param array<string, string> $customFields slug => typed value
     */
    private function raiseInvoiceFromOrder(FunctionalTester $I, SalesOrder $order, array $customFields): int
    {
        $url = sprintf(self::ORDER_SCREEN, $order->getId());
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        $highWaterMark = (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');
        $lineId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM sales_order_line WHERE order_id = ? ORDER BY id LIMIT 1',
            [(int) $order->getId()],
        );
        $I->assertGreaterThan(0, $lineId, 'guard: the order fixture has a line to draw down');

        $I->sendFormPostRequest($url, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [0 => ['id' => (string) $lineId, 'qty' => '3.00', 'price' => '5.00']],
            'custom_field' => $customFields,
        ]);

        return $this->invoiceRaisedAfter($I, $highWaterMark);
    }

    private function invoiceRaisedAfter(FunctionalTester $I, int $highWaterMark): int
    {
        $id = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice WHERE id > ? ORDER BY id DESC LIMIT 1',
            [$highWaterMark],
        );
        $I->assertGreaterThan(0, $id, 'the create screen actually raised an invoice');

        return $id;
    }

    /**
     * An approved sales order with one line on it — the only state the Convert screen will open for.
     *
     * The customer and the product are re-read through THIS request's entity manager rather than
     * used off $this. Both were persisted in _before() and every definition since has gone through a
     * real HTTP request, which reboots the kernel — so the objects this class is holding are
     * strangers to the manager about to be flushed, and handing one over is the "A new entity was
     * found through the relationship" error. The id survives detachment, so the id is what carries.
     */
    private function approvedOrder(FunctionalTester $I): SalesOrder
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $company = $em->find(Company::class, (int) $this->company->getId());
        $product = $em->find(ProductCore::class, (int) $this->product->getId());
        $I->assertInstanceOf(Company::class, $company, 'guard: the customer fixture is readable in this request');
        $I->assertInstanceOf(ProductCore::class, $product, 'guard: the product fixture is readable in this request');

        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName('Invoice Custom Field Widget')
            ->setSku((string) $product->getSku())
            ->setQuantity('10.00')
            ->setPrice('5.00')
            ->setSubtotal('50.00');

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ICF-ORD-' . uniqid())
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $order->addLine($line);
        $em->persist($order);
        $em->flush();
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        return $order;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    /**
     * What this invoice holds, slug => value, read by SQL off `custom_field_value_invoice` joined to
     * the definitions — not off an entity the request happened to leave behind in memory.
     *
     * @return array<string, string|null>
     */
    private function storedValuesBySlug(FunctionalTester $I, int $invoiceId): array
    {
        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT d.slug AS slug, v.value AS value
               FROM custom_field_value_invoice v
               JOIN custom_field_definition d ON d.id = v.definition_id
              WHERE v.invoice_id = ?
              ORDER BY d.slug',
            [$invoiceId],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['slug']] = $row['value'] === null ? null : (string) $row['value'];
        }

        return $values;
    }

    /**
     * Whole rows, for the "nobody touched it" comparison.
     *
     * @return list<array<string, mixed>>
     */
    private function valueRowsFor(FunctionalTester $I, int $invoiceId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT id, definition_id, invoice_id, value FROM custom_field_value_invoice WHERE invoice_id = ? ORDER BY id',
            [$invoiceId],
        );
    }

    private function rowCount(FunctionalTester $I, string $table): int
    {
        return (int) $this->connection($I)->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
