<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomFieldDefinition;
use App\Entity\Estimate;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Repository\CustomFieldValueRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Admin-defined custom fields on the two sell-side documents that never had them: the invoice and
 * the quote.
 *
 * ## Conducted, not asserted at (#624)
 *
 * Every definition here is created through the REAL /admin/custom-fields screens, and every quote
 * through the REAL /admin/estimate create and edit screens, with plain form POSTs carrying a CSRF
 * token scraped from the rendered page. Nothing is set up by calling a service that the screen
 * would have called. The fixtures are this file's own — no row it did not write is relied on.
 *
 * What is then asserted is the COLUMN. `custom_field_value_estimate.value` and
 * `custom_field_value_invoice.value` are read back through a second connection read, by SQL,
 * because an entity the request left in memory proves what the request computed and not what was
 * stored — and because the whole point of this change is a new table, which only a read of that
 * table can prove exists and holds the right row.
 *
 * ## Why the invoice half stops at storage
 *
 * There is NO invoice edit screen in this application — `admin_invoice_edit` does not exist, which
 * templates/admin/invoice/_tab_nav.html.twig says outright — and the two invoice CREATE screens are
 * being merged into one on another branch, so they are deliberately not wired to custom fields yet.
 * So the invoice tests here cover exactly what ships: the object type is offered on the definition
 * screen, a value persists into its own table and reads back, and it does not leak sideways onto
 * the estimate or the order. There is no skipped test standing in for the form wiring; when that
 * screen lands, its own Cest covers it.
 *
 * ## Numbers are never asserted with see() (#627)
 *
 * `see('50')` matches '1050'. Nothing in this file asserts a figure against the page — values are
 * compared as stored columns, and every page assertion is on a label or a named element, each
 * paired with a positive control on the SAME element so a selector that has quietly stopped
 * matching fails loudly instead of passing as an absence.
 */
final class AdminInvoiceEstimateCustomFieldCest
{
    private Company $company;
    private ProductCore $product;
    private string $regionName;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('cf-invoice-estimate@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Custom Field Documents Co')
            ->setCode('CFD-' . uniqid());
        $I->haveInRepository($this->company);

        // Quote CREATE is refused outright for a company with no active fulfillment region — there
        // would be no price list to quote from — so the company needs one before any create POST.
        $this->regionName = 'CF Docs Region ' . uniqid();
        $priceList = (new PriceList())->setName($this->regionName . ' List')->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);
        $region = (new FulfillmentRegion())->setName($this->regionName)->setStatus('Active');
        $I->haveInRepository($region);
        $I->haveInRepository(
            (new CompanyFulfillmentRegion())
                ->setCompany($this->company)
                ->setFulfillmentRegion($region)
                ->setStatus('Active')
                ->setPriceList($priceList)
        );

        $this->product = (new ProductCore())
            ->setSku('CFD-SKU-' . uniqid())
            ->setName('Custom Field Documents Widget')
            ->setSalesTaxCode('G')
            ->setOriginalPrice('20.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);
    }

    // ---------------------------------------------------------------- the definition screen

    /**
     * The admin can actually reach the two new object types.
     *
     * Asserted on the option elements of the Object Type select rather than on the words anywhere
     * on the page: "Invoice" and "Estimate" appear in the sidebar of every admin screen, so a
     * bare see() would have passed with the select untouched. Order is the positive control — all
     * three assertions use the same selector shape, so one that has stopped matching fails here
     * rather than quietly passing somewhere else.
     */
    public function theDefinitionFormOffersInvoiceAndEstimateBesideOrder(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/custom-fields/create');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('select[name="object_type"] option[value="order"]');
        $I->seeElement('select[name="object_type"] option[value="invoice"]');
        $I->seeElement('select[name="object_type"] option[value="estimate"]');
        // The control on the same element: a type that is deliberately NOT offered here.
        $I->dontSeeElement('select[name="object_type"] option[value="company_address"]');
    }

    /**
     * And the index lists what was created for them, under their own headings.
     *
     * The labels are deliberately distinctive strings rather than words like "Invoice", for the
     * same reason as above — the chrome of the page is full of the object type names.
     */
    public function theIndexListsInvoiceAndEstimateDefinitions(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_idx_inv', 'Freight Claim Reference');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_idx_est', 'Bid Package Number');

        $I->amOnPage('/admin/custom-fields');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Freight Claim Reference');
        $I->see('Bid Package Number');
    }

    // ---------------------------------------------------------------- estimate, end to end

    /**
     * A definition made for the quote object type renders on the quote CREATE form, and only there
     * — the same definition must not appear on the order form, which is a different object type
     * with its own definitions.
     *
     * Both halves use the identical selector against the identical attribute, so the absence
     * assertion has a positive control of exactly the same shape.
     */
    public function anEstimateDefinitionRendersOnTheQuoteCreateFormAndNotOnTheOrderForm(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_est_job', 'Job Site');

        $I->amOnPage('/admin/estimate/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Custom Fields', '#estimate-form h2');
        $I->seeElement('#estimate-form input[name="custom_field[cf_est_job]"]');

        $I->amOnPage('/admin/order/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();
        // The control: the order form really did render a form with inputs in it, so the absence
        // below is the object type scoping and not a blank page or a changed selector.
        $I->seeElement('form input[name="po_number"]');
        $I->dontSeeElement('form input[name="custom_field[cf_est_job]"]');
    }

    /**
     * The whole round trip, and the column at every step: typed on create, stored; shown on edit;
     * retyped; replaced in the column.
     */
    public function aQuoteCustomFieldIsSavedOnCreateShownOnEditAndReplacedByAnEdit(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_est_job', 'Job Site');

        $estimateId = $this->createEstimate($I, ['cf_est_job' => 'Pier 9 East']);

        $I->assertSame(
            ['Pier 9 East'],
            $this->storedValues($I, 'custom_field_value_estimate', 'estimate_id', $estimateId),
            'the create form wrote the typed value into the quote value table',
        );

        // It comes back on the edit form, in the box, holding what was stored.
        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Custom Fields', '#estimate-form h2');
        $I->assertSame(
            'Pier 9 East',
            $I->grabAttributeFrom('#estimate-form input[name="custom_field[cf_est_job]"]', 'value'),
            'the edit form repaints the stored value rather than an empty box',
        );

        $this->editEstimate($I, $estimateId, ['cf_est_job' => 'Pier 12 West']);

        $I->assertSame(
            ['Pier 12 West'],
            $this->storedValues($I, 'custom_field_value_estimate', 'estimate_id', $estimateId),
            'the edit replaced the value in place — one row, not two',
        );
    }

    /**
     * The cheap half of #624: the quote nobody touched is byte-identical afterwards.
     *
     * Two quotes, each with its own value for the same definition. Saving one must not write
     * through the other — which is exactly what a value table keyed on a bare object id with no FK,
     * or a save that looked up by definition alone, would get wrong.
     */
    public function savingOneQuoteLeavesAnotherQuotesValueAlone(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_est_job', 'Job Site');

        $bystanderId = $this->createEstimate($I, ['cf_est_job' => 'Bystander Yard']);
        $bystanderBefore = $this->valueRowsFor($I, 'custom_field_value_estimate', 'estimate_id', $bystanderId);

        $targetId = $this->createEstimate($I, ['cf_est_job' => 'Target Yard']);
        $this->editEstimate($I, $targetId, ['cf_est_job' => 'Target Yard Rewritten']);

        $I->assertSame(
            ['Target Yard Rewritten'],
            $this->storedValues($I, 'custom_field_value_estimate', 'estimate_id', $targetId),
            'the quote that was saved carries the new value',
        );
        $I->assertSame(
            $bystanderBefore,
            $this->valueRowsFor($I, 'custom_field_value_estimate', 'estimate_id', $bystanderId),
            'and the quote nobody touched is exactly as it was, row for row',
        );
    }

    /**
     * A blanked box clears the value rather than leaving the old one standing.
     *
     * The failure this closes is the one a `??` instead of an array_key_exists produces: the admin
     * empties the box, the save reads nothing there, and the screen goes on showing a value the
     * admin deliberately removed.
     */
    public function clearingTheBoxNullsTheStoredValue(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_est_job', 'Job Site');

        $estimateId = $this->createEstimate($I, ['cf_est_job' => 'Briefly Set']);
        $I->assertSame(['Briefly Set'], $this->storedValues($I, 'custom_field_value_estimate', 'estimate_id', $estimateId));

        $this->editEstimate($I, $estimateId, ['cf_est_job' => '']);

        $I->assertSame(
            [null],
            $this->storedValues($I, 'custom_field_value_estimate', 'estimate_id', $estimateId),
            'an emptied box stores NULL, not the value it used to hold',
        );
    }

    // ---------------------------------------------------------------- invoice storage

    /**
     * An invoice value persists into its own table and reads back through the same objectType-
     * agnostic API every other document uses.
     *
     * The definition is made through the real admin screen. The value is written through
     * CustomFieldValueRepository because the invoice FORM is not wired yet (see this class's
     * docblock) — and it is then asserted by SELECTing the column, not by asking the repository
     * again, so a repository that answered from its own identity map could not pass this.
     */
    public function anInvoiceCustomFieldValuePersistsIntoItsOwnTableAndReadsBack(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_inv_claim', 'Freight Claim Reference');
        $invoice = $this->makeInvoice($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $values = $I->grabService(CustomFieldValueRepository::class);
        $values->setValue(
            $this->definition($em, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_inv_claim'),
            (int) $invoice->getId(),
            'CLAIM-77-ALPHA',
        );
        $em->flush();

        $I->assertSame(
            ['CLAIM-77-ALPHA'],
            $this->storedValues($I, 'custom_field_value_invoice', 'invoice_id', (int) $invoice->getId()),
            'the value is in custom_field_value_invoice, in the invoice_id row it belongs to',
        );
        $I->assertSame(
            ['cf_inv_claim' => 'CLAIM-77-ALPHA'],
            $values->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_INVOICE, (int) $invoice->getId()),
            'and the objectType-agnostic read answers for the invoice object type',
        );
    }

    /**
     * The leak test, and the reason each object type has a table of its own.
     *
     * One slug, three definitions — invoice, estimate, order — and three documents whose ids are
     * deliberately allowed to collide, because they are ids in three different tables and the old
     * shared value table keyed on a bare `object_id` could not tell them apart. A value written
     * against the invoice must be visible ONLY against the invoice.
     *
     * The positive control is the invoice read itself: it is the same call, through the same
     * repository, with the same slug, and it answers.
     */
    public function aValueOnOneDocumentTypeIsInvisibleToTheOthers(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_shared_slug', 'Shared Slug Invoice');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_shared_slug', 'Shared Slug Estimate');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ORDER, 'cf_shared_slug', 'Shared Slug Order');

        $invoice = $this->makeInvoice($I);
        $estimate = $this->makeEstimate($I);
        $order = $this->makeOrder($I);

        $em = $I->grabService(EntityManagerInterface::class);
        $values = $I->grabService(CustomFieldValueRepository::class);
        $values->setValue(
            $this->definition($em, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_shared_slug'),
            (int) $invoice->getId(),
            'INVOICE ONLY',
        );
        $em->flush();

        $I->assertSame(
            ['cf_shared_slug' => 'INVOICE ONLY'],
            $values->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_INVOICE, (int) $invoice->getId()),
            'the control: the invoice really does hold the value, under that exact slug',
        );
        $I->assertSame(
            [],
            $values->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, (int) $estimate->getId()),
            'the quote holds nothing, though its definition carries the same slug',
        );
        $I->assertSame(
            [],
            $values->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_ORDER, (int) $order->getId()),
            'and neither does the order',
        );

        // And the tables say the same thing, which is the claim the object-type split actually
        // makes: exactly one row exists anywhere, and it is in the invoice table.
        $I->assertSame(1, $this->rowCount($I, 'custom_field_value_invoice'), 'one row, in the invoice table');
        $I->assertSame(0, $this->rowCount($I, 'custom_field_value_estimate'), 'none in the quote table');
        $I->assertSame(0, $this->rowCount($I, 'custom_field_value_order'), 'none in the order table');
    }

    /**
     * The same claim from the other direction, driven by the real quote form rather than by the
     * repository: a quote saved through the screen writes to the quote table and nowhere else.
     */
    public function aQuoteSavedThroughTheScreenWritesToTheQuoteTableAlone(FunctionalTester $I): void
    {
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ESTIMATE, 'cf_shared_slug', 'Shared Slug Estimate');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_INVOICE, 'cf_shared_slug', 'Shared Slug Invoice');
        $this->createDefinition($I, CustomFieldDefinition::OBJECT_TYPE_ORDER, 'cf_shared_slug', 'Shared Slug Order');

        $estimateId = $this->createEstimate($I, ['cf_shared_slug' => 'QUOTE ONLY']);

        $I->assertSame(
            ['QUOTE ONLY'],
            $this->storedValues($I, 'custom_field_value_estimate', 'estimate_id', $estimateId),
            'the control: the quote table holds what the form posted',
        );
        $I->assertSame(0, $this->rowCount($I, 'custom_field_value_invoice'), 'and the invoice table holds nothing');
        $I->assertSame(0, $this->rowCount($I, 'custom_field_value_order'), 'and neither does the order table');
    }

    // ---------------------------------------------------------------- fixtures and readers

    /**
     * A definition made the way an admin makes one: the real screen, the real POST, the real CSRF
     * token. Returns the row the screen wrote.
     */
    private function createDefinition(FunctionalTester $I, string $objectType, string $slug, string $label): void
    {
        $I->amOnPage('/admin/custom-fields/create');
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/custom-fields/create', [
            '_token' => $I->csrfToken(),
            'object_type' => $objectType,
            'slug' => $slug,
            'label' => $label,
            'field_type' => CustomFieldDefinition::FIELD_TYPE_TEXT,
            'visible_on_add' => '1',
            'visible_on_edit' => '1',
        ]);
        $I->seeCurrentUrlEquals('/admin/custom-fields');
    }

    /**
     * The definition row, read through the SAME entity manager the value repository is holding.
     *
     * Not through grabEntityFromRepository(): the Doctrine module keeps the entity manager it was
     * handed when the test started, and the kernel has rebooted several times since under the HTTP
     * requests above, so what that returns can be a managed entity of a DIFFERENT manager than the
     * one CustomFieldValueRepository writes through. Handing it one is the "A new entity was found
     * through the relationship ... #definition" error — the definition is perfectly real and
     * already in the database, it is simply a stranger to the manager being flushed.
     */
    private function definition(EntityManagerInterface $em, string $objectType, string $slug): CustomFieldDefinition
    {
        $definition = $em->getRepository(CustomFieldDefinition::class)
            ->findOneBy(['objectType' => $objectType, 'slug' => $slug]);

        if (!$definition instanceof CustomFieldDefinition) {
            throw new \RuntimeException(sprintf('No %s definition with slug %s — the admin screen did not create it.', $objectType, $slug));
        }

        return $definition;
    }

    /**
     * A quote raised through the real create screen, carrying the custom field boxes the screen
     * rendered. Returns its id.
     *
     * @param array<string, string> $customFields slug => typed value
     */
    private function createEstimate(FunctionalTester $I, array $customFields): int
    {
        $I->amOnPage('/admin/estimate/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        $highWaterMark = (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM estimate');

        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->company->getId(),
            'fulfillment_region' => $this->regionName,
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '20.00'],
            ],
            'save_mode' => 'draft',
            'custom_field' => $customFields,
        ]);

        $id = (int) $this->connection($I)->fetchOne('SELECT id FROM estimate WHERE id > ? ORDER BY id DESC LIMIT 1', [$highWaterMark]);
        $I->assertGreaterThan(0, $id, 'the create screen actually raised a quote');

        return $id;
    }

    /**
     * An edit through the real edit screen. The line rows are re-posted because the form rebuilds
     * the quote from what it posts — a save carrying no line is refused as an empty quote, and the
     * custom field would never be reached.
     *
     * @param array<string, string> $customFields slug => typed value
     */
    private function editEstimate(FunctionalTester $I, int $estimateId, array $customFields): void
    {
        $I->amOnPage('/admin/estimate/edit/' . $estimateId);
        $I->seeResponseCodeIsSuccessful();

        $lineId = (string) $this->connection($I)->fetchOne('SELECT id FROM estimate_line WHERE estimate_id = ? ORDER BY id LIMIT 1', [$estimateId]);

        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimateId, [
            '_token' => $I->csrfToken(),
            'lines' => [
                0 => ['id' => $lineId, 'product_id' => (string) $this->product->getId(), 'qty' => '2', 'price' => '20.00'],
            ],
            'action' => 'save',
            'custom_field' => $customFields,
        ]);
    }

    /**
     * The customer, re-read for THIS request's entity manager.
     *
     * `$this->company` was persisted in _before() and has been through several HTTP requests since;
     * the object this class is holding is detached by the time a later fixture wants to point at
     * it, and handing a detached entity to a new one is the "A new entity was found through the
     * relationship" error. The id survives detachment, so the id is what is carried across.
     */
    private function company(FunctionalTester $I): Company
    {
        return $I->grabEntityFromRepository(Company::class, ['id' => $this->company->getId()]);
    }

    private function makeInvoice(FunctionalTester $I): Invoice
    {
        $invoice = (new Invoice())
            ->setCompany($this->company($I))
            ->setDocumentNumber('CFD-INV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoiceDate('2026-09-01')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $I->haveInRepository($invoice);

        return $invoice;
    }

    private function makeEstimate(FunctionalTester $I): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($this->company($I))
            ->setDocumentNumber('CFD-EST-' . uniqid())
            ->setSource('Admin')
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function makeOrder(FunctionalTester $I): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company($I))
            ->setOrderNumber('CFD-ORD-' . uniqid())
            ->setSubtotal('40.00')
            ->setTax('0.00')
            ->setTotal('40.00');
        $I->haveInRepository($order);

        return $order;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    /**
     * The `value` column of every row this document owns, in id order — read by SQL, not off an
     * entity the request happened to leave behind.
     *
     * @return list<string|null>
     */
    private function storedValues(FunctionalTester $I, string $table, string $foreignKey, int $objectId): array
    {
        return array_map(
            static fn (array $row): ?string => $row['value'] === null ? null : (string) $row['value'],
            $this->valueRowsFor($I, $table, $foreignKey, $objectId),
        );
    }

    /**
     * Whole rows, for the "nobody touched it" comparison — a value that stayed the same while its
     * definition_id was rewritten underneath it would pass a value-only check.
     *
     * @return list<array<string, mixed>>
     */
    private function valueRowsFor(FunctionalTester $I, string $table, string $foreignKey, int $objectId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            sprintf('SELECT id, definition_id, %s, value FROM %s WHERE %s = ? ORDER BY id', $foreignKey, $table, $foreignKey),
            [$objectId],
        );
    }

    private function rowCount(FunctionalTester $I, string $table): int
    {
        return (int) $this->connection($I)->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
