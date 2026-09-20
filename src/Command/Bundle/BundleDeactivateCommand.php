<?php

declare(strict_types=1);

namespace App\Command\Bundle;

use App\Bundle\InstalledBundleDirectory;
use App\Repository\BundleStatusRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Switches a bundle off from the shell, the counterpart to {@see BundleActivateCommand}.
 *
 * Same single activation path — {@see BundleStatusRepository::deactivate()} — so the console and
 * the Deactivate button on App Management cannot disagree about what off means.
 *
 * **It deletes nothing.** Not the status row, and not one row of the data the bundle owns. Off
 * means the bundle's gates answer false; everything it ever wrote sits untouched, so activating it
 * again needs no recount, no re-import and no manual step.
 *
 * Deliberately no `--all-present`. Activating everything is a recovery action, and its failure mode
 * is a bundle running that should not be. Deactivating everything has no recovery use at all and
 * its failure mode is an application with nothing switched on — one keystroke is too cheap for
 * that. Off is done one bundle at a time, on purpose.
 */
#[AsCommand(
    name: 'app:bundle:deactivate',
    description: 'Deactivate a bundle by source. Deletes nothing.',
)]
final class BundleDeactivateCommand extends Command
{
    public function __construct(
        private readonly BundleStatusRepository $bundleStatuses,
        private readonly InstalledBundleDirectory $installed,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'Bundle source, e.g. ProcurementBundle.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getArgument('source');

        // Unlike activate, a source with no module on disk is only a WARNING here. A row left
        // behind by a module whose folder was deleted is a real state the management screen names
        // "not installed", and switching it off is a legitimate tidy-up — refusing would leave the
        // one row nobody can reach from any screen.
        if (!$this->installed->isInstalled($source)) {
            $io->warning(sprintf('No bundle "%s" is installed under modules/; writing the row anyway.', $source));
        }

        $before = $this->bundleStatuses->findBySource($source)?->getStatus();
        $result = $this->bundleStatuses->deactivate($source);

        $io->success(sprintf(
            '%s is now Inactive (was %s). Nothing was deleted.',
            $source,
            $before ?? 'never activated',
        ));

        // Cascaded off too (#788) — printed so this command cannot silently take down a dependent
        // the caller never named, the same reason the App Management confirm step names them.
        if ($result->alsoDeactivated !== []) {
            $io->note(sprintf('Also turned off, because it requires %s: %s', $source, implode(', ', $result->alsoDeactivated)));
        }

        return Command::SUCCESS;
    }
}
