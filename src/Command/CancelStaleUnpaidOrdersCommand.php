<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Invoice;
use App\Entity\SalesOrder;
use App\Enum\InvoiceStatus;
use App\Enum\SalesOrderStatus;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cancels card invoices whose payment never arrived, and voids the orders behind them.
 *
 * Checkout persists an order and its invoice before taking any money, so an abandoned card checkout
 * leaves a real invoice at InvoiceStatus::OnHold against a real order. Without this, those
 * accumulate forever.
 *
 * It sweeps On Hold and nothing else, which is the whole reason that status exists separately from
 * Pending. A customer on trade credit is legitimately unpaid for the length of their term and their
 * invoice sits at Pending being fulfilled — sweeping on "unpaid" would cancel live business.
 *
 * Invoices that were paid are never at risk: StripeOrderPaymentApplier moves an invoice off On Hold
 * the moment payment is applied, from either the browser or the webhook. The window only needs to be
 * comfortably longer than a customer takes to enter card details.
 *
 * ## Why this voids the order too
 *
 * An abandoned checkout is junk on both sides. Nobody accepted the order, no goods are owed, and no
 * human is ever going to come back and tidy it up — which is precisely what distinguishes this from
 * an admin cancelling an invoice by hand, where a person is present, making a decision about a real
 * order, and can void it themselves if that is what they want. Leaving the order here would strand
 * its full quantity in the sales hold indefinitely, which is the exact leak this sweep exists to
 * prevent.
 *
 * ## Two actions composed, not a third code path
 *
 * It calls the same Invoice::cancel() an admin's Cancel button calls, then voids the order through
 * SalesOrder::setStatus(), which is the one gate. There
 * is no "was this the sweep?" branch inside either — what separates the two situations is only what
 * each does afterwards. Both are attributed to a distinct sweep-cron actor rather than to a user or
 * to "System", so an admin looking at a voided order can see at a glance that a scheduled job did it
 * and why (#539 stage 2).
 */
#[AsCommand(
    name: 'app:cancel-stale-unpaid-orders',
    description: 'Cancel On Hold invoices whose payment never arrived, and void the orders behind them.',
)]
final class CancelStaleUnpaidOrdersCommand extends Command
{
    /** Minutes an invoice may sit On Hold before it is cancelled. Mirrors WooCommerce's "Hold stock". */
    public const SETTING_HOLD_MINUTES = 'checkout_unpaid_order_hold_minutes';
    public const DEFAULT_HOLD_MINUTES = 60;

    /** How this job signs its own work in the document timelines and the audit trail. */
    public const ACTOR_LABEL = 'Stale unpaid order sweep';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $appSettings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Override the configured hold window, in minutes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be cancelled without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $minutes = $input->getOption('minutes') !== null
            ? (int) $input->getOption('minutes')
            : (int) ($this->appSettings->get(self::SETTING_HOLD_MINUTES, (string) self::DEFAULT_HOLD_MINUTES) ?? self::DEFAULT_HOLD_MINUTES);

        if ($minutes < 1) {
            $io->error('The hold window must be at least 1 minute.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = new \DateTimeImmutable(sprintf('-%d minutes', $minutes));

        /** @var list<Invoice> $stale */
        $stale = $this->entityManager->getRepository(Invoice::class)
            ->createQueryBuilder('i')
            ->andWhere('i.status = :status')
            ->setParameter('status', InvoiceStatus::OnHold->value)
            ->andWhere('i.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        if ($stale === []) {
            $io->success(sprintf('No On Hold invoices older than %d minutes.', $minutes));

            return Command::SUCCESS;
        }

        $actor = DocumentActor::automation(self::ACTOR_LABEL);
        $reason = 'payment was not completed within the hold window.';
        $voided = 0;

        foreach ($stale as $invoice) {
            $order = $invoice->getSalesOrder();

            $io->writeln(sprintf(
                '  %s %s%s (raised %s)',
                $dryRun ? 'would cancel' : 'cancelling',
                $invoice->getDocumentNumber() ?: ('#' . $invoice->getId()),
                $order instanceof SalesOrder ? ' on order ' . ($order->getOrderNumber() ?: ('#' . $order->getId())) : '',
                $invoice->getCreatedAt()->format('Y-m-d H:i'),
            ));

            if ($dryRun) {
                continue;
            }

            try {
                $invoice->setStatus('Cancelled', $actor, sprintf('Invoice cancelled: %s', $reason));
            } catch (\DomainException $e) {
                // An On Hold invoice with money against it should not exist — the applier releases
                // one the moment a payment lands — so this is a genuine anomaly rather than a case
                // to handle. It is reported and skipped rather than allowed to abort the sweep,
                // because one odd row must not stop every other abandoned checkout being reclaimed.
                $io->warning(sprintf(
                    'Skipped %s: %s',
                    $invoice->getDocumentNumber() ?: ('#' . $invoice->getId()),
                    $e->getMessage(),
                ));

                continue;
            }

            // Voided only when the order has nothing else live on it. On the 1:1 ecom pair this
            // sweep exists for that is always true, and it is the case the reasoning above is about.
            // An order that also carries a live invoice someone else raised is a real order being
            // worked, and voiding it would take a live commitment out of reporting totals on the
            // strength of one abandoned checkout.
            if ($order instanceof SalesOrder
                && $order->getStatusEnum() !== SalesOrderStatus::Void
                && $order->getCountingInvoices() === []
            ) {
                $order->setStatus(
                    'Void',
                    $actor,
                    sprintf('Order voided (was %s): %s', $order->getStatus(), $reason),
                );
                ++$voided;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s %d On Hold invoice(s) older than %d minutes%s.',
            $dryRun ? 'Would cancel' : 'Cancelled',
            \count($stale),
            $minutes,
            $dryRun ? '' : sprintf(', voiding %d order(s)', $voided),
        ));

        return Command::SUCCESS;
    }
}
