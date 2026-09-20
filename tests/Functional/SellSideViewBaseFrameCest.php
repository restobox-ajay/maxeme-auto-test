<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoiceLineStockOverride;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesOrderLineStockOverride;
use App\Enum\InvoiceIssueIntent;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The FRAME the three sell-side view screens sit in — `admin/_base/commercial_document_view.html.twig`.
 *
 * The three detail screens used to be three hand-built pages that happened to look alike, and every
 * time one of them was touched the other two drifted a little further: the same card under three
 * different inline styles, the page's 50px of bottom margin hung on a different element on each,
 * one of the three quietly growing a wrapper `<div>` that the others never had. Componentising the
 * CONTENTS fixed none of that, because the contents were never where they disagreed.
 *
 * What is pinned here is the FRAME: which regions a sell-side view screen has, and in what order.
 * That order is the sales order's, which is the standard the three are held to.
 *
 * ## How these are written (#624, #627)
 *
 * Conducted: every fixture is built here and the real screens are driven. Nothing asserts a bare
 * word or a bare number — the frame is read as an ORDERED LIST of the headings a person actually
 * sees, compared in full, so a region that moved, vanished or appeared twice fails on the sequence
 * rather than on a `see()` that a stray match elsewhere on the page would satisfy.
 *
 * Every absence is paired with a positive control on the SAME selector: "the invoice has no totals
 * box" is asserted beside "the order has exactly one", using one XPath, so a selector that had
 * stopped matching anything could not pass as the finding.
 */
final class SellSideViewBaseFrameCest
{
    /** The frame's regions, as XPath over the direct children of the admin content frame. */
    private const GRID = '//main[@id="main-content"]/div[@class="order-detail-grid"]';
    private const LINE_ITEMS = '//main[@id="main-content"]/section[contains(@class,"table-card")][div[@class="table-header"]/h2[normalize-space()="Products"]]';
    private const TOTALS_BOX = '//main[@id="main-content"]/div[starts-with(normalize-space(@style),"display:flex; justify-content:flex-end")]';
    private const DOCUMENT_ACTIONS = '//main[@id="main-content"]/section[@class="panel detail-page-card"]';

    public function _before(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('view-base-frame@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    // ── The frame, region by region, on each of the three ────────────────────────────

    /**
     * The order's screen IS the frame — the standard the other two are held to. Every optional
     * region is present on this fixture, so the sequence below is the whole of it.
     */
    public function theOrderScreenRendersTheWholeFrameInOrder(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Frame Order Co');
        $order = $this->order($I, $company, 'FRAME-SO');

        $override = (new SalesOrderLineStockOverride())
            ->setOrderLine($order->getLines()->first())
            ->setRegionName('BC Lower Mainland')
            ->setRequestedQuantity('40')
            ->setAvailableQuantity('-5')
            ->setBackorderCapacity(6)
            ->setReason('Customer accepted a split shipment.')
            ->setOverriddenBy('dana@example.test');
        $order->getLines()->first()->setStockOverride($override);
        $I->haveInRepository($override);
        $entityManager->flush();

        $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        $I->amOnPage('/admin/order/detail/' . $order->getId());

        $I->assertSame(
            [
                'FRAME-SO-1',        // hero
                'Order Info',        // summary_grid · summary_info
                'Billing Detail',    // summary_grid · summary_billing
                'Shipping Detail',   // summary_grid · summary_shipping
                'Products',          // line_items   — FIXED
                'Sold beyond stock', // stock_overrides
                'Message',           // add_message
                'Invoices',          // related_documents
                'Activity Log',      // activity_log
                'Change History',    // change_history
                'Change Detail',     // change_history · the panel's own modal
                'Update Order Status', // page_end
            ],
            $this->frameHeadings($I),
            'the frame is the order screen, and this is it',
        );
    }

    /**
     * The quote's screen is the same frame with three regions absent, and each absence is a fact
     * about a quote rather than a difference of opinion with the order screen: a quote holds no
     * stock so nothing can be sold past the shelf on it, no document is raised FROM it on this page,
     * and it has no status modal because acceptance is the customer's to give.
     */
    public function theQuoteScreenRendersTheSameFrameWithoutTheRegionsAQuoteCannotHave(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Frame Quote Co');
        $estimate = $this->estimate($I, $company, 'FRAME-EST');

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());

        $I->assertSame(
            [
                'FRAME-EST-1',
                'Quote Info',
                'Billing Detail',
                'Shipping Detail',
                'Products',
                'Message',
                'Activity Log',
                'Change History',
                'Change Detail',
            ],
            $this->frameHeadings($I),
        );
    }

    /**
     * The invoice's screen is the same frame again. Its own two differences are both where the frame
     * puts them: its transition buttons in `document_actions`, and its money inside the line table's
     * own foot rather than in the box below — which is why "Products" is followed straight by the
     * timeline here.
     */
    public function theInvoiceScreenRendersTheSameFrame(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Frame Invoice Co');
        $order = $this->order($I, $company, 'FRAME-INV-SO');
        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        $override = (new InvoiceLineStockOverride())
            ->setInvoiceLine($invoice->getLines()->first())
            ->setRegionName('BC Lower Mainland')
            ->setRequestedQuantity('40')
            ->setAvailableQuantity('-5')
            ->setBackorderCapacity(6)
            ->setReason('Shipped short, billed in full by agreement.')
            ->setOverriddenBy(null);
        $invoice->getLines()->first()->setStockOverride($override);
        $I->haveInRepository($override);
        $entityManager->flush();

        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());

        $I->assertSame(
            [
                'INV-1',
                'Invoice Info',
                'Billing Detail',
                'Shipping Detail',
                'Products',
                'Billed beyond stock',
                'Activity Log',
                'Change History',
                'Change Detail',
            ],
            $this->frameHeadings($I),
        );
    }

    // ── The two regions that do not move ─────────────────────────────────────────────

    /**
     * Line items and totals are FIXED, and adjacent: "that block is solid, that's not something
     * that we can just toss around and change", and the fees/shipping/tax/totals under it likewise.
     *
     * Asserted as an adjacency rather than as two presences, because presence is what a wrongly
     * ordered page still has. The element immediately after the line-items card is the totals box
     * and nothing else, on both documents that have one.
     */
    public function theLineItemsCardIsFollowedImmediatelyByTheTotals(FunctionalTester $I): void
    {
        $company = $this->company($I, 'Fixed Pair Co');
        $order = $this->order($I, $company, 'FIXED-SO');
        $estimate = $this->estimate($I, $company, 'FIXED-EST');

        foreach ([
            '/admin/order/detail/' . $order->getId(),
            '/admin/estimate/detail/' . $estimate->getId(),
        ] as $url) {
            $I->amOnPage($url);

            // Positive control on the anchor itself: the card is there to be followed by anything.
            $I->seeNumberOfElements(self::LINE_ITEMS, 1);

            $I->assertStringStartsWith(
                'display:flex; justify-content:flex-end',
                $I->grabAttributeFrom(self::LINE_ITEMS . '/following-sibling::*[1]', 'style') ?? '',
                $url . ': something has come between the line items and the totals',
            );
        }
    }

    /**
     * A document that itemises its money inside the line table's own foot is given no totals box —
     * not an empty one. The frame captures that region and omits its wrapper when the document put
     * nothing in it, which is the difference between "this document has no separate totals" and "an
     * empty flex row is pushing the page about".
     *
     * Both halves are asserted on ONE selector, and the invoice's own totals are located positively
     * on the same screen, so neither claim can be satisfied by a page that failed to render.
     */
    public function theFrameOmitsTheTotalsWrapperForADocumentThatHasNoTotalsBox(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Totals Wrapper Co');
        $order = $this->order($I, $company, 'WRAP-SO');
        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeNumberOfElements(self::TOTALS_BOX, 1);
        $I->seeNumberOfElements(self::LINE_ITEMS . '//tfoot', 0);

        // The invoice used its own <tfoot> until the box learned the three things that kept it
        // apart: a fee's quantity, an unconditional Total Tax, and Amount Paid / Balance Due.
        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeNumberOfElements(self::TOTALS_BOX, 1);
        $I->seeNumberOfElements(self::LINE_ITEMS . '//tfoot', 0);
    }

    /**
     * The one difference between these three screens the owner named as legitimate — "the
     * diferences should only be buttons" — has a slot of its own between the summary cards and the
     * line items, and the two documents that have no such buttons leave it empty rather than
     * rendering an empty panel.
     */
    /**
     * The invoice's own named actions (Issue, Start processing, Complete, Cancel, ...) moved into
     * the shared action bar's overflow (#full-parity, 2026-09-13 — the owner: "we have a bar for
     * buttons, please keep everything there"), so the frame's `document_actions` slot is now empty
     * on all three documents, same as it always was for Order and Quote.
     */
    public function theFramesActionsSlotIsEmptyOnAllThreeDocuments(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $company = $this->company($I, 'Actions Slot Co');
        $order = $this->order($I, $company, 'SLOT-SO');
        $estimate = $this->estimate($I, $company, 'SLOT-EST');
        $invoice = $I->grabService(OrderInvoicingService::class)
            ->invoiceInFull($order, $entityManager, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $entityManager->flush();

        // Same selector, same neighbour, on all three: an empty slot renders nothing, so the line
        // items follow the grid directly, whichever document is on screen.
        foreach ([
            '/admin/invoice/detail/' . $invoice->getId() => 'table-card no-search no-paginate detail-page-card',
            '/admin/order/detail/' . $order->getId() => 'table-card no-search no-paginate detail-page-card',
            '/admin/estimate/detail/' . $estimate->getId() => 'table-card no-search no-paginate detail-page-card',
        ] as $url => $expectedNeighbourClass) {
            $I->amOnPage($url);
            $I->seeNumberOfElements(self::DOCUMENT_ACTIONS, 0);
            $I->seeNumberOfElements(self::GRID, 1);
            $I->assertSame(
                $expectedNeighbourClass,
                $I->grabAttributeFrom(self::GRID . '/following-sibling::*[1]', 'class'),
                $url . ': something is standing in the frame\'s empty actions slot',
            );
        }

        // Positive control: the invoice's named actions still render, just in the shared bar now.
        $I->amOnPage('/admin/invoice/detail/' . $invoice->getId());
        $I->seeElement('#invoice-action-cancel');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────

    /** The headings a person reads down the page, in document order: the frame, as rendered. */
    private function frameHeadings(FunctionalTester $I): array
    {
        return array_map(
            static fn (string $heading): string => trim($heading),
            $I->grabMultiple('//main[@id="main-content"]//h1|//main[@id="main-content"]//h2'),
        );
    }

    private function company(FunctionalTester $I, string $name): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode('VBF-' . uniqid())
            ->setPrimaryEmail('ap-' . uniqid() . '@view-base-frame.example')
            ->setPhoneNumber('+1 604 555 0199');
        $I->haveInRepository($company);

        return $company;
    }

    private function address(FunctionalTester $I, Company $company, bool $shipping): CompanyAddress
    {
        $address = (new CompanyAddress())->setCompany($company);
        $shipping ? $address->setIsDefaultShipping(true) : $address->setIsDefaultBilling(true);
        $address
            ->setFirstName($shipping ? 'Sanjay' : 'Brenda')
            ->setLastName($shipping ? 'Shipping' : 'Billing')
            ->setAddressLine1($shipping ? '12 Dockside Way' : '884 Camosun Street')
            ->setCity($shipping ? 'Burnaby' : 'Victoria')
            ->setProvince('BC')
            ->setCountry('Canada')
            ->setPostalCode($shipping ? 'V5C 6R7' : 'V8V 4E1');
        $I->haveInRepository($address);

        return $address;
    }

    private function product(FunctionalTester $I, string $sku): ProductCore
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if ($existing instanceof ProductCore) {
            return $existing;
        }
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Frame ' . $sku)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function order(FunctionalTester $I, Company $company, string $prefix): SalesOrder
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $sku = $prefix . '-SKU';
        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber($prefix . '-1')
            ->setDocumentDate('2026-09-01')
            ->setSubtotal('55.00')
            ->setTax('2.75')
            ->setTotal('57.75');
        $order->setBillingAddressFrom($this->address($I, $company, false));
        $order->setShippingAddressFrom($this->address($I, $company, true));
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product($I, $sku))
                ->setName('Frame widget')->setSku($sku)
                ->setQuantity('5')->setPrice('11.00')->setSubtotal('55.00')
        );
        $I->haveInRepository($order);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $entityManager->flush();

        return $order;
    }

    private function estimate(FunctionalTester $I, Company $company, string $prefix): Estimate
    {
        $sku = $prefix . '-SKU';
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber($prefix . '-1')
            ->setSource('Admin')
            ->setSubtotal('55.00')
            ->setTax('2.75')
            ->setTotal('57.75');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->setBillingAddressFrom($this->address($I, $company, false));
        $estimate->setShippingAddressFrom($this->address($I, $company, true));
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($this->product($I, $sku))
                ->setName('Frame widget')->setSku($sku)
                ->setQuantity('5')->setPrice('11.00')->setSubtotal('55.00')
        );
        $I->haveInRepository($estimate);

        return $estimate;
    }
}
