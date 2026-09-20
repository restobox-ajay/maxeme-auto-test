<?php

declare(strict_types=1);

namespace WooCommerceBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;
use WooCommerceBundle\Service\WooCommercePushQueueProcessor;

/**
 * Drains every Pending Push Queue row (#739) — the "not inline with the triggering request" half of
 * the event-driven push: WooCommerceInventoryChangeSubscriber marks a row Pending the moment a
 * stock bucket changes, and this pushes it to Woo on its own schedule, so a checkout or a receiving
 * scan never waits on a call to an external API.
 *
 * Meant to run on a short interval via the server's own crontab, the same pattern
 * `app:inventory-recalc`'s own docblock documents (this app has no Symfony Scheduler bundle):
 *
 *   * * * * * cd /path/to/app && php bin/console app:woocommerce:push-queue:drain >> /var/log/woocommerce-push-queue.log 2>&1
 *
 * A minute's staleness on a stock number shown to shoppers is the accepted cost of a queue that
 * never blocks a request; Bulk Resync (still to come) is the backstop that catches anything a drain
 * run ever missed.
 */
#[AsCommand(
    name: 'app:woocommerce:push-queue:drain',
    description: 'Pushes every Pending WooCommerce Push Queue row to its store. Run on a short interval via crontab.',
)]
final class WooCommercePushQueueDrainCommand extends Command
{
    public function __construct(
        private readonly WooCommercePushQueueItemRepository $pushQueue,
        private readonly WooCommercePushQueueProcessor $processor,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $items = $this->pushQueue->findAllPending();

        if ($items === []) {
            $io->writeln('Nothing to push.');

            return Command::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;
        foreach ($items as $item) {
            $this->processor->push($item);
            $item->isFailed() ? $failed++ : $pushed++;
        }

        $this->entityManager->flush();

        $io->writeln(sprintf('%d pushed, %d failed.', $pushed, $failed));

        return Command::SUCCESS;
    }
}
