<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use Twig\Environment;

/**
 * The sell-side forms' shared components: the address panels, the fee line row and the add-line
 * bar, each now one partial under templates/admin/_partials/ instead of two or three copies.
 *
 * What this file is FOR is the thing an extraction can silently get wrong: a partial that renders
 * beautifully and drops a field name, so the screen looks right and the save stops carrying a
 * column. So every screen assertion here is paired with a POST that reads the row back out of the
 * database by column (#624) — rendering is not the claim, saving is.
 *
 * The before/after proof that each extraction changed nothing is separate and does not live in a
 * test: the five screens were rendered whole before and after and compared as documents.
 */
final class SellSideSharedComponentsCest
{
    /** The address fields the shared panel posts, per side. The order and the quote both read these. */
    private const ADDRESS_FIELDS = [
        'address_id', 'company_name', 'first_name', 'last_name', 'phone',
        'address_1', 'address_2', 'city', 'country', 'province',
        'postal_code', 'fax', 'primary_email', 'secondary_email', 'delivery_instructions',
    ];

    /** The choices the add-line bar offers on every sell-side document, beyond the carrier options. */
    private const CHARGE_CHOICES = [
        'shipping:Custom Shipping',
        'tax:Custom GST',
        'tax:Custom PST',
        'tax:Custom HST',
        'empty-shipping:',
        'empty-tax:',
        'empty-fee:',
    ];

    private ?Company $company = null;
    private ?ProductCore $product = null;

    /**
     * Codeception reuses ONE Cest instance for every method in the file, so a handle left on $this
     * by the previous test is a handle onto a rolled-back row. Both are cleared here rather than
     * reused.
     */
    public function _before(FunctionalTester $I): void
    {
        $this->company = null;
        $this->product = null;
    }

    // ---------------------------------------------------------------- fixtures

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('sell-side-shared@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        if ($this->company instanceof Company) {
            return $this->company;
        }

        $company = (new Company())
            ->setName('Shared Components Co')
            ->setCode('SHARED-' . uniqid())
            ->setPrimaryEmail('buyer@shared.example')
            ->setPhoneNumber('604-555-0100');
        $I->haveInRepository($company);

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Default Billing')
                ->setCompanyName('Shared Components Co')
                ->setFirstName('Bill')
                ->setLastName('Payer')
                ->setAddressLine1('1 Billing Way')
                ->setCity('Vancouver')
                ->setProvince('BC')
                ->setCountry('CA')
                ->setPostalCode('V5K0A1')
                ->setIsDefaultBilling(true)
        );
        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Default Shipping')
                ->setCompanyName('Shared Components Co')
                ->setFirstName('Ship')
                ->setLastName('Receiver')
                ->setAddressLine1('2 Shipping Road')
                ->setCity('Burnaby')
                ->setProvince('BC')
                ->setCountry('CA')
                ->setPostalCode('V5K0A2')
                ->setIsDefaultShipping(true)
        );

        $I->haveActiveFulfillmentRegionFor($company, 'Shared Region');
        $this->company = $company;

        return $company;
    }

    private function makeProduct(FunctionalTester $I): ProductCore
    {
        if ($this->product instanceof ProductCore) {
            return $this->product;
        }

        $product = (new ProductCore())
            ->setSku('SHARED-SKU-1')
            ->setName('Shared Components Widget')
            ->setUnit('EA')
            ->setWeight('12.500')
            ->setSalesTaxCode('G')
            ->setCostPrice('30.55')
            ->setDefaultPrice('65.25')
            ->setOriginalPrice('79.99')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product, 500, 'Shared Region');
        $this->product = $product;

        return $product;
    }

    /** A saved order with one line and a computed main-line fee, so the fee row is on the page. */
    private function makeOrder(FunctionalTester $I): SalesOrder
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SHARED-ORDER-' . uniqid())
            ->setFulfillmentRegion('Shared Region')
            ->setSubtotal('130.50')
            ->setTax('0.00')
            ->setTotal('130.50');
        $order->setFeeLines($this->mainLineFeeSnapshot());
        $order->setBillingAddressFrom($company->getDefaultBillingAddress());
        $order->setShippingAddressFrom($company->getDefaultShippingAddress());
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName('Shared Components Widget')
                ->setSku('SHARED-SKU-1')
                ->setQuantity('2')
                ->setPrice('65.25')
                ->setSubtotal('130.50')
        );
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        return $order;
    }

    private function makeEstimate(FunctionalTester $I): Estimate
    {
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('SHARED-EST-' . uniqid())
            ->setSource('Admin');
        $estimate->setStatus('Submitted', DocumentActor::system());
        $estimate->setFeeLines($this->mainLineFeeSnapshot());
        $estimate->setFulfillmentRegion('Shared Region');
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($product)
                ->setName('Shared Components Widget')
                ->setSku('SHARED-SKU-1')
                ->setQuantity('2.00')
                ->setCost('30.55')
                ->setPrice('65.25')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }

    private function mainLineFeeSnapshot(): string
    {
        return json_encode([[
            'slug' => 'bc-recycling-fee',
            'label' => 'BC Recycling Fee',
            'amount' => 4.5,
            'type' => 'fee',
            'taxClass' => 'none',
            'placement' => 'main_line',
            'source' => 'auto-calc',
        ]], JSON_THROW_ON_ERROR);
    }

    /**
     * Asserts the form on the page really renders a control for each of these names.
     *
     * Without this the POSTs below prove only that the CONTROLLER accepts a field — they are
     * hand-written, so a template that renamed or dropped an input would keep passing while the
     * screen quietly stopped posting it. That is precisely the failure mode an extraction has, so
     * every save test here states first that the screen offers what it is about to post.
     *
     * Proved by mutation: renaming the merged section's po_number input left the create-save test
     * green until this check was added.
     */
    private function seeTheFormPosts(FunctionalTester $I, string $form, array $names): void
    {
        $rendered = [];
        foreach (['input', 'select', 'textarea', 'button'] as $control) {
            $rendered = array_merge($rendered, $I->grabMultiple($form . ' ' . $control, 'name'));
        }
        $rendered = array_values(array_unique(array_filter(
            $rendered,
            static fn (?string $name): bool => (string) $name !== '',
        )));

        foreach ($names as $name) {
            $I->assertContains(
                $name,
                $rendered,
                sprintf('%s renders no control named %s — this POST would be testing the controller, not the screen', $form, $name),
            );
        }
    }

    /** @return array<string, mixed> */
    private function addressRow(FunctionalTester $I, string $table, int $documentId, string $column, string $type): array
    {
        $row = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ? AND type = ?', $table, $column),
            [$documentId, $type],
        );
        $I->assertIsArray($row, sprintf('%s has no %s row for document %d', $table, $type, $documentId));

        return $row;
    }

    // ------------------------------------------------- 1. the address panels

    /**
     * The two forms post the SAME field names through the shared panel.
     *
     * Read off each screen rather than written down twice: the set is built from the page, both
     * sides of both documents, and compared. A panel that renamed or dropped a field on one form
     * fails here even though the page still looks complete, which is the failure mode an extraction
     * has.
     */
    public function theOrderAndQuoteAddressPanelsPostTheSameFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);

        $expected = [];
        foreach (['billing', 'shipping'] as $side) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $expected[] = $side . '_' . $field;
            }
        }
        sort($expected);

        foreach ([
            'the order form' => '/admin/order/edit/' . $order->getId(),
            'the quote form' => '/admin/estimate/edit/' . $estimate->getId(),
        ] as $what => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $found = [];
            foreach (['billing', 'shipping'] as $side) {
                $panel = 'section.order-form-panel[data-address-panel="' . $side . '"] ';
                foreach (['input', 'select', 'textarea'] as $control) {
                    // The address-book <select> carries no name — it is a picker JavaScript
                    // copies FROM, never a posted field — so nameless controls are dropped and
                    // what is left is exactly what this panel posts.
                    $found = array_merge($found, array_filter(
                        $I->grabMultiple($panel . $control, 'name'),
                        static fn (?string $name): bool => (string) $name !== '',
                    ));
                }
            }
            sort($found);

            $I->assertSame($expected, $found, $what . ' does not post the shared address panel\'s fields');
        }
    }

    /**
     * City, Province, Country, then Postal code — matching the entity's own property order
     * (AbstractDocumentAddress) and every read-only detail page, not the panel's prior
     * City/Country/Province sequence (#671). Read off `data-address-field`, which every one of
     * these controls carries regardless of tag (`<input>` for City/Postal code, `<select>` for
     * Province/Country), so this is one grab in document order rather than two selectors that
     * could each be individually correct while still interleaved wrong.
     */
    public function theAddressPanelOrdersCityProvinceCountryThenPostalCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        foreach (['billing', 'shipping'] as $side) {
            // Scoped to label.order-field-row so the hidden address_id input (data-address-field
            //="id", a direct child of the section, not inside a labelled row) doesn't count as a
            // visible field in this ordering.
            $panel = 'section.order-form-panel[data-address-panel="' . $side . '"] label.order-field-row ';
            $fields = $I->grabMultiple($panel . '[data-address-field]', 'data-address-field');

            $I->assertSame(
                ['companyName', 'firstName', 'lastName', 'phone', 'addressLine1', 'addressLine2', 'city', 'province', 'country', 'postalCode', 'fax', 'primaryEmail', 'secondaryEmail', 'deliveryInstructions'],
                $fields,
                $side . ' panel field order',
            );
        }
    }

    /**
     * The quote's panel keeps ORDER's hook class names, on purpose.
     *
     * app.js binds .js-order-toggle-address-book, .js-order-address-select, .js-order-apply-address
     * and .js-order-close-address-book document-wide, not to the order page, which is the whole
     * reason one partial can drive both pickers. Renaming them to something document-neutral leaves
     * every one of these assertions passing on markup and the address book doing nothing on both
     * screens — so the absence of a js-estimate-* twin is asserted beside the presence of the
     * js-order-* original, on the same panel (#627).
     */
    public function theAddressBookPickerKeepsOrdersHookNamesOnBothForms(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);

        foreach ([
            '/admin/order/edit/' . $order->getId(),
            '/admin/estimate/edit/' . $estimate->getId(),
        ] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $panel = 'section.order-form-panel[data-address-panel="billing"] ';
            $I->seeElement($panel . 'button.js-order-toggle-address-book');
            $I->seeElement($panel . 'select.js-order-address-select');
            $I->seeElement($panel . 'button.js-order-apply-address');
            $I->seeElement($panel . 'button.js-order-close-address-book');
            // The rename that would silently unbind it.
            $I->dontSeeElement($panel . 'button.js-estimate-toggle-address-book');
            $I->dontSeeElement($panel . 'select.js-estimate-address-select');
        }
    }

    /** The order still SAVES what the shared panel posts — read back out of sales_order_address. */
    public function theOrderSavesTheAddressTypedIntoTheSharedPanel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $product = $this->makeProduct($I);
        $lineId = (int) $order->getLines()->first()->getId();

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $this->seeTheFormPosts($I, 'form#order-form', [
            'company_id',
            'shipping_address_id', 'shipping_company_name', 'shipping_first_name', 'shipping_last_name',
            'shipping_phone', 'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_country', 'shipping_province', 'shipping_postal_code', 'shipping_fax',
            'shipping_primary_email', 'shipping_secondary_email', 'shipping_delivery_instructions',
        ]);
        $I->sendFormPostRequest('/admin/order/edit/' . $order->getId(), [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $this->makeCompany($I)->getId(),
            'lines' => [
                ['id' => (string) $lineId, 'product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '65.25', 'tax_code' => 'G'],
            ],
            'shipping_address_id' => '',
            'shipping_company_name' => 'Panel Shipping Co',
            'shipping_first_name' => 'Panel',
            'shipping_last_name' => 'Tester',
            'shipping_phone' => '604-555-9001',
            'shipping_address_1' => '9 Panel Street',
            'shipping_address_2' => 'Bay 3',
            'shipping_city' => 'Kelowna',
            'shipping_country' => 'CA',
            'shipping_province' => 'BC',
            'shipping_postal_code' => 'V1Y1A1',
            'shipping_fax' => '604-555-9002',
            'shipping_primary_email' => 'panel-primary@shared.example',
            'shipping_secondary_email' => 'panel-secondary@shared.example',
            'shipping_delivery_instructions' => 'Rear dock, 7am-3pm',
            'save_mode' => 'draft_recalc',
        ]);

        $row = $this->addressRow($I, 'sales_order_address', (int) $order->getId(), 'order_id', 'shipping');

        $I->assertSame('Panel Shipping Co', $row['company_name']);
        $I->assertSame('Panel', $row['first_name']);
        $I->assertSame('Tester', $row['last_name']);
        $I->assertSame('604-555-9001', $row['phone']);
        $I->assertSame('9 Panel Street', $row['address_line1']);
        $I->assertSame('Bay 3', $row['address_line2']);
        $I->assertSame('Kelowna', $row['city']);
        $I->assertSame('CA', $row['country']);
        $I->assertSame('BC', $row['province']);
        $I->assertSame('V1Y1A1', $row['postal_code']);
        $I->assertSame('604-555-9002', $row['fax']);
        $I->assertSame('panel-primary@shared.example', $row['email_primary']);
        $I->assertSame('panel-secondary@shared.example', $row['email_secondary']);
        $I->assertSame('Rear dock, 7am-3pm', $row['delivery_instructions']);

        // The row that should NOT have changed: billing is untouched by a shipping-only post.
        $billing = $this->addressRow($I, 'sales_order_address', (int) $order->getId(), 'order_id', 'billing');
        $I->assertSame('1 Billing Way', $billing['address_line1'], 'a shipping edit rewrote the billing snapshot');
    }

    /** The quote still SAVES what the shared panel posts — read back out of estimate_address. */
    public function theQuoteSavesTheAddressTypedIntoTheSharedPanel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $this->seeTheFormPosts($I, 'form#estimate-form', [
            'fulfillment_region', 'lines[0][product_id]', 'lines[0][qty]', 'lines[0][price]',
            'billing_address_id', 'billing_company_name', 'billing_first_name', 'billing_last_name',
            'billing_phone', 'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_country', 'billing_province', 'billing_postal_code', 'billing_fax',
            'billing_primary_email', 'billing_secondary_email', 'billing_delivery_instructions',
            'action',
        ]);
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'fulfillment_region' => 'Shared Region',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '65.25'],
            ],
            'billing_address_id' => '',
            'billing_company_name' => 'Panel Billing Co',
            'billing_first_name' => 'Quote',
            'billing_last_name' => 'Panel',
            'billing_phone' => '604-555-8001',
            'billing_address_1' => '8 Quote Lane',
            'billing_address_2' => 'Floor 2',
            'billing_city' => 'Victoria',
            'billing_country' => 'CA',
            'billing_province' => 'BC',
            'billing_postal_code' => 'V8W1A1',
            'billing_fax' => '604-555-8002',
            'billing_primary_email' => 'quote-primary@shared.example',
            'billing_secondary_email' => 'quote-secondary@shared.example',
            'billing_delivery_instructions' => 'Invoices by email only',
            'action' => 'save',
        ]);

        $row = $this->addressRow($I, 'estimate_address', (int) $estimate->getId(), 'estimate_id', 'billing');

        $I->assertSame('Panel Billing Co', $row['company_name']);
        $I->assertSame('Quote', $row['first_name']);
        $I->assertSame('Panel', $row['last_name']);
        $I->assertSame('604-555-8001', $row['phone']);
        $I->assertSame('8 Quote Lane', $row['address_line1']);
        $I->assertSame('Floor 2', $row['address_line2']);
        $I->assertSame('Victoria', $row['city']);
        $I->assertSame('CA', $row['country']);
        $I->assertSame('BC', $row['province']);
        $I->assertSame('V8W1A1', $row['postal_code']);
        $I->assertSame('604-555-8002', $row['fax']);
        $I->assertSame('quote-primary@shared.example', $row['email_primary']);
        $I->assertSame('quote-secondary@shared.example', $row['email_secondary']);
        $I->assertSame('Invoices by email only', $row['delivery_instructions']);

        $shipping = $this->addressRow($I, 'estimate_address', (int) $estimate->getId(), 'estimate_id', 'shipping');
        $I->assertSame('2 Shipping Road', $shipping['address_line1'], 'a billing edit rewrote the shipping snapshot');
    }

    /**
     * The standalone invoice joined the shared panel (#full-parity, ruled 2026-09-12): a document
     * gets a feature unless there is a genuine document-specific reason it should not, and "an
     * invoice has no order to inherit a snapshot from" only explains why it defaults from the
     * company's address book instead of an order — it was never a reason the fields should be
     * uneditable. The invoice still freezes what was typed at save; only the create-time experience
     * changed, from read-only preview to the same pick-or-type card Order and Quote already offer.
     */
    public function theStandaloneInvoiceHasTheSameEditableAddressPanel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();
        $highWaterMark = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $this->seeTheFormPosts($I, 'form', [
            'lines[0][product_id]',
            'billing_address_id', 'billing_company_name', 'billing_first_name', 'billing_last_name',
            'billing_phone', 'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_country', 'billing_province', 'billing_postal_code', 'billing_fax',
            'billing_primary_email', 'billing_secondary_email', 'billing_delivery_instructions',
            'shipping_address_id', 'shipping_company_name', 'shipping_first_name', 'shipping_last_name',
        ]);

        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'save_mode' => 'draft',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '2', 'price' => '65.25'],
            ],
            'billing_address_id' => '',
            'billing_company_name' => 'Panel Billing Co',
            'billing_first_name' => 'Invoice',
            'billing_last_name' => 'Panel',
            'billing_phone' => '604-555-7001',
            'billing_address_1' => '7 Invoice Lane',
            'billing_address_2' => 'Suite 5',
            'billing_city' => 'Richmond',
            'billing_country' => 'CA',
            'billing_province' => 'BC',
            'billing_postal_code' => 'V7A1A1',
            'billing_fax' => '604-555-7002',
            'billing_primary_email' => 'invoice-primary@shared.example',
            'billing_secondary_email' => 'invoice-secondary@shared.example',
            'billing_delivery_instructions' => 'Front desk only',
            'shipping_address_id' => '',
            'shipping_company_name' => 'Shared Components Co',
            'shipping_first_name' => 'Ship',
            'shipping_last_name' => 'Receiver',
            'shipping_address_1' => '2 Shipping Road',
            'shipping_city' => 'Burnaby',
            'shipping_country' => 'CA',
            'shipping_province' => 'BC',
            'shipping_postal_code' => 'V5K0A2',
        ]);

        $invoiceId = (int) $connection->fetchOne('SELECT id FROM invoice WHERE id > ? ORDER BY id DESC LIMIT 1', [$highWaterMark]);

        $row = $this->addressRow($I, 'invoice_address', $invoiceId, 'invoice_id', 'billing');
        $I->assertSame('Panel Billing Co', $row['company_name']);
        $I->assertSame('Invoice', $row['first_name']);
        $I->assertSame('Panel', $row['last_name']);
        $I->assertSame('604-555-7001', $row['phone']);
        $I->assertSame('7 Invoice Lane', $row['address_line1']);
        $I->assertSame('Suite 5', $row['address_line2']);
        $I->assertSame('Richmond', $row['city']);
        $I->assertSame('BC', $row['province']);
        $I->assertSame('V7A1A1', $row['postal_code']);
        $I->assertSame('604-555-7002', $row['fax']);
        $I->assertSame('invoice-primary@shared.example', $row['email_primary']);
        $I->assertSame('invoice-secondary@shared.example', $row['email_secondary']);
        $I->assertSame('Front desk only', $row['delivery_instructions']);

        // The row that should NOT have changed: shipping is untouched by a billing-only expectation.
        $shipping = $this->addressRow($I, 'invoice_address', $invoiceId, 'invoice_id', 'shipping');
        $I->assertSame('2 Shipping Road', $shipping['address_line1']);
    }

    // -------------------------------------------------- 2. the add-line bar

    /**
     * The choices are the same on all three documents, and each document's own hooks and submit are
     * what differ — with one deliberate exception (#669): all three now pass `categoryFilter`
     * (#full-parity, 2026-09-13 — the standalone invoice's own line/charge JS matches order's and
     * quote's exactly) and so all three drop "Custom Shipping", the preset-label twin of "Empty
     * Shipping Line", since a preset label an admin cannot edit and a free-text row that does the
     * same job is one option too many.
     */
    public function theAddLineBarOffersTheSameChoicesOnAllThreeDocuments(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $orderUrl = '/admin/order/edit/' . $order->getId();
        $estimateUrl = '/admin/estimate/edit/' . $estimate->getId();
        $invoiceUrl = '/admin/invoice/create?company_id=' . $company->getId();
        $categoryFilteredUrls = [$orderUrl, $estimateUrl, $invoiceUrl];
        foreach ([
            $orderUrl,
            $estimateUrl,
            $invoiceUrl,
        ] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $values = $I->grabMultiple('.order-add-line-bar select[name="charge_line_type"] option', 'value');
            foreach (self::CHARGE_CHOICES as $choice) {
                if (in_array($url, $categoryFilteredUrls, true) && $choice === 'shipping:Custom Shipping') {
                    $I->assertNotContains($choice, $values, $url . ' still offers the retired Custom Shipping option');
                    continue;
                }
                $I->assertContains($choice, $values, $url . ' does not offer ' . $choice);
            }
            $I->assertContains('', $values, $url . ' has no empty first option');
        }
    }

    public function eachDocumentsAddLineBarCarriesItsOwnHooksAndSubmit(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $bar = '.order-add-line-bar ';

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement($bar . 'select.js-order-charge-type[name="charge_line_type"]');
        $I->seeElement($bar . 'select.js-order-charge-type[data-shipping-options-url]');
        $I->seeElement($bar . 'button.js-order-bottom-add[type="button"]');
        $I->assertSame(
            ['Add Line & Save'],
            array_map(trim(...), $I->grabMultiple($bar . 'button.no-js-inline[name="add_charge_line"]')),
        );

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement($bar . 'select.js-estimate-charge-type[name="charge_line_type"]');
        $I->seeElement($bar . 'button.js-estimate-bottom-add[type="button"]');
        // Only the order has an endpoint to re-fetch carrier options from.
        $I->dontSeeElement($bar . 'select[data-shipping-options-url]');
        $I->assertSame(
            ['Add Line & Save'],
            array_map(trim(...), $I->grabMultiple($bar . 'button.no-js-inline[name="add_charge_line"]')),
        );

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeElement($bar . 'select.js-invoice-charge-type[name="charge_line_type"]');
        $I->seeElement($bar . 'button.js-invoice-bottom-add[type="button"]');
        // Only the order has an endpoint to re-fetch carrier options from.
        $I->dontSeeElement($bar . 'select[data-shipping-options-url]');
        $I->assertSame(
            ['Add Line & Save'],
            array_map(trim(...), $I->grabMultiple($bar . 'button.no-js-inline[name="add_charge_line"]')),
        );
    }

    /**
     * DEFECT: the standalone invoice dropped the carrier's delivery time.
     *
     * InvoiceController::invoiceShippingOptions() computes `deliveryDays` and hands it to the
     * template; the template's own copy of the option line printed label and amount and nothing
     * else, while the order's and the quote's — byte-identical to each other — printed "(5 days)".
     * "$15.00" against "$30.84" is not a choice until it says which arrives when.
     *
     * WHY THIS IS ASSERTED AGAINST THE PARTIAL AND NOT THE INVOICE SCREEN. The create page prices
     * its options against a transient document that carries no lines
     * (InvoiceController::renderStandaloneForm()), and every resolver that reports a delivery time
     * requires items — so the only options that page can offer today are Free Shipping and Pickup,
     * both of which report no delivery time at all. The order and quote CREATE pages are in exactly
     * the same position; only their EDIT screens, which have a saved document behind them, ever show
     * a carrier with days on it, and a standalone invoice has no edit screen. So the defect was
     * real markup drift and unobservable on the screen as it is reachable, and the guard has to be
     * the partial rendered with the invoice's own parameter set. The real-screen half is below it:
     * the order's edit page, where such an option does appear.
     *
     * That second finding — that the standalone invoice's shipping options are resolved against a
     * document with no lines, so no carrier requiring items is ever offered and the quoted price
     * ignores what is being invoiced — is left alone here. It is a controller defect, not this one.
     */
    public function theAddLineBarStatesACarriersDeliveryTime(FunctionalTester $I): void
    {
        $twig = $I->grabService(Environment::class);

        $bar = $twig->render('admin/_partials/sales_add_line_bar.html.twig', [
            // The standalone invoice's own parameter set, exactly as create_standalone.html.twig
            // passes it: no hook, no sentinel, its own submit.
            'shippingOptions' => [
                ['id' => 'canada-post-ground', 'label' => 'Canada Post - Ground', 'amount' => 15.0, 'deliveryDays' => 5, 'taxClass' => 'G'],
                ['id' => 'canada-post-express', 'label' => 'Canada Post - Express', 'amount' => 30.84, 'deliveryDays' => 2, 'taxClass' => 'G'],
                ['id' => 'canada-post-overnight', 'label' => 'Canada Post - Overnight', 'amount' => 60.0, 'deliveryDays' => 1, 'taxClass' => 'G'],
                ['id' => 'pickup', 'label' => 'Pickup', 'amount' => 0.0, 'deliveryDays' => null, 'taxClass' => 'E'],
            ],
            'submitLabel' => 'Add charge line',
            'submitClass' => 'button outline',
        ]);

        $I->assertStringContainsString('>Canada Post - Ground (5 days) — $15.00<', $bar);
        $I->assertStringContainsString('>Canada Post - Express (2 days) — $30.84<', $bar);
        // One day is one day, not "1 days".
        $I->assertStringContainsString('>Canada Post - Overnight (1 day) — $60.00<', $bar);
        // The positive control's partner: an option that reports no delivery time says nothing in
        // its place rather than an empty pair of brackets.
        $I->assertStringContainsString('>Pickup — $0.00<', $bar);
        $I->assertStringNotContainsString('>Pickup (', $bar);
    }

    /** The real-screen half of the above, on the one sell-side screen that can reach such an option. */
    public function theOrderEditBarStatesTheDeliveryTimeOnTheScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();

        $labels = array_map(trim(...), $I->grabMultiple('.order-add-line-bar select[name="charge_line_type"] option'));
        $ground = array_values(array_filter($labels, static fn (string $l): bool => str_starts_with($l, 'Canada Post - Ground')));
        $I->assertCount(1, $ground, 'the fixture no longer offers a carrier that reports a delivery time');
        $I->assertStringContainsString('(5 days)', $ground[0]);
        // Same element, the other way round: an option with no delivery time carries no brackets.
        $pickup = array_values(array_filter($labels, static fn (string $l): bool => str_starts_with($l, 'Pickup')));
        $I->assertCount(1, $pickup);
        $I->assertStringNotContainsString('(', $pickup[0]);
    }

    /**
     * All three documents' add-line bars carry the charge sentinel, unconditionally.
     *
     * The marker exists so a form that rebuilds a document from its own post can tell "the admin
     * removed every charge row" from "this post was never shown them" (#full-parity, 2026-09-13 —
     * the shared toolbar emits it the same way for order, estimate and invoice; a document type
     * that doesn't need the guard yet still costs nothing by carrying it).
     */
    public function eachDocumentsAddLineBarCarriesTheChargeSentinel(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $I->seeElement('input[type="hidden"][name="charge_lines_present"][value="1"]');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('input[type="hidden"][name="charge_lines_present"][value="1"]');

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeElement('input[type="hidden"][name="charge_lines_present"][value="1"]');
    }

    /** The invoice's add-line submit still adds a charge row through the shared bar. */
    public function theStandaloneInvoiceStillAddsAChargeRowFromTheSharedBar(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'add_charge_line' => '1',
            'charge_line_type' => 'empty-fee:',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '10.00'],
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();
        // The row the bar asked for came back as an editable charge row.
        $I->seeElement('.order-total-box .order-charge-line-row[data-charge-type="fee"]');
        $I->seeElement('.order-total-box input[name="charge_lines[0][amount]"]');
    }

    // ------------------------------------- 4. one line-items section, both modes

    /**
     * The quote form renders its line-items section ONCE, for create and for edit alike.
     *
     * It used to render it twice, in two branches, of which 53 of the create branch's 117
     * substantive lines were copies of the edit branch's — and that is not a tidiness complaint:
     * it is how the bottom [line type] + Add Line control landed on edit and was silently missing
     * from create. So the assertion is that each mode has exactly ONE of each of these, which fails
     * both ways: a second copy fails it, and a mode that lost one fails it too.
     */
    public function theQuoteRendersOneLineItemsSectionInBothModes(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        foreach ([
            'quote edit' => '/admin/estimate/edit/' . $estimate->getId(),
            'quote create' => '/admin/estimate/create?company_id=' . $company->getId(),
        ] as $mode => $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $I->seeNumberOfElements('form#estimate-form', 1);
            $I->seeNumberOfElements('section.order-lines-section', 1);
            $I->seeNumberOfElements('table#estimate-line-table', 1);
            $I->seeNumberOfElements('tbody#estimate-line-rows', 1);
            $I->seeNumberOfElements('.order-lines-footer', 1);
            // The control whose absence from create was the defect the duplication caused.
            $I->seeNumberOfElements('.order-add-line-bar select[name="charge_line_type"]', 1);
            $I->seeNumberOfElements('.order-add-line-bar button.js-estimate-bottom-add', 1);
            $I->seeNumberOfElements('.order-total-box', 1);
            $I->seeNumberOfElements('.order-bottom-actions', 1);
        }
    }

    /**
     * What each mode does NOT have, paired with what it does, on the same form.
     *
     * The version field is edit-only: there is no row yet for a second admin to race over on
     * create. company_id is create-only: on edit the quote already knows its customer. A merge of
     * two branches gets exactly these wrong, and gets them wrong silently.
     */
    public function eachQuoteModeCarriesOnlyItsOwnHiddenFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);
        $company = $this->makeCompany($I);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->seeElement('form#estimate-form input[type="hidden"][name="version"]');
        $I->assertSame(
            [(string) $estimate->getVersion()],
            $I->grabMultiple('form#estimate-form input[name="version"]', 'value'),
        );
        $I->dontSeeElement('form#estimate-form input[type="hidden"][name="company_id"]');
        $I->assertStringContainsString(
            '/admin/estimate/edit/' . $estimate->getId(),
            (string) $I->grabAttributeFrom('form#estimate-form', 'action'),
        );

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeElement('form#estimate-form input[type="hidden"][name="company_id"]');
        $I->assertSame(
            [(string) $company->getId()],
            $I->grabMultiple('form#estimate-form input[name="company_id"]', 'value'),
        );
        $I->dontSeeElement('form#estimate-form input[type="hidden"][name="version"]');
        $I->assertStringContainsString(
            '/admin/estimate/create',
            (string) $I->grabAttributeFrom('form#estimate-form', 'action'),
        );
    }

    /**
     * The create mode still MINTS a quote, with its lines, its address snapshot and a charge row
     * added from the bar — read back out of estimate, estimate_line and estimate_address by column.
     *
     * This is the test the collapse needed: a merged section that renders perfectly and posts one
     * field fewer is exactly the failure a screenshot cannot see.
     */
    public function theQuoteCreateModeStillMintsAQuoteFromTheMergedSection(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();
        $highWaterMark = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM estimate');

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        // Every scalar this POST carries, asserted to be a control the merged section renders.
        $this->seeTheFormPosts($I, 'form#estimate-form', [
            'company_id', 'fulfillment_region', 'po_number', 'quote_date', 'special_instructions',
            'primary_email', 'company_phone',
            'lines[0][product_id]', 'lines[0][qty]', 'lines[0][price]',
            'billing_address_id', 'billing_company_name', 'billing_first_name', 'billing_last_name',
            'billing_address_1', 'billing_city', 'billing_country', 'billing_province',
            'billing_postal_code', 'shipping_address_id',
            'charge_lines_present', 'charge_line_type', 'add_charge_line', 'save_mode',
        ]);
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'Shared Region',
            'po_number' => 'PO-MERGED-1',
            'quote_date' => '2026-09-12',
            'special_instructions' => 'Deliver to the merged section',
            'primary_email' => 'merged@shared.example',
            'company_phone' => '604-555-7001',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '3', 'price' => '40.00'],
            ],
            'billing_address_id' => '',
            'billing_company_name' => 'Merged Billing Co',
            'billing_first_name' => 'Merge',
            'billing_last_name' => 'Test',
            'billing_address_1' => '7 Merge Street',
            'billing_city' => 'Nanaimo',
            'billing_country' => 'CA',
            'billing_province' => 'BC',
            'billing_postal_code' => 'V9R1A1',
            'shipping_address_id' => '',
            'charge_lines_present' => '1',
            'charge_lines' => [
                ['label' => 'Custom Shipping', 'amount' => '15.00', 'type' => 'shipping'],
            ],
            'save_mode' => 'draft',
        ]);

        $row = $connection->fetchAssociative('SELECT * FROM estimate WHERE id > ? ORDER BY id DESC LIMIT 1', [$highWaterMark]);
        $I->assertIsArray($row, 'the create mode minted no quote');

        $I->assertSame((string) $company->getId(), (string) $row['company_id']);
        $I->assertSame('PO-MERGED-1', $row['po_number'], 'the PO # box no longer posts');
        $I->assertSame('2026-09-12', $row['document_date'], 'the Quote Date box no longer posts');
        $I->assertSame('Deliver to the merged section', $row['special_instructions']);
        $I->assertSame('Shared Region', $row['fulfillment_region']);
        $I->assertSame('Draft', $row['status']);

        $lines = $connection->fetchAllAssociative('SELECT * FROM estimate_line WHERE estimate_id = ? ORDER BY id', [$row['id']]);
        $I->assertCount(1, $lines, 'the merged line table posted a different number of rows than it showed');
        $I->assertSame((string) $product->getId(), (string) $lines[0]['product_id']);
        $I->assertSame(3.0, (float) $lines[0]['quantity']);
        $I->assertSame(40.0, (float) $lines[0]['price']);

        $billing = $this->addressRow($I, 'estimate_address', (int) $row['id'], 'estimate_id', 'billing');
        $I->assertSame('Merged Billing Co', $billing['company_name']);
        $I->assertSame('7 Merge Street', $billing['address_line1']);
        $I->assertSame('Nanaimo', $billing['city']);

        // The charge row the shared bar posts, on the very first save — the thing create used to be
        // unable to do at all, because the bar was missing from the create branch.
        $feeLines = json_decode((string) $row['fee_lines'], true);
        $I->assertIsArray($feeLines);
        $shipping = array_values(array_filter($feeLines, static fn (array $l): bool => ($l['type'] ?? '') === 'shipping'));
        $I->assertCount(1, $shipping, 'the charge row posted from the create page was dropped');
        $I->assertSame('Custom Shipping', $shipping[0]['label']);
        $I->assertSame(15.0, (float) $shipping[0]['amount']);
    }

    /** And the edit mode still saves its lines through the same merged section. */
    public function theQuoteEditModeStillSavesItsLinesFromTheMergedSection(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $estimate = $this->makeEstimate($I);
        $product = $this->makeProduct($I);
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $this->seeTheFormPosts($I, 'form#estimate-form', [
            'version', 'fulfillment_region', 'po_number',
            'lines[0][product_id]', 'lines[0][qty]', 'lines[0][price]', 'action',
        ]);
        $I->sendFormPostRequest('/admin/estimate/edit/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
            'version' => (string) $estimate->getVersion(),
            'fulfillment_region' => 'Shared Region',
            'po_number' => 'PO-MERGED-EDIT',
            'lines' => [
                ['product_id' => (string) $product->getId(), 'qty' => '5', 'price' => '11.00'],
                ['product_id' => (string) $product->getId(), 'qty' => '7', 'price' => '13.00'],
            ],
            'action' => 'save',
        ]);

        $row = $connection->fetchAssociative('SELECT * FROM estimate WHERE id = ?', [$estimate->getId()]);
        $I->assertIsArray($row);
        $I->assertSame('PO-MERGED-EDIT', $row['po_number']);

        $lines = $connection->fetchAllAssociative('SELECT * FROM estimate_line WHERE estimate_id = ? ORDER BY id', [$estimate->getId()]);
        $I->assertCount(2, $lines, 'the second row the merged table posted was dropped');
        $I->assertSame([5.0, 7.0], array_map(static fn (array $l): float => (float) $l['quantity'], $lines));
        $I->assertSame([11.0, 13.0], array_map(static fn (array $l): float => (float) $l['price'], $lines));
    }

    // ------------------------------------------------- 3. the fee line row

    /**
     * The fee row spans its document's whole line table — 14 cells on an order, 13 on a quote —
     * and the count is read off the table's own header row rather than written down here, so a
     * column added to one table without the fee row following it fails.
     */
    public function theFeeRowSpansEveryColumnOfEachDocumentsLineTable(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $headers = \count($I->grabMultiple('table.order-lines-table thead tr th'));
        $I->assertSame(14, $headers, 'the order line table is no longer 14 columns wide');
        $I->assertSame(
            $headers,
            \count($I->grabMultiple('tr.order-line-row.fee-line-row td')),
            'the order fee row no longer spans its table',
        );

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $headers = \count($I->grabMultiple('table.estimate-line-table thead tr th'));
        $I->assertSame(13, $headers, 'the quote line table is no longer 13 columns wide');
        $I->assertSame(
            $headers,
            \count($I->grabMultiple('tr.estimate-line-row.fee-line-row td')),
            'the quote fee row no longer spans its table',
        );
    }

    /**
     * The fee's name and its money, paired, each read out of the cell that carries it.
     *
     * On the quote that is by data-label; on the order, which labels no cell in its line table,
     * it is by position — and the position is the one the shared row was told to put the amount in.
     */
    public function theFeeRowNamesTheFeeAndStatesItsMoney(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        $cells = array_map(trim(...), $I->grabMultiple('tr.order-line-row.fee-line-row td'));
        $I->assertSame('BC Recycling Fee', $cells[0], 'the fee is not named in the first cell');
        $I->assertSame('$4.50', $cells[11], 'the money is not in the Subtotal cell');
        $I->assertSame('—', $cells[12], 'the Batch cell is not dash-filled');

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $names = array_map(trim(...), $I->grabMultiple('tr.estimate-line-row.fee-line-row td[data-label="Name"]'));
        $amounts = array_map(trim(...), $I->grabMultiple('tr.estimate-line-row.fee-line-row td[data-label="Subtotal"]'));
        $I->assertSame(['BC Recycling Fee'], $names);
        $I->assertSame(['$4.50'], $amounts);
    }

    /**
     * The quote labels every cell of the fee row for the responsive stacked table; the order labels
     * none of them, anywhere in its line table. Both halves are asserted, because a shared row that
     * quietly started labelling the order's cells would change how the order's table stacks on a
     * phone and nothing else would say so.
     */
    public function theQuoteLabelsEveryFeeCellAndTheOrderLabelsNone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $order = $this->makeOrder($I);
        $estimate = $this->makeEstimate($I);

        $I->amOnPage('/admin/estimate/edit/' . $estimate->getId());
        $I->assertSame(
            \count($I->grabMultiple('tr.estimate-line-row.fee-line-row td')),
            \count($I->grabMultiple('tr.estimate-line-row.fee-line-row td[data-label]')),
            'a quote fee cell lost its data-label',
        );

        $I->amOnPage('/admin/order/edit/' . $order->getId());
        // Positive control on the same row: it is there, it just carries no labels.
        $I->assertNotSame([], $I->grabMultiple('tr.order-line-row.fee-line-row td'));
        $I->assertSame([], $I->grabMultiple('tr.order-line-row.fee-line-row td[data-label]'));
    }

    /**
     * Invoice and Quote both got their own /fee-lines and /tax-breakdown AJAX endpoints
     * (#full-parity, 2026-09-13) — Order's own previewOrderFromRequest()/feeLinesAjax()/
     * taxBreakdownAjax(), ported verbatim to InvoiceController/EstimateController rather than
     * reinvented. Proved here by giving all three documents the identical input and asserting
     * byte-identical computed output — a real preview against real tax rules, not a stub.
     */
    public function theThreeDocumentsFeeAndTaxPreviewEndpointsAgree(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I);
        $product = $this->makeProduct($I);

        $lines = json_encode([['product_id' => (string) $product->getId(), 'qty' => 2, 'subtotal' => 20, 'tax_code' => 'G']], JSON_THROW_ON_ERROR);
        $query = [
            'company_id' => (string) $company->getId(),
            'address_id' => '0',
            'province' => 'BC',
            'shipping' => '5',
            'lines' => $lines,
        ];

        $responses = [];
        foreach (['order', 'invoice', 'estimate'] as $doc) {
            $I->amOnPage('/admin/' . $doc . '/tax-breakdown?' . http_build_query($query));
            $I->seeResponseCodeIsSuccessful();
            $responses[$doc] = $I->grabPageSource();
        }

        $I->assertSame($responses['order'], $responses['invoice'], 'invoice tax-breakdown disagrees with order for the same input');
        $I->assertSame($responses['order'], $responses['estimate'], 'estimate tax-breakdown disagrees with order for the same input');

        $decoded = json_decode($responses['order'], true, 512, JSON_THROW_ON_ERROR);
        $I->assertNotEmpty($decoded['lines'], 'a real tax calculator ran; this is not an empty stub response');
    }
}
