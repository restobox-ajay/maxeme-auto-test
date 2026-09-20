<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes throwaway e2e databases nobody is using any more.
 *
 * Agents invent a database name per run, so the files accumulate: every sandbox that ever
 * started leaves one behind, plus its -wal and -shm siblings. Left alone, var/ fills up with
 * databases whose owning agent stopped existing days ago. The requirement anticipates it —
 * "Dev may need clean up step after :-)".
 *
 * Age is read from the file's MODIFICATION time, not its creation time: a long-running agent
 * touches its database constantly, so mtime is the closest available answer to "is anyone still
 * using this", and creation time would delete the database out from under an agent that has
 * been working for hours.
 *
 * DRY RUN BY DEFAULT. This deletes databases, and the difference between an abandoned instance
 * and one an agent is mid-test on is a number of hours somebody has to choose. Printing the
 * list first, and requiring --force to act, is the difference between a cleanup and an outage.
 *
 * NO TEST_INSTANCE_KEY GUARD, unlike app:test-instance:provision, and the difference is worth
 * stating because the two commands look like a pair. Provisioning has to refuse without a usable
 * key: it opens a connection, and with the mechanism switched off that connection is the shared
 * dev database, so it would seed everybody's data. This command opens nothing. It unlinks files
 * whose names match var/db_*.sqlite — the shared dev database is var/data_dev.db, which the glob
 * cannot match and PROTECTED_NAMES names regardless — so there is no wrong database for it to act
 * on. Refusing without a key would only stop it clearing up the debris an unusable key leaves
 * behind, which is the opposite of what this is for.
 */
#[AsCommand(
    name: 'app:test-instance:gc',
    description: 'Remove abandoned e2e databases (var/db_*.sqlite). Dry run unless --force.',
)]
final class TestInstanceGcCommand extends Command
{
    /** Never touched, whatever its age — these are not per-agent instances. */
    private const PROTECTED_NAMES = ['data_dev.db', 'data_test.db', 'data_prod.db'];

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED,
                'Delete instances untouched for this many hours', '24')
            ->addOption('instance', null, InputOption::VALUE_REQUIRED,
                'Delete one named instance regardless of age')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Actually delete. Without this, only lists what would go.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment !== 'dev') {
            $io->error(sprintf('Refusing to run in "%s" — dev only.', $this->environment));

            return Command::FAILURE;
        }

        $varDir = $this->projectDir . '/var';
        $one = (string) $input->getOption('instance');
        $hours = max(0, (int) $input->getOption('older-than'));
        $cutoff = time() - $hours * 3600;
        $force = (bool) $input->getOption('force');

        // Only db_*.sqlite. The glob is the first guard and PROTECTED_NAMES is the second: the
        // real dev database is data_dev.db and does not match this pattern, but a rename
        // upstream should not be all that stands between this command and it.
        $candidates = [];
        foreach (glob($varDir . '/db_*.sqlite') ?: [] as $path) {
            $base = basename($path);
            if (\in_array($base, self::PROTECTED_NAMES, true)) {
                continue;
            }
            $name = substr($base, 3, -7);           // db_<name>.sqlite
            if ($one !== '') {
                if ($name === $one) {
                    $candidates[] = [$path, $name, filemtime($path) ?: 0];
                }
                continue;
            }
            $mtime = filemtime($path) ?: 0;
            if ($mtime < $cutoff) {
                $candidates[] = [$path, $name, $mtime];
            }
        }

        if (!$candidates) {
            $io->success($one !== ''
                ? sprintf('No instance named "%s".', $one)
                : sprintf('Nothing untouched for %dh.', $hours));

            return Command::SUCCESS;
        }

        $rows = [];
        $bytes = 0;
        foreach ($candidates as [$path, $name, $mtime]) {
            $size = filesize($path) ?: 0;
            $bytes += $size;
            $rows[] = [$name, sprintf('%.1f MB', $size / 1048576),
                $mtime ? date('Y-m-d H:i', $mtime) : '?',
                $mtime ? sprintf('%.1f h', (time() - $mtime) / 3600) : '?'];
        }
        $io->table(['instance', 'size', 'last touched', 'idle'], $rows);

        if (!$force) {
            $io->warning(sprintf('DRY RUN — %d instance(s), %.1f MB. Re-run with --force to delete.',
                \count($candidates), $bytes / 1048576));

            return Command::SUCCESS;
        }

        $removed = 0;
        foreach ($candidates as [$path, $name]) {
            // The -wal and -shm siblings go too. Leaving a stray -wal behind next to a deleted
            // database is how SQLite gets handed a write-ahead log for a file that no longer
            // exists, and the next instance that happens to reuse the name inherits it.
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            $removed += is_file($path) ? 0 : 1;
        }

        $io->success(sprintf('Removed %d of %d instance(s), freeing about %.1f MB.',
            $removed, \count($candidates), $bytes / 1048576));

        return $removed === \count($candidates) ? Command::SUCCESS : Command::FAILURE;
    }
}
