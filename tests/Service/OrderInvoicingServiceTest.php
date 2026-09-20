<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\BundleStatusRepository;
use App\Service\OrderTaxBreakdownService;
use Psr\Log\NullLogger;
use TaxBundle\Tax\TaxCalculatorResolver;
use App\Entity\AbstractDocumentAddress;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\InvoicePaymentStatus;
use App\Enum\SalesOrderStatus;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\DocumentNumberAllocator;
use App\Service\InvoiceNumberGenerator;
use App\Service\OrderInvoicingService;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The one place an order's invoice is built (#539 stage 1). Every path that mints an order calls
 * this, so what it copies is what four screens copy, and a field missing here is a field missing on
 * every invoice in the system.
 *
 * Against a real EntityManager rather than a mock, for the reason EstimateConversionServiceTest is:
 * InvoiceNumberGenerator drives raw SQL through DocumentNumberAllocator that a stubbed manager
 * could not meaningfully fake.
 */
final class OrderInvoicingServiceTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private OrderInvoicingService $service;
    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        $this->service = $this->invoicingWith([]);
    }

    /**
     * @param array<string, string> $settings
     */
    private function invoicingWith(array $settings): OrderInvoicingService
    {
        $rows = [];
        foreach ($settings as $key => $value) {
            $rows[] = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
        }

        // Stubbed for the reason OrderNumberGeneratorTest stubs it: the generator only ever asks
        // AppSettings for one string, and writing a real row would put it inside the transaction
        // under test.
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);
        $settingsEm = $this->createStub(EntityManagerInterface::class);
        $settingsEm->method('getRepository')->willReturn($repo);

        return new OrderInvoicingService(
            new InvoiceNumberGenerator(new AppSettings($settingsEm, new ArrayAdapter()), new DocumentNumberAllocator()),
            $this->taxBreakdown(),
        );
    }

    /**
     * The real tax service, wired with no calculators — which is the honest fixture here: a tax
     * bundle contributes rates, and an instance with none owes no tax. What matters for these tests
     * is that the invoice goes through the same computeBreakdownFor() call the order form makes,
     * not what any particular jurisdiction charges.
     */
    private function taxBreakdown(): OrderTaxBreakdownService
    {
        $bundleStatus = $this->createStub(BundleStatusRepository::class);
        $bundleStatus->method('isActiveForInstance')->willReturn(false);

        return new OrderTaxBreakdownService(
            new TaxCalculatorResolver([], [], $bundleStatus),
            new NullLogger(),
        );
    }


    /**
     * An approved order with two lines. Approved rather than Draft because that is what an order
     * being invoiced is, and because an approved order is the one whose invoices count toward it —
     * which several tests below read. It gets there through the named action; there is no setter.
     */
    private function order(): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-' . ++$this->orderSequence)
            ->setSource('Customer')
            ->setUserName('Jane Buyer')
            ->setPoNumber('PO-77')
            ->setSpecialInstructions('Leave at dock')
            ->setShippingMethod('Ground')
            ->setFulfillmentRegion('West')
            ->setFeeLines(json_encode([
                ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc'],
            ]))
            ->setTaxLines('{"lines":[]}')
            ->setCouponCodes(['SAVE10'])
            ->setSubtotal('100.00')
            ->setTax('5.00')
            ->setTotal('115.00');

        $order->setPaymentMethod('Credit Card')->setPaymentTerm('Net 30');

        $order->addressForWriting(AbstractDocumentAddress::TYPE_BILLING)
            ->setFirstName('Jane')->setLastName('Buyer')->setCompanyName('Acme Co')
            ->setAddressLine1('1 Bill St')->setCity('Vancouver')->setProvince('BC')->setCountry('CA')->setPostalCode('V5K0A1');
        $order->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING)
            ->setFirstName('Sam')->setLastName('Receiver')->setCompanyName('Acme Depot')
            ->setAddressLine1('2 Ship Rd')->setCity('Burnaby')->setProvince('BC')->setCountry('CA')->setPostalCode('V5H1Z9')
            ->setDeliveryInstructions('Ring the bell');

        $order->addLine(
            (new SalesOrderLine())
                ->setName('Widget')->setLocation('Aisle 1')->setSku('WIDGET-1')
                ->setQuantity('2.00')->setWeight('3.5')->setUnit('lb')->setTaxCode('TAX1')
                ->setCost('40.00')->setPrice('50.00')->setSubtotal('100.00')->setBatch('LOT-9')
                ->setSortOrder(1),
        );
        $order->addLine(
            (new SalesOrderLine())
                ->setName('Gadget')->setSku('GADGET-2')
                ->setQuantity('1.00')->setCost('10.00')->setPrice('15.00')->setSubtotal('15.00')
                ->setSortOrder(0),
        );

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $this->em->persist($order);

        return $order;
    }

    public function testTheInvoiceCarriesTheOrdersHeader(): void
    {
        $order = $this->order();

        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('INV-1', $invoice->getDocumentNumber());
        self::assertSame($order, $invoice->getSalesOrder());
        self::assertSame($this->company, $invoice->getCompany());
        self::assertSame('Customer', $invoice->getSource());
        self::assertSame('Jane Buyer', $invoice->getUserName());
        self::assertSame('PO-77', $invoice->getPoNumber());
        self::assertSame('Leave at dock', $invoice->getSpecialInstructions());
        self::assertSame('Ground', $invoice->getShippingMethod());
        self::assertSame('West', $invoice->getFulfillmentRegion());
        self::assertSame($order->getFeeLines(), $invoice->getFeeLines());
        self::assertSame($order->getTaxLines(), $invoice->getTaxLines());
        self::assertSame(['SAVE10'], $invoice->getCouponCodes());
        self::assertSame('100.00', $invoice->getSubtotal());
        self::assertSame('5.00', $invoice->getTax());
        self::assertSame('115.00', $invoice->getTotal());
        self::assertSame($order->getDocumentDate(), $invoice->getDocumentDate());
        // The shipping row travels inside fee_lines, so the invoice states the same shipping total
        // as the order without a second copy of the figure that could disagree with it.
        self::assertSame(10.0, $invoice->getShippingTotal());
    }

    /**
     * The terms of the sale travel with the invoice; the payment STATUS does not, because since
     * #539 stage 4 it is derived from the invoice's own payment rows — and a new invoice has none,
     * so it is born Not Paid whatever the order it came from once said.
     */
    public function testTheInvoiceCarriesTheOrdersPaymentTermsButDerivesItsOwnStatus(): void
    {
        $invoice = $this->service->invoiceInFull($this->order(), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());
        self::assertSame('Credit Card', $invoice->getPaymentMethod());
        self::assertSame('Net 30', $invoice->getPaymentTerm());
    }

    /**
     * The accounting date comes off the order's own date.
     *
     * This used to be COALESCE(order.invoiceDate, order.documentDate) — the backfill's expression in
     * PHP — and its companion test asserted that a date typed on the ORDER won. Both halves went
     * with sales_order.invoice_date in #539 stage 6: there is no longer a place to type one, because
     * an order billed across several invoices has several invoice dates and a column on the order
     * could name only one of them. The invoice carries its own.
     */
    public function testTheInvoiceDateComesFromTheOrdersDate(): void
    {
        $order = $this->order();

        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame($order->getDocumentDate(), $invoice->getInvoiceDate());
        self::assertNotNull($invoice->getInvoiceDate(), 'an invoice is a document raised on a day; it is never undated');
    }

    public function testEveryOrderLineBecomesAnInvoiceLineAttributedToIt(): void
    {
        $order = $this->order();

        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertCount(2, $invoice->getLines());

        $byName = [];
        foreach ($invoice->getLines() as $line) {
            $byName[$line->getName()] = $line;
        }

        $widget = $byName['Widget'];
        self::assertSame('Aisle 1', $widget->getLocation());
        self::assertSame('WIDGET-1', $widget->getSku());
        self::assertSame('2.00', $widget->getQuantity());
        self::assertSame('3.5', $widget->getWeight());
        self::assertSame('lb', $widget->getUnit());
        self::assertSame('TAX1', $widget->getTaxCode());
        self::assertSame('40.00', $widget->getCost());
        self::assertSame('50.00', $widget->getPrice());
        self::assertSame('100.00', $widget->getSubtotal());
        self::assertSame('LOT-9', $widget->getBatch());
        self::assertSame(1, $widget->getSortOrder());
        self::assertSame(
            'WIDGET-1',
            $widget->getSalesOrderLine()?->getSku(),
            'the order row this bills is recorded, so uninvoiced qty can be derived per line',
        );

        self::assertSame(0, $byName['Gadget']->getSortOrder());
    }

    /**
     * The row order is part of what the customer agreed to, so the invoice prints its rows in the
     * order the order does. Asserted on a freshly loaded invoice because Invoice::$lines states the
     * ORDER BY, and an in-memory collection is in whatever order the copy happened to add to it.
     */
    public function testTheInvoicePrintsItsRowsInTheOrdersRowOrder(): void
    {
        $order = $this->order();
        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        $invoiceId = $invoice->getId();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Invoice::class)->find($invoiceId);

        self::assertSame(
            ['Gadget', 'Widget'],
            array_map(static fn ($line) => $line->getName(), $reloaded->getLines()->toArray()),
        );
    }

    public function testTheInvoiceTakesTheOrdersFrozenAddresses(): void
    {
        $order = $this->order();

        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertCount(2, $invoice->getAddresses());

        $billing = $invoice->getBillingAddress();
        self::assertSame('Jane', $billing->getFirstName());
        self::assertSame('1 Bill St', $billing->getAddressLine1());
        self::assertSame('V5K0A1', $billing->getPostalCode());

        $shipping = $invoice->getShippingAddress();
        self::assertSame('Acme Depot', $shipping->getCompanyName());
        self::assertSame('2 Ship Rd', $shipping->getAddressLine1());
        self::assertSame('Ring the bell', $shipping->getDeliveryInstructions());
    }

    /**
     * The identity is copied from the ORDER's snapshot, never re-read from the live Company. An
     * invoice is the record of who was billed; re-reading would let a rebrand between the order and
     * a later render change what the record says.
     */
    public function testTheInvoiceInheritsTheOrdersFrozenCompanyIdentity(): void
    {
        $order = $this->order();
        $this->em->flush();

        $this->company->setName('Globex Incorporated');
        $this->em->flush();

        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('Acme Co', $invoice->getCompanyIdentity()->getName());
        self::assertSame($order->getCompanySnapshot(), $invoice->getCompanySnapshot());
        self::assertSame('Globex Incorporated', $invoice->getCompany()->getName(), 'the live relationship still points at the account');
    }

    /**
     * The caller states the intent; the service issues to match it.
     *
     * Stage 1 inferred this from the order's own status, and that mapping is gone: stage 2's
     * SalesOrderStatus says nothing about goods or money, so there is nothing left on the order to
     * read. What is pinned here is the replacement contract — one intent, one resulting status.
     */
    #[DataProvider('issueIntents')]
    public function testTheStatedIntentDecidesTheInvoicesStatus(InvoiceIssueIntent $intent, string $expected): void
    {
        $invoice = $this->service->invoiceInFull($this->order(), $this->em, DocumentActor::system(), $intent);
        $this->em->flush();

        self::assertSame($expected, $invoice->getStatus());
    }

    /** @return iterable<string, array{0: InvoiceIssueIntent, 1: string}> */
    public static function issueIntents(): iterable
    {
        yield 'KeepDraft' => [InvoiceIssueIntent::KeepDraft, 'Draft'];
        yield 'Issue' => [InvoiceIssueIntent::Issue, 'Pending'];
        yield 'AwaitingPayment' => [InvoiceIssueIntent::AwaitingPayment, 'On Hold'];
    }

    /** Every intent the enum offers is pinned above, so adding one without deciding fails here. */
    public function testEveryIssueIntentIsCovered(): void
    {
        $covered = [];
        foreach (self::issueIntents() as [$intent]) {
            $covered[] = $intent;
        }

        self::assertEqualsCanonicalizing(InvoiceIssueIntent::cases(), $covered);
    }

    /**
     * intentForOrderStatus() is what the callers that DO mirror an order's own state use — quote
     * conversion, chiefly. Driven from SalesOrderStatus::cases() rather than a hand-written list,
     * keeping the exhaustiveness the retired mapping test had: a status added to the enum without a
     * decision recorded here fails rather than quietly defaulting to a draft invoice.
     */
    public function testTheIntentAnOrderStatusImplies(): void
    {
        $expected = [
            SalesOrderStatus::Draft->value => InvoiceIssueIntent::KeepDraft,
            SalesOrderStatus::Approved->value => InvoiceIssueIntent::Issue,
            SalesOrderStatus::PartiallyInvoiced->value => InvoiceIssueIntent::Issue,
            SalesOrderStatus::Invoiced->value => InvoiceIssueIntent::Issue,
            SalesOrderStatus::Closed->value => InvoiceIssueIntent::Issue,
            SalesOrderStatus::Void->value => InvoiceIssueIntent::KeepDraft,
        ];

        self::assertEqualsCanonicalizing(
            array_map(static fn (SalesOrderStatus $case): string => $case->value, SalesOrderStatus::cases()),
            array_keys($expected),
            'a status was added to SalesOrderStatus without deciding what invoice it should raise',
        );

        foreach (SalesOrderStatus::cases() as $case) {
            self::assertSame(
                $expected[$case->value],
                OrderInvoicingService::intentForOrderStatus($case),
                $case->value . ' raises the wrong kind of invoice',
            );
            self::assertSame(
                $case->isApprovedOrLater(),
                $expected[$case->value] === InvoiceIssueIntent::Issue,
                'an order is issued an invoice exactly when it is approved or later',
            );
        }
    }

    /**
     * SalesOrder.status is a plain string column so quote-era and pre-#539 rows still hydrate;
     * getStatusEnum() returns null for them. An unrecognised status is not an approval, so it must
     * raise a draft rather than issue against goods nobody accepted.
     */
    public function testAnUnrecognisedLegacyOrderStatusRaisesADraftInvoice(): void
    {
        self::assertSame(InvoiceIssueIntent::KeepDraft, OrderInvoicingService::intentForOrderStatus(null));

        $invoice = $this->service->invoiceInFull(
            $this->order(),
            $this->em,
            DocumentActor::system(),
            OrderInvoicingService::intentForOrderStatus(null),
        );
        $this->em->flush();

        self::assertSame('Draft', $invoice->getStatus());
    }

    public function testInvoiceNumbersAreSequential(): void
    {
        $first = $this->service->invoiceInFull($this->order(), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();
        $second = $this->service->invoiceInFull($this->order(), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame(['INV-1', 'INV-2'], [$first->getDocumentNumber(), $second->getDocumentNumber()]);
    }

    /** A configured prefix is honoured, and the sequence restarts under it — one counter per prefix. */
    public function testAConfiguredInvoicePrefixIsHonoured(): void
    {
        $service = $this->invoicingWith(['invoice_number_prefix' => 'BILL-']);

        $invoice = $service->invoiceInFull($this->order(), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('BILL-1', $invoice->getDocumentNumber());
    }

    /** A blank setting is not a prefix; it falls back rather than numbering invoices '1', '2'. */
    public function testABlankConfiguredPrefixFallsBackToInv(): void
    {
        $service = $this->invoicingWith(['invoice_number_prefix' => '  ']);

        $invoice = $service->invoiceInFull($this->order(), $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('INV-1', $invoice->getDocumentNumber());
    }

    /**
     * Invoicing the whole order twice would bill the same goods twice — true in stage 1, where it
     * would also break the one-invoice-per-order invariant, and true afterwards.
     */
    public function testAnOrderCannotBeInvoicedInFullTwice(): void
    {
        $order = $this->order();
        $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already has a live invoice');

        $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
    }

    /**
     * Cancelled invoices do not count, so an order whose only invoice was withdrawn can be invoiced
     * again — the goods are still owed. Same rule getCountingInvoices() states.
     */
    public function testAnOrderWhoseOnlyInvoiceWasCancelledCanBeInvoicedAgain(): void
    {
        $order = $this->order();
        $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue)
            ->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        $replacement = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);
        $this->em->flush();

        self::assertSame('INV-2', $replacement->getDocumentNumber(), 'a cancelled invoice keeps its number forever');
        self::assertCount(2, $this->em->getRepository(Invoice::class)->findBy(['salesOrder' => $order]));
        self::assertCount(1, $order->getCountingInvoices());
    }

    /** Both sides of the association are linked, so the order sees its own invoice in the same request. */
    public function testTheOrderSeesItsInvoiceWithoutAReload(): void
    {
        $order = $this->order();

        $invoice = $this->service->invoiceInFull($order, $this->em, DocumentActor::system(), InvoiceIssueIntent::Issue);

        self::assertSame([$invoice], $order->getInvoices()->toArray());
        self::assertSame([$invoice], $order->getCountingInvoices());
    }
}
