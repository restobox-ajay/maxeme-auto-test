<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A DRAFT invoice must not be offered on, or shippable from, the inventory-depth shipment form
 * (#784), conducted per the same #624 discipline as `AdminInvoiceIssueOverInvoicingCest`: plain form
 * POSTs through the real screens, with a scraped CSRF token, and every assertion reads
 * `table.column` back out of the database rather than trusting a flash message or a redirect alone.
 *
 * ## What was wrong
 *
 * `/admin/bundles/inventory-depth/shipments/new` looked up an invoice by number and loaded invoices
 * by id with no status filter at all, and `ShipmentController::create()` recorded a shipment against
 * whatever was posted, again with no status check — a Draft invoice, which holds no claim on stock
 * yet, could be shipped, moving stock from `available` to `sold` under a document nobody had issued.
 *
 * ## What these tests prove
 *
 * The number-lookup picker now refuses to add a Draft invoice at all (`thePickerRefusesToAddADraft
 * InvoiceByNumber`). `create()` refuses it a second time, independently, for the case that actually
 * matters most: a scripted POST that never went through the picker
 * (`postingDirectlyToCreateWithADraftInvoiceIsRefusedAndNothingIsRecorded`) — nothing hidden in the
 * UI is a real guard on its own. And the positive control
 * (`issuingTheInvoiceLetsTheExactSameSubmissionThrough`) is the one that proves this did not
 * over-correct into refusing invoices generally: the exact same POST, against the exact same invoice,
 * succeeds once it is issued.
 */
final class AdminInventoryDepthShipmentDraftInvoiceGuardCest
{
    private const SCREEN = '/admin/bundles/inventory-depth/shipments/new';

    private Company $company;
    private ProductCore $widget;

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('shipment-draft-guard-' . uniqid() . '@example.test')->setStatus('Active');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->company = (new Company())
            ->setName('Draft Guard Wholesale')
            ->setCode('DGW-' . uniqid())
            ->setStatus('Active');
        $I->haveInRepository($this->company);

        $this->widget = (new ProductCore())
            ->setSku('DGW-WID-' . uniqid())
            ->setName('Draft Guard Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($this->widget);
    }

    public function thePickerRefusesToAddADraftInvoiceByNumber(FunctionalTester $I): void
    {
        $context = $this->invoiceWithLine($I, issued: false);

        $I->amOnPage(self::SCREEN . '?invoice_number=' . $context['invoiceNumber']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('is still a draft');
        $I->see('Issue it before shipping against it');
        // Never made it into the "Invoices on this shipment" table — that table only renders once
        // something has actually been added.
        $I->dontSee('Included');
        $I->assertSame('Draft', $this->invoiceStatus($I, $context['invoiceId']), 'the refused add-by-number changed the invoice');
    }

    public function postingDirectlyToCreateWithADraftInvoiceIsRefusedAndNothingIsRecorded(FunctionalTester $I): void
    {
        $context = $this->invoiceWithLine($I, issued: false);

        // Straight past the picker: `?invoice[]=` is what a copied URL, or a scripted request that
        // never went near `invoice_number`, both produce — exactly the case the picker's own refusal
        // above cannot reach.
        $I->amOnPage(self::SCREEN . '?invoice%5B0%5D=' . $context['invoiceId']);
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[method="post"] input[name="_token"]', 'value');

        $I->sendFormPostRequest(self::SCREEN, [
            '_token' => $token,
            'invoice' => [(string) $context['invoiceId']],
            'lines' => [(string) $context['lineId'] => '5'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('is still a draft');
        $I->see('Issue it before shipping against it');

        $I->assertSame(0, $this->shipmentCount($I), 'a shipment was recorded against a draft invoice');
        $I->assertSame('Draft', $this->invoiceStatus($I, $context['invoiceId']), 'the refused POST changed the invoice');
        $I->assertSame(0.0, $this->soldUnits($I), 'stock moved to sold for a shipment that was refused');
    }

    public function issuingTheInvoiceLetsTheExactSameSubmissionThrough(FunctionalTester $I): void
    {
        $context = $this->invoiceWithLine($I, issued: false);

        $I->amOnPage(self::SCREEN . '?invoice%5B0%5D=' . $context['invoiceId']);
        $token = (string) $I->grabAttributeFrom('form[method="post"] input[name="_token"]', 'value');
        $submission = [
            '_token' => $token,
            'invoice' => [(string) $context['invoiceId']],
            'lines' => [(string) $context['lineId'] => '5'],
        ];

        // Guard: the refusal this positive control answers.
        $I->sendFormPostRequest(self::SCREEN, $submission);
        $I->see('is still a draft');
        $I->assertSame(0, $this->shipmentCount($I));

        $this->issue($I, $context['invoiceId']);
        $I->assertSame('Pending', $this->invoiceStatus($I, $context['invoiceId']), 'the invoice did not issue');

        // The exact same submission the guard just refused, unchanged, now succeeds.
        $I->sendFormPostRequest(self::SCREEN, $submission);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('is still a draft');
        $I->assertSame(1, $this->shipmentCount($I), 'the shipment was not recorded once the invoice was issued');
        $I->assertSame('Pending', $this->invoiceStatus($I, $context['invoiceId']), 'shipping changed the invoice status');
    }

    // ------------------------------------------------------------------ fixtures and readers

    /** @return array{invoiceId: int, invoiceNumber: string, lineId: int} */
    private function invoiceWithLine(FunctionalTester $I, bool $issued): array
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $company = $em->find(Company::class, $this->company->getId()) ?? $this->company;
        $widget = $em->find(ProductCore::class, $this->widget->getId()) ?? $this->widget;

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('DGW-SO-' . strtoupper(substr(uniqid(), -8)))
            ->setSubtotal('25.00')
            ->setTax('0.00')
            ->setTotal('25.00');

        $orderLine = (new SalesOrderLine())
            ->setProduct($widget)
            ->setName($widget->getName())
            ->setQuantity('5.00')
            ->setPrice('5.00')
            ->setSubtotal('25.00');
        $order->addLine($orderLine);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->haveInRepository($order);

        $invoiceNumber = 'DGW-INV-' . strtoupper(substr(uniqid(), -8));
        $invoice = (new Invoice())
            ->setCompany($company)
            ->setDocumentNumber($invoiceNumber)
            ->setDocumentDate('2026-09-15');
        $order->addInvoice($invoice);

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($widget)
            ->setName($widget->getName())
            ->setSku((string) $widget->getSku())
            ->setQuantity('5.00');
        $invoice->addLine($invoiceLine);
        $I->haveInRepository($invoice);

        if ($issued) {
            $invoice->issue(DocumentActor::system());
            $em->flush();
        }

        return [
            'invoiceId' => (int) $invoice->getId(),
            'invoiceNumber' => $invoiceNumber,
            'lineId' => (int) $invoiceLine->getId(),
        ];
    }

    /** Presses Issue on the invoice's own screen, exactly as `AdminInvoiceIssueOverInvoicingCest` does. */
    private function issue(FunctionalTester $I, int $invoiceId): void
    {
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->seeResponseCodeIsSuccessful();
        $action = '/admin/invoice/' . $invoiceId . '/action/issue';
        $token = (string) $I->grabAttributeFrom('form[action="' . $action . '"] input[name="_token"]', 'value');

        $I->sendFormPostRequest($action, ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function invoiceStatus(FunctionalTester $I, int $invoiceId): string
    {
        return (string) $this->connection($I)->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoiceId]);
    }

    private function shipmentCount(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM shipment WHERE company_id = ?',
            [$this->company->getId()],
        );
    }

    private function soldUnits(FunctionalTester $I): float
    {
        return (float) $this->connection($I)->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = 'sold'",
            [$this->widget->getId()],
        );
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
