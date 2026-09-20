<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CancelStaleUnpaidOrdersCommand;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The sweeper cancels real documents, so the cases that must NOT be swept matter more than the ones
 * that must. In particular a B2B customer on trade credit is unpaid by arrangement, and their
 * invoice sits at Pending being fulfilled — cancelling it would destroy live business.
 *
 * Since #539 the fulfilment status the sweep reads belongs to the INVOICE, not to the order, so
 * every case here is an invoice in some state against a real approved order. The sweep composes two
 * actions: it cancels the abandoned invoice, then voids the order behind it once nothing live is
 * left on that order.
 */
final class CancelStaleUnpaidOrdersCommandTest extends DoctrineIntegrationTestCase
{
    /** What the sweep must sign its work with: neither a user nor the "System" fallback. */
    private const SWEEP_ACTOR = 'Stale unpaid order sweep (automated)';

    private CommandTester $tester;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Wholesale');
        $this->em->persist($this->company);
        $this->em->flush();

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:cancel-stale-unpaid-orders'));
    }

    /**
     * An approved order carrying one invoice, raised the way the app really raises it — through
     * OrderInvoicingService — so the fixture cannot drift from what checkout writes.
     */
    private function invoice(InvoiceIssueIntent $intent, string $raisedAgo): Invoice
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setSubtotal('100.00')
            ->setTotal('100.00');

        $order->addLine(
            (new SalesOrderLine())
                ->setName('Widget')->setSku('WIDGET-1')
                ->setQuantity('1.00')->setPrice('100.00')->setSubtotal('100.00'),
        );

        $this->em->persist($order);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $invoice = self::getContainer()->get(OrderInvoicingService::class)
            ->invoiceInFull($order, $this->em, DocumentActor::system(), $intent);
        $this->em->flush();

        // createdAt is set in the constructor and has no setter; age it directly so the test does
        // not have to wait out the window.
        $this->em->getConnection()->executeStatement(
            'UPDATE invoice SET created_at = :created WHERE id = :id',
            ['created' => (new \DateTimeImmutable($raisedAgo))->format('Y-m-d H:i:s'), 'id' => $invoice->getId()],
        );
        $this->em->refresh($invoice);

        // Guards the "nothing changed" cases below from passing vacuously against a Draft order
        // that was never live in the first place.
        self::assertTrue(
            $order->getStatusEnum()?->isApprovedOrLater() ?? false,
            'The fixture must produce a live, approved order.',
        );

        return $invoice;
    }

    private function orderOf(Invoice $invoice): SalesOrder
    {
        $order = $invoice->getSalesOrder();
        self::assertInstanceOf(SalesOrder::class, $order);

        return $order;
    }

    /** @return list<string> the narrative timeline comments for one document, written by $userName */
    private function commentsBy(string $entityType, int $entityId, string $userName): array
    {
        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'actorType' => 'document',
            'actorName' => $userName,
        ]);

        return array_map(static fn (AuditLog $log): string => $log->getSummary(), $logs);
    }

    /** The narrative timeline row count for one document. */
    private function narrativeLogCount(string $entityType, int $entityId): int
    {
        return count($this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'actorType' => 'document',
        ]));
    }

    public function testCancelsStaleOnHoldInvoiceAndVoidsTheOrderBehindIt(): void
    {
        $invoice = $this->invoice(InvoiceIssueIntent::AwaitingPayment, '-3 hours');
        $order = $this->orderOf($invoice);

        $exit = $this->tester->execute(['--minutes' => 60]);
        $this->em->refresh($invoice);
        $this->em->refresh($order);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('Cancelled', $invoice->getStatus());
        self::assertSame(
            SalesOrderStatus::Void->value,
            $order->getStatus(),
            'An abandoned checkout is junk on both sides: the invoice is cancelled and its order voided.',
        );
    }

    public function testTheSweepSignsBothTimelinesAsAScheduledJob(): void
    {
        $invoice = $this->invoice(InvoiceIssueIntent::AwaitingPayment, '-3 hours');
        $order = $this->orderOf($invoice);

        $this->tester->execute(['--minutes' => 60]);
        $this->em->refresh($invoice);
        $this->em->refresh($order);

        // #539: an admin looking at a voided order must be able to tell at a glance that a
        // scheduled job did it — not a signed-in user, and not the "System" fallback, which also
        // means "we did not know who".
        self::assertSame(
            self::SWEEP_ACTOR,
            DocumentActor::automation(CancelStaleUnpaidOrdersCommand::ACTOR_LABEL)->displayName,
        );

        $invoiceComments = $this->commentsBy('Invoice', $invoice->getId(), self::SWEEP_ACTOR);
        $orderComments = $this->commentsBy('SalesOrder', $order->getId(), self::SWEEP_ACTOR);

        self::assertCount(1, $invoiceComments);
        self::assertCount(1, $orderComments);
        self::assertStringContainsString('payment was not completed', $invoiceComments[0]);
        self::assertStringContainsString('payment was not completed', $orderComments[0]);
    }

    public function testLeavesAnOnHoldInvoiceInsideTheWindowAlone(): void
    {
        $invoice = $this->invoice(InvoiceIssueIntent::AwaitingPayment, '-5 minutes');
        $order = $this->orderOf($invoice);
        $orderStatus = $order->getStatus();

        $this->tester->execute(['--minutes' => 60]);
        $this->em->refresh($invoice);
        $this->em->refresh($order);

        self::assertSame('On Hold', $invoice->getStatus());
        self::assertSame($orderStatus, $order->getStatus());
    }

    public function testNeverTouchesAPendingCreditInvoiceHoweverOld(): void
    {
        $invoice = $this->invoice(InvoiceIssueIntent::Issue, '-30 days');
        $order = $this->orderOf($invoice);
        $orderStatus = $order->getStatus();

        $this->tester->execute(['--minutes' => 60]);
        $this->em->refresh($invoice);
        $this->em->refresh($order);

        self::assertSame(
            'Pending',
            $invoice->getStatus(),
            'A customer on terms is unpaid by arrangement — sweeping Pending would cancel live business.',
        );
        self::assertSame($orderStatus, $order->getStatus());
        self::assertNotSame(SalesOrderStatus::Void->value, $order->getStatus());
    }

    public function testNeverTouchesAProcessingInvoice(): void
    {
        $invoice = $this->invoice(InvoiceIssueIntent::Issue, '-30 days');
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        $order = $this->orderOf($invoice);
        $orderStatus = $order->getStatus();

        $this->tester->execute(['--minutes' => 60]);
        $this->em->refresh($invoice);
        $this->em->refresh($order);

        self::assertSame('Processing', $invoice->getStatus());
        self::assertSame($orderStatus, $order->getStatus());
    }

    public function testDryRunReportsWithoutChangingAnything(): void
    {
        $invoice = $this->invoice(InvoiceIssueIntent::AwaitingPayment, '-3 hours');
        $order = $this->orderOf($invoice);
        $orderStatus = $order->getStatus();
        $invoiceLogs = $this->narrativeLogCount('Invoice', $invoice->getId());
        $orderLogs = $this->narrativeLogCount('SalesOrder', $order->getId());

        $this->tester->execute(['--minutes' => 60, '--dry-run' => true]);
        $this->em->refresh($invoice);
        $this->em->refresh($order);

        self::assertSame('On Hold', $invoice->getStatus());
        self::assertSame($orderStatus, $order->getStatus());
        self::assertSame($invoiceLogs, $this->narrativeLogCount('Invoice', $invoice->getId()));
        self::assertSame($orderLogs, $this->narrativeLogCount('SalesOrder', $order->getId()));
        self::assertStringContainsString('would cancel', $this->tester->getDisplay());
    }

    public function testRejectsANonPositiveWindow(): void
    {
        $exit = $this->tester->execute(['--minutes' => 0]);

        self::assertSame(Command::FAILURE, $exit);
    }
}
