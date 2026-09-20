<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The long admin-side lifecycle, driven through every real screen it touches: a quote is accepted
 * and converted, the order it produces is grown by a line the quote never had, three invoices are
 * raised against it in overlapping partial claims, one of the three is edited, a second is
 * cancelled outright, and the third is edited again to absorb exactly what the cancellation freed
 * — so the order reaches fully invoiced without the cancelled invoice ever having existed. Paying
 * the two that remain closes it.
 *
 * Two products carry the arithmetic so a shortfall in one line can never be mistaken for a
 * shortfall in the other: Widget A (10 ordered @ $10) is on the quote from the start, Widget B (6
 * ordered @ $20) is the line the order edit adds. Every invoice bills some of each, in the same
 * ratio, so "half the goods" and "a portion" both read the same way on both lines.
 *
 * Asserted on the database column at every turn (#624), the same discipline
 * AdminQuoteAcceptAndConvertCest states for the same reason: a screen that says the right thing
 * proves nothing about whether the row underneath agrees.
 */
final class EstimateToMultiInvoiceCloseCest
{
    private const REGION = 'Multi Invoice Close Region';

    /** The admin logs back in for every later step — created once, reused after that. */
    private ?AdminUser $admin = null;

    public function _before(FunctionalTester $I): void
    {
        $this->admin = null;
    }

    public function theFullCycleFromEstimateThroughThreeInvoicesToAClosedOrder(FunctionalTester $I): void
    {
        [$company, $productA, $productB, $estimate] = $this->pricedQuoteForWidgetA($I);
        $this->loginAsAdmin($I);

        // --- Step 1: accept, then convert to a Sales Order ---------------------------------------
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/accept/' . $estimate->getId(), ['_token' => $I->csrfToken()]);
        $I->assertSame('Accepted', $this->reloadEstimate($I, (int) $estimate->getId())->getStatus());

        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/convert/' . $estimate->getId(), ['_token' => $I->csrfToken()]);

        $orderId = $this->reloadEstimate($I, (int) $estimate->getId())->getConvertedOrder()?->getId();
        $I->assertNotNull($orderId);
        $order = $this->reloadOrder($I, $orderId);
        $I->assertSame(SalesOrderStatus::Approved->value, $order->getStatus());
        $lineAId = (int) $order->getLines()->first()->getId();

        // --- Step 2: admin grows the order with a line the quote never had -----------------------
        $I->amOnPage('/admin/order/edit/' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/order/edit/' . $orderId, [
            '_token' => $I->csrfToken(),
            'lines' => [
                ['id' => (string) $lineAId, 'product_id' => (string) $productA->getId(), 'qty' => '10', 'price' => '10.00'],
                ['product_id' => (string) $productB->getId(), 'qty' => '6', 'price' => '20.00'],
            ],
            'save_mode' => 'recalc',
        ]);

        $order = $this->reloadOrder($I, $orderId);
        $I->assertSame(SalesOrderStatus::Approved->value, $order->getStatus(), 'the edit keeps the order right where it was');
        $I->assertCount(2, $order->getLines(), 'the new line landed on the same order');
        $lineBId = 0;
        foreach ($order->getLines() as $orderLine) {
            if ((int) $orderLine->getId() !== $lineAId) {
                $lineBId = (int) $orderLine->getId();
            }
        }
        $I->assertNotSame(0, $lineBId, 'guard: the added line was actually found');

        // --- Step 3: Invoice #1 — half of each line, issued -------------------------------------
        // Also claims the order's own shipping charge in full: a charge is a quantified row like
        // any other (#539 stage 5), and SalesOrder::isFullyInvoiced() checks every charge slug as
        // well as every line — this one carries $0.00, but its quantity of 1 still has to be
        // billed by SOME invoice before the order can ever read as fully invoiced.
        $invoice1Id = $this->raiseInvoice(
            $I,
            $orderId,
            [
                ['product_id' => (string) $productA->getId(), 'sales_order_line_id' => (string) $lineAId, 'qty' => '5', 'price' => '10.00'],
                ['product_id' => (string) $productB->getId(), 'sales_order_line_id' => (string) $lineBId, 'qty' => '3', 'price' => '20.00'],
            ],
            'issue',
            [['slug' => 'shipping', 'quantity' => '1.00', 'amount' => '0.00']],
        );
        $I->assertSame(SalesOrderStatus::PartiallyInvoiced->value, $this->reloadOrder($I, $orderId)->getStatus());

        // --- Step 4: Invoice #2 — a further portion, issued --------------------------------------
        $invoice2Id = $this->raiseInvoice($I, $orderId, [
            ['product_id' => (string) $productA->getId(), 'sales_order_line_id' => (string) $lineAId, 'qty' => '3', 'price' => '10.00'],
            ['product_id' => (string) $productB->getId(), 'sales_order_line_id' => (string) $lineBId, 'qty' => '2', 'price' => '20.00'],
        ], 'issue');
        $I->assertSame(SalesOrderStatus::PartiallyInvoiced->value, $this->reloadOrder($I, $orderId)->getStatus());

        // --- Step 5: edit Invoice #1 — a real change, same quantities ----------------------------
        $I->amOnPage('/admin/invoice/edit/' . $invoice1Id);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoice1Id, [
            '_token' => $I->csrfToken(),
            'po_number' => 'MIC-PO-1',
            'special_instructions' => 'Loading dock B only.',
            'lines' => [
                ['product_id' => (string) $productA->getId(), 'sales_order_line_id' => (string) $lineAId, 'qty' => '5', 'price' => '10.00'],
                ['product_id' => (string) $productB->getId(), 'sales_order_line_id' => (string) $lineBId, 'qty' => '3', 'price' => '20.00'],
            ],
            'save_mode' => 'draft',
        ]);
        $editedInvoice1 = $this->reloadInvoice($I, $invoice1Id);
        $I->assertSame('MIC-PO-1', $editedInvoice1->getPoNumber());
        $I->assertSame('Pending', $editedInvoice1->getStatus(), 'editing a field does not disturb an already-issued invoice');
        $I->assertSame(110.0, (float) $editedInvoice1->getTotal(), '5 x $10 + 3 x $20, unchanged by the edit');

        // --- Step 6: Invoice #3 — the "final" invoice, but not the full remainder yet ------------
        $invoice3Id = $this->raiseInvoice($I, $orderId, [
            ['product_id' => (string) $productA->getId(), 'sales_order_line_id' => (string) $lineAId, 'qty' => '2', 'price' => '10.00'],
            ['product_id' => (string) $productB->getId(), 'sales_order_line_id' => (string) $lineBId, 'qty' => '1', 'price' => '20.00'],
        ], 'issue');
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $this->reloadOrder($I, $orderId)->getStatus(),
            '5+3+2 of A and 3+2+1 of B is the whole order, so it is fully invoiced — just not yet paid',
        );

        // --- Step 7: cancel Invoice #2 — its quantity reopens ------------------------------------
        $I->amOnPage('/admin/invoice/detail/' . $invoice2Id);
        $I->sendFormPostRequest('/admin/invoice/' . $invoice2Id . '/action/cancel', ['_token' => $I->csrfToken()]);
        $I->assertSame('Cancelled', $this->reloadInvoice($I, $invoice2Id)->getStatus());
        $I->assertSame(
            SalesOrderStatus::PartiallyInvoiced->value,
            $this->reloadOrder($I, $orderId)->getStatus(),
            'a cancelled invoice counts toward nothing, so its 3 of A and 2 of B are uninvoiced again',
        );

        // --- Step 8: edit Invoice #3 to absorb exactly what #2 used to cover --------------------
        $I->amOnPage('/admin/invoice/edit/' . $invoice3Id);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/edit/' . $invoice3Id, [
            '_token' => $I->csrfToken(),
            'lines' => [
                ['product_id' => (string) $productA->getId(), 'sales_order_line_id' => (string) $lineAId, 'qty' => '5', 'price' => '10.00'],
                ['product_id' => (string) $productB->getId(), 'sales_order_line_id' => (string) $lineBId, 'qty' => '3', 'price' => '20.00'],
            ],
            'save_mode' => 'draft',
        ]);
        $editedInvoice3 = $this->reloadInvoice($I, $invoice3Id);
        $I->assertSame('Pending', $editedInvoice3->getStatus(), 'still the same issued invoice, only its lines grew');
        $I->assertSame(110.0, (float) $editedInvoice3->getTotal(), '5 x $10 + 3 x $20, same shape invoice #1 always billed');
        $I->assertSame(
            SalesOrderStatus::Invoiced->value,
            $this->reloadOrder($I, $orderId)->getStatus(),
            'fully invoiced again — invoice #1 and the edited invoice #3, invoice #2 never counted again',
        );

        // --- Step 9: pay off the two invoices that remain ----------------------------------------
        $this->recordPayment($I, $invoice1Id, $editedInvoice1->getTotal(), 'Paying invoice #1 in full.');
        $this->recordPayment($I, $invoice3Id, $editedInvoice3->getTotal(), 'Paying invoice #3 in full.');

        $I->assertSame(InvoicePaymentStatus::Paid, $this->reloadInvoice($I, $invoice1Id)->getPaymentStatus());
        $I->assertSame(InvoicePaymentStatus::Paid, $this->reloadInvoice($I, $invoice3Id)->getPaymentStatus());

        // --- Step 10: the order closes ------------------------------------------------------------
        $I->assertSame(
            SalesOrderStatus::Closed->value,
            $this->reloadOrder($I, $orderId)->getStatus(),
            'every counting invoice — #1 and #3, #2 dropped out when it cancelled — is now fully paid',
        );
    }

    // ------------------------------------------------------------------------------------ driving

    /** Bills $orderId through the real Convert to Invoice screen and returns the new invoice's id. */
    private function raiseInvoice(FunctionalTester $I, int $orderId, array $lines, string $saveMode, array $charges = []): int
    {
        $I->amOnPage('/admin/invoice/create?order_id=' . $orderId);
        $I->seeResponseCodeIsSuccessful();
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => $saveMode,
            'lines' => $lines,
            'charges' => $charges,
        ]);

        $order = $this->reloadOrder($I, $orderId);
        $ids = array_map(static fn (Invoice $invoice): int => (int) $invoice->getId(), $order->getInvoices()->toArray());
        sort($ids);
        $newest = end($ids);
        $I->assertNotFalse($newest, 'guard: the order actually gained an invoice');

        return $newest;
    }

    private function recordPayment(FunctionalTester $I, int $invoiceId, string $amount, string $comment): void
    {
        $I->amOnPage('/admin/invoice/' . $invoiceId . '/payments');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('#document-payment-form input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/invoice/' . $invoiceId . '/payments', [
            '_token' => $token,
            'payment_id' => '',
            'received_at' => '2026-09-13',
            'method' => 'Bank Transfer',
            'amount' => $amount,
            'comment' => $comment,
        ]);
    }

    // ------------------------------------------------------------------------------------ fixture

    /**
     * An admin-raised, fully priced quote for 10 of Widget A — Widget B doesn't exist on it yet,
     * because the order edit is what adds that line.
     *
     * @return array{0: Company, 1: ProductCore, 2: ProductCore, 3: Estimate}
     */
    private function pricedQuoteForWidgetA(FunctionalTester $I): array
    {
        $company = (new Company())
            ->setName('Multi Invoice Close Wholesale')
            ->setCode('MIC-' . uniqid());
        $I->haveInRepository($company);
        $I->haveActiveFulfillmentRegionFor($company, self::REGION);

        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)
                ->setLabel('Main')
                ->setAddressLine1('4 Ledger Row')
                ->setCity('Winnipeg')
                ->setProvince('MB')
                ->setPostalCode('R3C0V8')
                ->setCountry('CA')
                ->setIsDefaultShipping(true)
                ->setIsDefaultBilling(true),
        );

        $productA = (new ProductCore())
            ->setSku('MIC-A-' . uniqid())
            ->setName('Multi Invoice Widget A')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setCostPrice('4.00')
            ->setDefaultPrice('10.00')
            ->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($productA);
        $I->haveStockFor($productA, 1000, self::REGION);

        $productB = (new ProductCore())
            ->setSku('MIC-B-' . uniqid())
            ->setName('Multi Invoice Widget B')
            ->setUnit('EA')
            ->setSalesTaxCode('E')
            ->setCostPrice('8.00')
            ->setDefaultPrice('20.00')
            ->setOriginalPrice('20.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($productB);
        $I->haveStockFor($productB, 1000, self::REGION);

        // A shipping row of quantity 0 — not no row at all: EstimateConversionService::convert()
        // refuses an estimate whose isFullyPriced() is false, which needs getShippingTotal() to be
        // non-null (a resolved $0 charge, not an absent one). But SalesOrder::isFullyInvoiced()
        // also walks every charge slug's OWN quantity, and a quantity-1 row nothing ever bills
        // would keep this order "Partially Invoiced" forever no matter how fully its lines were
        // invoiced — this test's own first draft tripped over exactly that. Quantity 0 satisfies
        // both: a resolved figure that starts, and stays, fully invoiced on its own.
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('MIC-' . uniqid())
            ->setSource('Admin')
            ->setFulfillmentRegion(self::REGION)
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'No Shipping Charge', 'taxClass' => 'E',
                'amount' => 0.0, 'quantity' => 0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal('100.00')
            ->setTax('0.00')
            ->setTotal('100.00');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->setBillingAddressFrom($company->getDefaultBillingAddress());
        $estimate->setShippingAddressFrom($company->getDefaultShippingAddress());
        $estimate->addLine(
            (new EstimateLine())
                ->setProduct($productA)
                ->setName($productA->getName())
                ->setSku($productA->getSku())
                ->setLocation(self::REGION)
                ->setTaxCode('E')
                ->setQuantity('10.00')
                ->setPrice('10.00')
                ->setSubtotal('100.00'),
        );
        $I->haveInRepository($estimate);

        return [$company, $productA, $productB, $estimate];
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        if (!$this->admin instanceof AdminUser) {
            $hasher = $I->grabService(UserPasswordHasherInterface::class);
            $this->admin = (new AdminUser())->setEmail('mic-admin@example.test');
            $this->admin->setPassword($hasher->hashPassword($this->admin, 'test-password-123'));
            $I->haveInRepository($this->admin);
        }

        $I->amLoggedInAs($this->admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * find() through a freshly grabbed service, never refresh() on an object a prior request
     * built — that request wrote through its OWN entity manager and this one's identity map knows
     * nothing about it (see AdminInvoicePaymentsCest::reloadInvoice()'s own docblock).
     */
    private function reloadEstimate(FunctionalTester $I, int $id): Estimate
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(Estimate::class)->find($id);
    }

    private function reloadOrder(FunctionalTester $I, int $id): SalesOrder
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(SalesOrder::class)->find($id);
    }

    private function reloadInvoice(FunctionalTester $I, int $id): Invoice
    {
        return $I->grabService(EntityManagerInterface::class)->getRepository(Invoice::class)->find($id);
    }
}
