<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use App\Repository\ImportRunRepository;
use App\Service\Import\ImportLock;
use App\Service\Import\ImportRunnerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drains the import queue: one flock, one process, every queued ImportRun in submission order.
 *
 * Every import type's controller spawns this detached after queueing (`exec('bin/console
 * import:process --detached')`), and the admin "Process Queue" / "Retry Queue" buttons do the same
 * manually. flock() makes a second concurrent invocation exit immediately instead of piling up —
 * whichever process is already draining the queue will pick up anything the second one would have
 * queued for it. See docs/plans/2026-09-18-unified-import-framework.md, D6/D9.
 */
#[AsCommand(
    name: 'import:process',
    description: 'Drains the import queue, one run at a time, under a flock. Safe to invoke concurrently — a second invocation exits immediately if one is already draining.',
)]
final class ImportProcessCommand extends Command
{
    public function __construct(
        private readonly ImportRunRepository $runs,
        private readonly ImportRunnerRegistry $registry,
        private readonly ImportLock $lock,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock->tryAcquire()) {
            $output->writeln('<comment>Another import is already running; exiting.</comment>');

            return Command::SUCCESS;
        }

        try {
            $processed = 0;
            while (($run = $this->runs->findOldestQueued()) !== null) {
                $this->runOne($run, $output);
                ++$processed;
            }

            $output->writeln(sprintf('<info>Queue drained. %d run(s) processed.</info>', $processed));

            return Command::SUCCESS;
        } finally {
            $this->lock->release();
        }
    }

    private function runOne(ImportRun $run, OutputInterface $output): void
    {
        $run->setStatus(ImportRunStatus::Started);
        $run->setStartedAt(new \DateTimeImmutable());
        $this->em->flush();

        try {
            if (!$this->registry->has($run->getImportType())) {
                throw new \RuntimeException("No import runner registered for '{$run->getImportType()}'.");
            }

            $this->registry->get($run)->executeQueued($run);
        } catch (\Throwable $exception) {
            // executeQueued() itself already catches per-row failures (D2) — this only catches a
            // top-level failure (bad file, a bug in the executor, the exception above). Status
            // still goes to Completed: it is workflow-only, never outcome (see D9's own note).
            $run->setError($exception->getMessage());
            $output->writeln(sprintf('<error>Import #%d failed: %s</error>', $run->getId(), $exception->getMessage()));
        } finally {
            $run->setStatus(ImportRunStatus::Completed);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        $output->writeln(sprintf(
            'Import #%d (%s): %d row(s), %d executed, %d error(s).',
            $run->getId(),
            $run->getImportType(),
            $run->getRowCount(),
            $run->getExecutedCount(),
            $run->getErrorCount(),
        ));
    }
}
