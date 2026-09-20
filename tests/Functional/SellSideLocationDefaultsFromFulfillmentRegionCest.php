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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * "Header sets a default, the line overrides it" for `location` across Order, Invoice and Estimate.
 *
 * `location` (per line) and `fulfillmentRegion` (per document header) are the same value domain —
 * both name a `FulfillmentRegion` — captured at two levels. Before this, the header value had no
 * bearing on a blank line's default at all: order's blank line stayed genuinely blank (no location
 * assigned), and invoice's/estimate's fell back to the literal hard-coded string `'Main'`
 * regardless of what the document's own header actually said. A company whose only active region
 * was, say, 'East' got a new invoice/estimate line silently defaulting to 'Main' — a region that
 * company may not even have.
 *
 * The fix (`sales_line_row.html.twig`'s `documentFulfillmentRegion` param, and the matching
 * `?? $document->getFulfillmentRegion()` fallback in each of OrderController::edit()/create(),
 * InvoiceController::applyLineRows() and EstimateController::applyLinesFromRequest()) only applies
 * to a line that did not exist before the save (no `id`) — an EXISTING order/estimate line's
 * genuine blank is left alone on an unrelated re-save, never silently backfilled. Invoice is the
 * one document where that distinction doesn't apply: applyLineRows() rebuilds every line from
 * scratch on every save (SellSideBatchCaptureAcrossDocumentsCest's own finding), so there is no
 * "existing line" to protect there — a blank location on ANY invoice line, new or not, always
 * defaults to the header.
 *
 * Every scenario below runs against a company with TWO active fulfillment regions, never one — a
 * company with only one region auto-resolves it whether or not this feature exists, which would
 * prove nothing. Two regions forces an explicit header choice, and the line default has to follow
 * THAT choice specifically, not just happen to land on a name that was already the only option.
 */
final class SellSideLocationDefaultsFromFulfillmentRegionCest
{
    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('location-default-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ---------------------------------------------------------------- fixtures

    private function makeCompanyWithTwoRegions(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Location Default Co')
            ->setCode('LDC-' . uniqid())
            ->setPrimaryEmail('ap@location-default.example');
        $I->haveInRepository($company);

        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Bill')->setCompanyName('Location Default Co')
            ->setFirstName('Bill')->setLastName('Payer')->setAddressLine1('1 Billing Way')
            ->setCity('Toronto')->setProvince('ON')->setCountry('CA')->setPostalCode('M4B1B3')
            ->setIsDefaultBilling(true));
        $I->haveInRepository((new CompanyAddress())
            ->setCompany($company)->setLabel('Ship')->setCompanyName('Location Default Co')
            ->setFirstName('Ship')->setLastName('Receiver')->setAddressLine1('2 Shipping Road')
            ->setCity('Toronto')->setProvince('ON')->setCountry('CA')->setPostalCode('M4B1B4')
            ->setIsDefaultShipping(true));

        // Two DISTINCT active regions — see class docblock for why one alone would prove nothing.
        $I->haveActiveFulfillmentRegionFor($company, 'East');
        $I->haveActiveFulfillmentRegionFor($company, 'West');

        return $company;
    }

    private function makeProduct(FunctionalTester $I, string $skuPrefix): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($skuPrefix . '-' . uniqid())
            ->setName('Location Default Widget ' . $skuPrefix)
            ->setUnit('EA')
            ->setSalesTaxCode('G')
            ->setCostPrice('4.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em = $I->grabService(EntityManagerInterface::class);
        $em->persist($product);
        $em->flush();

        $I->haveStockFor($product, 500, 'East');
        $I->haveStockFor($product, 500, 'West');

        return $product;
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    // -------------------------------------------------------------- 1. order: create

    public function theOrderCreateScreenDefaultsABlankLinesLocationToWhicheverRegionWasPickedAtTheHeader(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithTwoRegions($I);
        $productBlank = $this->makeProduct($I, 'ORD-BLANK');
        $productOverride = $this->makeProduct($I, 'ORD-OVERRIDE');

        $I->amOnPage('/admin/order/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            // The header choice: 'West', deliberately NOT the alphabetically-first region and NOT
            // 'Main' — so a leftover hard-coded fallback would be caught red-handed.
            'fulfillment_region' => 'West',
            'lines' => [
                [
                    'product_id' => (string) $productBlank->getId(),
                    // No `location` key at all — the shape a genuinely untouched row posts.
                    'qty' => '3', 'price' => '10.00', 'tax_code' => 'G',
                ],
                [
                    'product_id' => (string) $productOverride->getId(),
                    // The line overrides the header explicitly.
                    'location' => 'East',
                    'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
                ],
            ],
            'save_mode' => 'order',
        ]);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT sol.product_id, sol.location FROM sales_order_line sol JOIN sales_order so ON so.id = sol.order_id WHERE so.company_id = ? ORDER BY sol.sort_order ASC',
            [$company->getId()],
        );
        $I->assertCount(2, $rows, 'both lines were raised');
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row['location'];
        }
        $I->assertSame('West', (string) $byProduct[$productBlank->getId()], "the blank line's location — the header's own choice, not a hard-coded guess");
        $I->assertSame('East', (string) $byProduct[$productOverride->getId()], "the other line's own explicit override wins over the header");
    }

    // -------------------------------------------------------------- 2. order: edit — existing line untouched, new line defaulted

    /**
     * One save, two lines with different needs (per the earlier batch-capture tests' own pattern):
     * an EXISTING line whose location was deliberately cleared to None before this test even starts
     * — proving a re-save that never touches it does not quietly backfill the header region into it
     * — alongside a brand-new line added in that SAME edit, which DOES get the header default. If
     * the implementation applied the fallback unconditionally (dropping the `id` check), the first
     * assertion is exactly the one that would catch it.
     */
    public function editingAnOrderLeavesAnExistingLinesDeliberateBlankAloneWhileDefaultingTheNewLineAddedInTheSameSave(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithTwoRegions($I);
        $existingProduct = $this->makeProduct($I, 'ORD-EXIST');
        $newProduct = $this->makeProduct($I, 'ORD-NEW');

        $order = (new SalesOrder())
            ->setCompany($company)->setOrderNumber('LDC-ORD-' . uniqid())
            ->setFulfillmentRegion('East')
            ->setSubtotal('20.00')->setTax('0.00')->setTotal('20.00');
        $existingLine = (new SalesOrderLine())
            ->setProduct($existingProduct)->setName($existingProduct->getName())->setSku((string) $existingProduct->getSku())
            ->setUnit('EA')->setTaxCode('G')->setQuantity('2.00')->setPrice('10.00')->setSubtotal('20.00')
            ->setLocation(null); // deliberately no location — this is the state being protected.
        $order->addLine($existingLine);
        $I->haveInRepository($order);

        $editUrl = '/admin/order/edit/' . $order->getId();
        $I->amOnPage($editUrl);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($editUrl, [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'East',
            'lines' => [
                [
                    // Named by id: an UPDATE to the existing line, location left blank — not touched.
                    'id' => (string) $existingLine->getId(),
                    'product_id' => (string) $existingProduct->getId(),
                    'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
                ],
                [
                    // No id: a brand-new line added in this same edit.
                    'product_id' => (string) $newProduct->getId(),
                    'qty' => '1', 'price' => '10.00', 'tax_code' => 'G',
                ],
            ],
            'save_mode' => 'draft_recalc',
        ]);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT sol.product_id, sol.location FROM sales_order_line sol WHERE sol.order_id = ? ORDER BY sol.sort_order ASC',
            [$order->getId()],
        );
        $I->assertCount(2, $rows, 'the existing line stayed and the new one was added — neither dropped');
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row['location'];
        }
        $I->assertNull($byProduct[$existingProduct->getId()], "the existing line's deliberate blank survives a re-save that never touched it");
        $I->assertSame('East', (string) $byProduct[$newProduct->getId()], "the line added in this same save gets the document's own region");
    }

    // -------------------------------------------------------------- 3. invoice: create — default and override

    public function theStandaloneInvoiceCreateScreenDefaultsABlankLinesLocationToTheHeaderRegionAndOverrideStillWins(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithTwoRegions($I);
        $productBlank = $this->makeProduct($I, 'INV-BLANK');
        $productOverride = $this->makeProduct($I, 'INV-OVERRIDE');

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'West',
            'save_mode' => 'draft',
            'lines' => [
                [
                    'product_id' => (string) $productBlank->getId(),
                    'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
                ],
                [
                    'product_id' => (string) $productOverride->getId(),
                    'location' => 'East',
                    'qty' => '1', 'price' => '10.00', 'tax_code' => 'G',
                ],
            ],
        ]);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT il.product_id, il.location FROM invoice_line il JOIN invoice i ON i.id = il.invoice_id WHERE i.company_id = ? ORDER BY il.sort_order ASC',
            [$company->getId()],
        );
        $I->assertCount(2, $rows, 'both invoice lines were raised');
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row['location'];
        }
        $I->assertSame('West', (string) $byProduct[$productBlank->getId()], "the blank invoice line's location — the header's own choice");
        $I->assertSame('East', (string) $byProduct[$productOverride->getId()], "the other invoice line's own explicit override wins");
    }

    // -------------------------------------------------------------- 4. invoice: edit — every line always defaults, none protected

    /**
     * The sharper invoice-specific case: because applyLineRows() rebuilds every line from scratch on
     * every save (there is no server-side notion of "the existing row" to compare an id against —
     * confirmed in SellSideBatchCaptureAcrossDocumentsCest), a blank location on re-save is NOT
     * protected the way order's/estimate's existing lines are. A line that had an explicit location
     * before this edit but posts blank on the re-save loses it to the header default too — the same
     * "not merely un-settable, erased on the next unrelated save" shape that test file already
     * proved for `batch`.
     */
    public function reSavingAnInvoiceDefaultsEveryBlankLineToTheHeaderRegionEvenOneThatHadAnExplicitLocationBefore(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithTwoRegions($I);
        $product = $this->makeProduct($I, 'INV-RESAVE');

        $I->amOnPage('/admin/invoice/create?company_id=' . $company->getId());
        $I->sendFormPostRequest('/admin/invoice/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'East',
            'save_mode' => 'draft',
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'location' => 'East',
                'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
            ]],
        ]);

        $invoiceId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM invoice WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$company->getId()],
        );
        $lineId = (int) $this->connection($I)->fetchOne('SELECT id FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $before = $this->connection($I)->fetchOne('SELECT location FROM invoice_line WHERE id = ?', [$lineId]);
        $I->assertSame('East', (string) $before, 'guard: the line really did start with an explicit location');

        $editUrl = '/admin/invoice/edit/' . $invoiceId;
        $I->amOnPage($editUrl);
        $I->sendFormPostRequest($editUrl, [
            '_token' => $I->csrfToken(),
            'fulfillment_region' => 'West',
            'save_mode' => 'draft',
            'lines' => [[
                'id' => (string) $lineId,
                'product_id' => (string) $product->getId(),
                // Location left blank on the re-save.
                'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
            ]],
        ]);

        $after = $this->connection($I)->fetchOne('SELECT location FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertSame('West', (string) $after, "the line's prior explicit location did not survive the rebuild — it fell back to this save's header region, same as a brand-new line would");
    }

    // -------------------------------------------------------------- 5. estimate: create and edit

    public function theEstimateCreateScreenDefaultsABlankLinesLocationToTheHeaderRegionAndOverrideStillWins(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithTwoRegions($I);
        $productBlank = $this->makeProduct($I, 'EST-BLANK');
        $productOverride = $this->makeProduct($I, 'EST-OVERRIDE');

        $I->amOnPage('/admin/estimate/create?company_id=' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/estimate/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'fulfillment_region' => 'West',
            'save_mode' => 'draft',
            'lines' => [
                [
                    'product_id' => (string) $productBlank->getId(),
                    // Explicit blank — the shape a real `<select>` always posts (estimate's own
                    // applyLinesFromRequest() only touches a field the submission actually names a
                    // key for, array_key_exists('location', $row), so an omitted key would test
                    // "field never posted" rather than "field posted blank").
                    'location' => '',
                    'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
                ],
                [
                    'product_id' => (string) $productOverride->getId(),
                    'location' => 'East',
                    'qty' => '1', 'price' => '10.00', 'tax_code' => 'G',
                ],
            ],
        ]);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT el.product_id, el.location FROM estimate_line el JOIN estimate e ON e.id = el.estimate_id WHERE e.company_id = ? ORDER BY el.sort_order ASC',
            [$company->getId()],
        );
        $I->assertCount(2, $rows, 'both estimate lines were raised');
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row['location'];
        }
        $I->assertSame('West', (string) $byProduct[$productBlank->getId()], "the blank estimate line's location — the header's own choice");
        $I->assertSame('East', (string) $byProduct[$productOverride->getId()], "the other estimate line's own explicit override wins");
    }

    public function editingAnEstimateLeavesAnExistingLinesDeliberateBlankAloneWhileDefaultingTheNewLineAddedInTheSameSave(FunctionalTester $I): void
    {
        $company = $this->makeCompanyWithTwoRegions($I);
        $existingProduct = $this->makeProduct($I, 'EST-EXIST');
        $newProduct = $this->makeProduct($I, 'EST-NEW');

        $estimate = (new Estimate())
            ->setCompany($company)->setDocumentNumber('LDC-EST-' . uniqid())->setSource('Admin')
            ->setFulfillmentRegion('East')
            ->setSubtotal('20.00')->setTax('0.00')->setTotal('20.00');
        $estimate->setStatus('Priced', DocumentActor::system());
        $existingLine = (new EstimateLine())
            ->setProduct($existingProduct)->setName($existingProduct->getName())->setSku((string) $existingProduct->getSku())
            ->setUnit('EA')->setTaxCode('G')->setQuantity('2.00')->setCost('4.00')->setPrice('10.00')->setSubtotal('20.00')
            ->setLocation(null);
        $estimate->addLine($existingLine);
        $I->haveInRepository($estimate);

        $editUrl = '/admin/estimate/edit/' . $estimate->getId();
        $I->amOnPage($editUrl);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest($editUrl, [
            '_token' => $I->csrfToken(),
            'fulfillment_region' => 'East',
            'save_mode' => 'draft',
            'lines' => [
                [
                    'id' => (string) $existingLine->getId(),
                    'product_id' => (string) $existingProduct->getId(),
                    // No `location` key at all — "leave this field alone", which is exactly the
                    // deliberate-blank state being protected here.
                    'qty' => '2', 'price' => '10.00', 'tax_code' => 'G',
                ],
                [
                    'product_id' => (string) $newProduct->getId(),
                    // A real `<select>` on a brand-new row still posts a value, blank or not.
                    'location' => '',
                    'qty' => '1', 'price' => '10.00', 'tax_code' => 'G',
                ],
            ],
        ]);

        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT el.product_id, el.location FROM estimate_line el WHERE el.estimate_id = ? ORDER BY el.sort_order ASC',
            [$estimate->getId()],
        );
        $I->assertCount(2, $rows, 'the existing line stayed and the new one was added');
        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row['location'];
        }
        $I->assertNull($byProduct[$existingProduct->getId()], "the existing estimate line's deliberate blank survives a re-save that never touched it");
        $I->assertSame('East', (string) $byProduct[$newProduct->getId()], "the line added in this same save gets the document's own region");
    }
}
