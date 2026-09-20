<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\UnitOfMeasure;
use App\Service\DocumentActor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The gaps AdminStandaloneInvoiceCest leaves, found while reviving that branch onto main.
 *
 * That file conducts the create screen thoroughly and is not repeated here. What it could not
 * cover is everything the branch was stale against or silent about:
 *
 *  - **The U/M selector (#659).** The branch shipped a free-text U/M box and called
 *    `applyLineQuantity()` with the pre-#659 four-argument shape, so every save died with an
 *    ArgumentCountError. Its own tests never caught the DENOMINATION question underneath, because
 *    every row they post is in base units. A line said in boxes is the case that distinguishes a
 *    real conversion from a shortcut.
 *  - **The tax province.** Every customer in that file has an Ontario address, so a standalone
 *    invoice with taxable lines and NO province was never posted — and it was silently taxed $0.
 *  - **The #539 invariant.** "Every order has exactly one invoice" is asserted nowhere against an
 *    order-less one.
 *  - **The order-rooted path.** Without a regression control every case above passes just as well
 *    on a build that broke ordinary invoicing.
 *
 * ## Conducted (#624)
 *
 * Real screens, plain form POSTs carrying a scraped CSRF token, fixtures this file makes itself,
 * and every figure read back out of the database BY COLUMN after the POST. An entity left in memory
 * by the request proves what was computed, not what was stored.
 *
 * ## Assertions (#627)
 *
 * No figure is asserted with see(): `see('50')` matches '1050'. Money and quantities are compared as
 * stored columns. The one place a page is asserted on — the province refusal — is anchored to
 * `.form-error-banner` and paired with a positive control on that SAME element.
 *
 * ## Shared instance
 *
 * Codeception reuses one Cest instance across methods, so every property this file keeps is
 * reassigned in `_before()` rather than merely initialised once.
 */
final class AdminStandaloneInvoiceRevivalCest
{
    private Company $company;
    private ProductCore $product;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('standalone-revival@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // Reassigned every test, not initialised once: one Cest instance serves them all, and a
        // company left over from the previous method belongs to an entity manager that is gone.
        $this->company = (new Company())
            ->setName('Revival Wholesale')
            ->setCode('RVW-' . uniqid())
            ->setPrimaryEmail('ap@revival.example');
        $I->haveInRepository($this->company);

        $this->product = (new ProductCore())
            ->setSku('RVW-SKU-' . uniqid())
            ->setName('Revival Widget')
            ->setSalesTaxCode('G')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->product);
    }

    /**
     * A line said in BOXES stores the base quantity, the entered quantity and the unit it was said
     * in — the #659 fix this revival exists for.
     *
     * The figures are chosen so no two of them coincide: 4 boxes of 12 is 48 eaches, and $120.00 a
     * box is $10.00 an each. A shortcut that ignored the unit would store 4 and $120.00 and every
     * one of the four column assertions below would fail, which is the point of picking them.
     */
    public function aLineSaidInBoxesStoresTheBaseQuantityTheEnteredOneAndItsUnit(FunctionalTester $I): void
    {
        $this->giveTheCompanyAnOntarioAddress($I);
        $box = $this->availableUnit($I, 'RVWBOX12', '12');

        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, [
            'save_mode' => 'draft',
            'invoice_date' => '2026-09-12',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '4', 'unit_id' => (string) $box->getId(), 'price' => '120.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ]);

        $invoiceRow = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertNull($invoiceRow['sales_order_id'], 'still a standalone invoice');

        $lineRows = $this->lineRowsFor($I, (int) $invoiceRow['id']);
        $I->assertCount(1, $lineRows);

        // The BASE figure, which is what the stock hold, OverInvoicingGuard and every total are
        // denominated in. 4 x 12.
        $I->assertSame('48.0000', $this->quantity($lineRows[0]['quantity']), '4 boxes of 12 is 48 base units');
        // And what the admin actually typed, kept beside it so the document prints what was said.
        $I->assertSame('4.0000', $this->quantity($lineRows[0]['quantity_entered']), 'the entered figure is kept as typed');
        $I->assertSame((string) $box->getId(), (string) $lineRows[0]['unit_id'], 'the row records the unit it was said in');

        // Per BASE unit: $120.00 a box of 12 is $10.00 an each. Stored at the column's six places,
        // because two cannot hold a converted rate at all (#645/#659).
        $I->assertSame(10.0, round((float) $lineRows[0]['price'], 6), '$120.00 per box of 12 is $10.00 per each');
        $I->assertSame('480.00', $this->money($lineRows[0]['subtotal']), '48 base units at $10.00');
        $I->assertSame('480.00', $this->money($invoiceRow['subtotal']), 'the document subtotal follows the line');
    }

    /**
     * The stock ceiling weighs a boxed line as BASE units.
     *
     * The hole this closes is specific and silent: validating the typed 4 against a shelf holding 10
     * would wave the save through, and the invoice would then HOLD 48 — twelve times the stock that
     * was checked, driving availability negative, which is exactly the #326 failure
     * AdminOrderStockValidator exists to refuse.
     *
     * Both halves are asserted on the same product and the same shelf, so the second is a genuine
     * positive control on the first rather than a different arrangement that happens to pass.
     */
    public function theStockCeilingWeighsABoxedLineInBaseUnits(FunctionalTester $I): void
    {
        // Region and stock FIRST, while the objects _before() created are still managed: both
        // helpers write through the entity they are handed, and availableUnit() below clears the
        // entity manager — after which handing either one over makes Doctrine read a detached
        // object as a brand-new row to cascade-persist.
        $I->haveActiveFulfillmentRegionFor($this->company, 'Main');
        $I->haveStockFor($this->product, 10, 'Main');

        $this->giveTheCompanyAnOntarioAddress($I);
        $box = $this->availableUnit($I, 'RVWBOX12', '12');

        // 4 boxes is 48 eaches against 10 on the shelf: refused.
        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, $this->boxedLine($box, '4', 'issue'));

        $I->seeElement('.form-error-banner');
        $I->assertSame(
            $highWaterMark,
            $this->highestInvoiceId($I),
            'the refusal wrote no invoice row and drew no invoice number',
        );

        // POSITIVE CONTROL: the same product, the same shelf, the same screen and the same element
        // — only the quantity changes. 1 box is 12 eaches against 10, still short, so the figure is
        // dropped to a quantity the shelf covers rather than to something that is not a line at all.
        $this->postInvoice($I, $this->boxedLine($box, '0.5', 'issue'));

        $I->dontSeeElement('.form-error-banner');
        $saved = $this->newestInvoiceRow($I, $highWaterMark);
        // The column, not the page: half a box of 12 is 6 base units, and 6 fits in 10.
        $I->assertSame('6.0000', $this->quantity($this->lineRowsFor($I, (int) $saved['id'])[0]['quantity']));
        $I->assertNull($saved['sales_order_id'], 'and it is still a standalone invoice');
    }

    /**
     * Taxable goods with no province to tax them in are REFUSED, and the same invoice saves once a
     * province exists.
     *
     * Without this an invoice for a customer with no shipping address is billed $0.00 tax, and a $0
     * derived from nothing is indistinguishable on the document from a genuine zero — the defect
     * PurchaseOrder::assertTaxProvinceKnownIfTaxable() closes on the buy side.
     *
     * The positive control is deliberately the SAME element on the SAME screen for the SAME
     * customer and the same posted line: only the address changes between the two halves.
     */
    public function taxableLinesWithNoProvinceAreRefusedAndSaveOnceOneExists(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);

        // No address at all, so the invoice can freeze no province onto itself.
        $this->postInvoice($I, $this->taxableLine('G'));

        $I->seeElement('.form-error-banner');
        // Anchored to the banner, and to a phrase rather than a figure: the refusal has to say what
        // to do about it, which is the half that makes it useful rather than merely correct.
        $I->see('has no shipping address on file', '.form-error-banner');
        $I->assertSame(
            $highWaterMark,
            $this->highestInvoiceId($I),
            'nothing was written and no invoice number was burnt',
        );

        // POSITIVE CONTROL, same element: give the customer a province and the same post succeeds.
        $this->giveTheCompanyAnOntarioAddress($I);
        $this->postInvoice($I, $this->taxableLine('G'));

        $I->dontSeeElement('.form-error-banner');
        $savedRow = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertNull($savedRow['sales_order_id']);
        $I->assertSame('100.00', $this->money($savedRow['subtotal']), '10 at $10.00');
        $I->assertSame('13.00', $this->money($savedRow['tax']), 'Ontario HST really ran; this is not a $0 from nothing');
    }

    /**
     * EXEMPT goods with no province are let through, because there is no rate to get wrong.
     *
     * The other side of the guard above, and the same decision the purchase order took: refusing
     * every province-less invoice would block a legitimate document to protect a calculation that
     * was never going to run.
     */
    public function exemptGoodsWithNoProvinceAreStillAllowed(FunctionalTester $I): void
    {
        $highWaterMark = $this->highestInvoiceId($I);

        $this->postInvoice($I, $this->taxableLine('E'));

        $I->dontSeeElement('.form-error-banner');
        $row = $this->newestInvoiceRow($I, $highWaterMark);
        $I->assertSame('100.00', $this->money($row['subtotal']));
        $I->assertSame('0.00', $this->money($row['tax']), 'exempt goods are $0 because they are exempt, not because nobody asked');
    }

    /**
     * A standalone invoice joins NO order's invoice count — #539's invariant, confirmed rather than
     * assumed.
     *
     * `SalesOrder::$invoices` is the inverse side of `invoice.sales_order_id`, so an invoice with a
     * NULL FK is in no order's collection and contributes to no count derived from it. That is the
     * reasoning; this is the measurement, taken against a real order that exists at the same time.
     */
    public function aStandaloneInvoiceJoinsNoOrdersInvoiceCount(FunctionalTester $I): void
    {
        $this->giveTheCompanyAnOntarioAddress($I);
        $order = $this->orderRootedInvoice($I);
        $orderId = (int) $order['order_id'];

        $before = $this->invoiceCountForOrder($I, $orderId);
        $I->assertSame(1, $before, 'the order starts with exactly the one invoice raised against it');

        $highWaterMark = $this->highestInvoiceId($I);
        $this->postInvoice($I, $this->taxableLine('G'));
        $standalone = $this->newestInvoiceRow($I, $highWaterMark);

        $I->assertNull($standalone['sales_order_id'], 'the standalone invoice points at no order');
        $I->assertSame(
            1,
            $this->invoiceCountForOrder($I, $orderId),
            'and the order still counts exactly one invoice, so the #539 invariant is untouched',
        );

        // The same question asked the other way, so a count that was right by accident is caught:
        // no invoice row anywhere names this order except the one raised against it.
        $I->assertSame(
            $order['invoice_id'],
            $this->connection($I)->fetchOne('SELECT id FROM invoice WHERE sales_order_id = ?', [$orderId]),
            'the only invoice on that order is still the one that was raised against it',
        );
    }

    /**
     * THE REGRESSION CONTROL. An order-rooted invoice still behaves exactly as it did.
     *
     * Without this every case above passes just as well on a build that broke ordinary invoicing —
     * which is the failure mode that matters, because the order-rooted path is the one in use.
     * Raised through the real Convert to Invoice screen and asserted by column.
     */
    public function anOrderRootedInvoiceStillBehavesExactlyAsBefore(FunctionalTester $I): void
    {
        $this->giveTheCompanyAnOntarioAddress($I);
        $raised = $this->orderRootedInvoice($I);

        $row = $this->invoiceRow($I, (int) $raised['invoice_id']);

        $I->assertSame(
            (string) $raised['order_id'],
            (string) $row['sales_order_id'],
            'the order-rooted invoice still records the order it bills',
        );
        $I->assertSame('Pending', $row['status'], 'and is still issued by the same button');
        $I->assertSame('40.00', $this->money($row['subtotal']), '4 billed of 10 ordered, at $10.00');
        $I->assertSame('5.20', $this->money($row['tax']), 'Ontario HST on the part-invoice, computed from its own lines');
        $I->assertSame('45.20', $this->money($row['total']));

        // The line is still attributed to the order line it bills — what makes uninvoiced quantity
        // derivable per row instead of guessed by matching SKUs afterwards.
        $lineRows = $this->lineRowsFor($I, (int) $raised['invoice_id']);
        $I->assertCount(1, $lineRows);
        $I->assertSame(
            (string) $raised['order_line_id'],
            (string) $lineRows[0]['sales_order_line_id'],
            'the invoice line still claims its order line',
        );
        $I->assertSame('4.0000', $this->quantity($lineRows[0]['quantity']));

        // And the order really was drawn down by it, which is the whole reason the attribution
        // exists. 10 ordered less 4 invoiced.
        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $order = $em->find(SalesOrder::class, (int) $raised['order_id']);
        $I->assertInstanceOf(SalesOrder::class, $order);
        $line = $order->getLines()->first();
        $I->assertSame(
            6.0,
            round((float) $order->uninvoicedQuantityFor($line), 4),
            '6 of the 10 ordered are still left to invoice',
        );
    }

    // ---------------------------------------------------------------- fixtures and readers

    /**
     * The posted rows for one taxable line of 10 at $10.00, at the given tax code.
     *
     * @return array<string, mixed>
     */
    private function taxableLine(string $taxCode): array
    {
        return [
            'save_mode' => 'issue',
            'invoice_date' => '2026-09-12',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => '10', 'unit_id' => '', 'price' => '10.00', 'sku' => '', 'unit' => '', 'tax_code' => $taxCode, 'cost' => '', 'location' => 'Main'],
            ],
        ];
    }

    /**
     * The posted rows for one line said in $box, at $quantity boxes.
     *
     * @return array<string, mixed>
     */
    private function boxedLine(UnitOfMeasure $box, string $quantity, string $saveMode): array
    {
        return [
            'save_mode' => $saveMode,
            'invoice_date' => '2026-09-12',
            'lines' => [
                0 => ['product_id' => (string) $this->product->getId(), 'name' => '', 'qty' => $quantity, 'unit_id' => (string) $box->getId(), 'price' => '120.00', 'sku' => '', 'unit' => '', 'tax_code' => '', 'cost' => '', 'location' => 'Main'],
            ],
        ];
    }

    private function postInvoice(FunctionalTester $I, array $params): void
    {
        $I->amOnPage('/admin/invoice/create?company_id=' . $this->company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/invoice/create', array_merge(
            ['_token' => $I->csrfToken(), 'company_id' => (string) $this->company->getId()],
            $params,
        ));
    }

    /** Gives the customer a default shipping address in Ontario, so a province can be frozen. */
    private function giveTheCompanyAnOntarioAddress(FunctionalTester $I): void
    {
        // Re-read through the Doctrine module's OWN entity manager, not through $this->company: the
        // kernel is rebooted between requests, so the object this Cest has held since _before()
        // belongs to an entity manager that is gone, and the one haveInRepository() writes with
        // would take it for a brand-new Company to cascade-persist.
        $company = $I->grabEntityFromRepository(Company::class, ['id' => $this->company->getId()]);

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Warehouse door')
                ->setCompanyName('Revival Wholesale')
                ->setFirstName('Ship')
                ->setLastName('Ping')
                ->setAddressLine1('3 Receiving Lane')
                ->setCity('Toronto')
                ->setProvince('ON')
                ->setCountry('CA')
                ->setPostalCode('M4B1B5')
                ->setIsDefaultShipping(true)
                ->setIsDefaultBilling(true)
        );
    }

    /**
     * Defines a global term through the real Units of Measure screen and lists it on this file's
     * product, so a line may be said in it.
     *
     * Both halves are needed since #659: the term and its ratio are instance-wide, and WHICH
     * products may be expressed in it is per product — a term that exists but is not listed on the
     * product is refused by the line save, and that scoping is the enhancement.
     */
    private function availableUnit(FunctionalTester $I, string $code, string $factor): UnitOfMeasure
    {
        $I->amOnPage('/admin/product/units-of-measure/new');
        $I->seeResponseCodeIs(200);
        $I->sendAjaxPostRequest('/admin/product/units-of-measure/save', [
            '_token' => $I->grabAttributeFrom('input[name="_token"]', 'value'),
            'id' => 0,
            'code' => $code,
            'name' => 'Box of ' . $factor,
            'family' => UnitOfMeasure::FAMILY_QUANTITY,
            'factor_to_family_base' => $factor,
            'rounding_precision' => '1',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $em->clear();
        $unit = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => $code]);
        $I->assertInstanceOf(UnitOfMeasure::class, $unit, 'the term was created through the screen');

        $product = $em->find(ProductCore::class, (int) $this->product->getId());
        // A product must declare its BASE unit before it can be offered any others: the family is
        // drawn from the base, and ProductAvailableUnitService refuses a product without one. That
        // is the #659 ruling in force, not a fixture detail — "EA" is what this product's quantities
        // are counted in, and the box below is a term on top of it.
        $product->setBaseUnit($this->each($I, $em));
        $em->flush();

        $I->grabService(\App\Service\Uom\ProductAvailableUnitService::class)
            ->apply($product, [(int) $unit->getId()], null);
        $em->flush();
        $em->clear();

        return $em->find(UnitOfMeasure::class, (int) $unit->getId());
    }

    /** The instance-wide "Each" term, created once and reused — other Cests may already have it. */
    private function each(FunctionalTester $I, EntityManagerInterface $em): UnitOfMeasure
    {
        $existing = $em->getRepository(UnitOfMeasure::class)->findOneBy(['code' => 'EA']);
        if ($existing instanceof UnitOfMeasure) {
            return $existing;
        }

        $each = (new UnitOfMeasure())
            ->setCode('EA')
            ->setName('Each')
            ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
            ->setFactorToFamilyBase('1')
            ->setRoundingPrecision('1');
        $em->persist($each);
        $em->flush();

        return $each;
    }

    /**
     * An ordinary order-rooted invoice, raised through the real Convert to Invoice screen: 4 billed
     * of 10 ordered at $10.00.
     *
     * A PART invoice deliberately. One covering the whole order is handed the order's own frozen tax
     * snapshot verbatim — whatever the fixture typed — whereas a part-invoice computes its own tax
     * from its own lines, which is the path a standalone invoice shares and therefore the one a
     * regression control has to watch.
     *
     * @return array{order_id: int, order_line_id: int, invoice_id: int}
     */
    private function orderRootedInvoice(FunctionalTester $I): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $company = $em->find(Company::class, (int) $this->company->getId());
        $product = $em->find(ProductCore::class, (int) $this->product->getId());

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('RVW-ORD-' . uniqid())
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setSku($product->getSku())
                ->setTaxCode('G')
                ->setQuantity('10')
                ->setPrice('10.00')
                ->setSubtotal('100.00')
        );
        foreach ($company->getAddresses() as $address) {
            if ($address instanceof CompanyAddress && $address->isDefaultShipping()) {
                $order->setShippingAddressFrom($address);
                $order->setBillingAddressFrom($address);
            }
        }
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->flush();

        $line = $order->getLines()->first();
        $orderId = (int) $order->getId();
        $orderLineId = (int) $line->getId();

        // admin_order_invoice_new/admin_order_invoice_create were retired into admin_invoice_create
        // taking order_id — see that method's docblock. Same screen, same save, one route.
        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'order_id' => (string) $orderId,
            'save_mode' => 'issue',
            'lines' => [['product_id' => (string) $product->getId(), 'sales_order_line_id' => (string) $orderLineId, 'qty' => '4', 'price' => '10.00']],
        ]);

        $invoiceId = $this->connection($I)->fetchOne('SELECT id FROM invoice WHERE sales_order_id = ?', [$orderId]);
        $I->assertNotFalse($invoiceId, 'the order-rooted invoice was raised through the real screen');

        return ['order_id' => $orderId, 'order_line_id' => $orderLineId, 'invoice_id' => (int) $invoiceId];
    }

    private function invoiceCountForOrder(FunctionalTester $I, int $orderId): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM invoice WHERE sales_order_id = ?',
            [$orderId],
        );
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function highestInvoiceId(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT COALESCE(MAX(id), 0) FROM invoice');
    }

    /** @return array<string, mixed> */
    private function newestInvoiceRow(FunctionalTester $I, int $above): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT * FROM invoice WHERE id > ? ORDER BY id DESC LIMIT 1',
            [$above],
        );
        $I->assertIsArray($row, 'the save wrote an invoice row');

        return $row;
    }

    /** @return array<string, mixed> */
    private function invoiceRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]);
        $I->assertIsArray($row, 'the invoice row is there to read');

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function lineRowsFor(FunctionalTester $I, int $invoiceId): array
    {
        return $this->connection($I)->fetchAllAssociative(
            'SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC',
            [$invoiceId],
        );
    }

    /** Money as a two-place string, so '45.2' and '45.20' compare equal. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** A quantity at the column's four places (#645). */
    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
