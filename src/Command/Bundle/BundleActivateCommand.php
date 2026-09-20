<?php

declare(strict_types=1);

namespace App\Command\Bundle;

use App\Bundle\InstalledBundleDirectory;
use App\Repository\BundleStatusRepository;
use App\Service\Bundle\BundleDependencyException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Switches a bundle on from the shell — the second of the two entry points, and the only one that
 * works when the first cannot be reached.
 *
 * ## Why this exists as well as the button
 *
 * Activation has to be possible from the management PAGE or from the CONSOLE, both. The page is
 * where an administrator does it; this is the escape hatch. It needs no HTTP request, no session,
 * no logged-in user and no bundle being active, because it goes straight to
 * {@see BundleStatusRepository::activate()} — see that method for why nothing on that path can
 * require any of those things.
 *
 * ## It does not write the row itself
 *
 * One activation method, reached by both entry points. If this command persisted a BundleStatus of
 * its own, the console and the button would be two implementations of the same idea, free to
 * disagree about what "activate" means — whether a missing row is created, what a bad status
 * string does, whether anything is flushed. Both call the repository instead.
 *
 * ## `--all-present`
 *
 * Activates every module currently installed. This is the recovery action, not the upgrade step:
 * the one-time upgrade for existing installations is
 * {@see \DoctrineMigrations\Version20260916104500} and it runs automatically. Use this when a
 * database missed that migration, when a flip went wrong, or to put a database built from Doctrine
 * metadata rather than the migration chain into the same state — which is exactly what
 * `tests/_bootstrap.php` does with it, the way it already calls `app:seed-regions` for the
 * reference tables the seed migration would have filled.
 *
 * Both modes are safe to repeat: activating something already Active writes the same value back.
 */
#[AsCommand(
    name: 'app:bundle:activate',
    description: 'Activate a bundle by source, or every bundle installed on disk with --all-present.',
)]
final class BundleActivateCommand extends Command
{
    public function __construct(
        private readonly BundleStatusRepository $bundleStatuses,
        private readonly InstalledBundleDirectory $installed,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Bundle source, e.g. ProcurementBundle. Omit with --all-present.')
            ->addOption('all-present', null, InputOption::VALUE_NONE, 'Activate every bundle installed on disk.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $input->getArgument('source');
        $allPresent = (bool) $input->getOption('all-present');

        if ($allPresent && $source !== null) {
            $io->error('Pass a source or --all-present, not both.');

            return Command::INVALID;
        }

        if (!$allPresent && $source === null) {
            $io->error('Name a bundle source, or pass --all-present to activate everything installed.');

            return Command::INVALID;
        }

        return $allPresent ? $this->activateAllPresent($io) : $this->activateOne($io, (string) $source);
    }

    private function activateOne(SymfonyStyle $io, string $source): int
    {
        // A source that matches no installed module is refused rather than written. Activating a
        // typo would create a row nothing ever reads and would report success for a bundle that is
        // not there — and the reason somebody reaches for this command is usually that something is
        // already wrong, which is the worst moment to be told a mistake worked.
        if (!$this->installed->isInstalled($source)) {
            $io->error(sprintf('No bundle "%s" is installed under modules/.', $source));
            $io->note('Run app:bundle:list to see what is installed and what state each one is in.');

            return Command::FAILURE;
        }

        $before = $this->bundleStatuses->findBySource($source)?->getStatus();

        try {
            $this->bundleStatuses->activate($source);
        } catch (BundleDependencyException $e) {
            $io->error($e->getMessage());
            $io->note(sprintf('Activate %s first: app:bundle:activate %s', implode(', ', $e->missingSources), $e->missingSources[0]));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s is now Active (was %s).',
            $source,
            $before ?? 'never activated',
        ));

        return Command::SUCCESS;
    }

    private function activateAllPresent(SymfonyStyle $io): int
    {
        $sources = $this->installed->sources();

        if ($sources === []) {
            $io->warning('No modules are installed under modules/. Nothing to activate.');

            return Command::SUCCESS;
        }

        $changed = $this->bundleStatuses->activateAll($sources);

        $io->success(sprintf(
            '%d of %d installed bundle(s) activated; %d were already Active.',
            $changed,
            count($sources),
            count($sources) - $changed,
        ));

        return Command::SUCCESS;
    }
}
