<?php

declare(strict_types=1);

namespace WooCommerceBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WooCommerceBundle\Service\WooCommerceBulkResyncService;

/**
 * Nightly Bulk Resync (#739) — the scheduled backstop to the Push Queue's event-driven trigger,
 * same shape and reasoning as `app:inventory-recalc`'s own docblock (this app has no Symfony
 * Scheduler bundle):
 *
 *   0 3 * * * cd /path/to/app && php bin/console app:woocommerce:bulk-resync >> /var/log/woocommerce-bulk-resync.log 2>&1
 *
 * Re-queues every mapped product on every active connection outright, catching anything the
 * event-driven path ever missed. Queuing, not pushing: this command's own job ends the moment
 * WooCommerceBulkResyncService::run() returns — the actual pushes happen on
 * `app:woocommerce:push-queue:drain`'s own schedule, same as any other queued row.
 */
#[AsCommand(
    name: 'app:woocommerce:bulk-resync',
    description: 'Re-queues every mapped product on every active WooCommerce connection. Run nightly via crontab.',
)]
final class WooCommerceBulkResyncCommand extends Command
{
    public function __construct(
        private readonly WooCommerceBulkResyncService $resync,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $run = $this->resync->run(null, 'cron');

        $io->writeln(sprintf('Queued %d product(s) for resync.', $run->getQueuedCount()));

        return Command::SUCCESS;
    }
}
