<?php

declare(strict_types=1);

namespace App\Command\Bundle;

use App\Bundle\InstalledBundleDirectory;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What is installed, and what state each one is in — the console's answer to App Management's
 * listing, and the thing to run first when something has gone dark.
 *
 * Shows the same three states the screen does, from the same two facts:
 *
 * | state              | on disk | `bundle_status` row |
 * |--------------------|---------|---------------------|
 * | Active             | yes     | Active              |
 * | Inactive           | yes     | Inactive            |
 * | Never activated    | yes     | none                |
 *
 * plus a fourth line for a row whose module is gone, which the screen labels the same way: a
 * leftover from a folder that was deleted. `config/bundles.php` supports deleting a module folder
 * as a way of removing a bundle, so the row outliving the code is expected, not corruption.
 *
 * Read-only. It is the one bundle command that writes nothing, which matters because it is the one
 * most likely to be run against an installation somebody is already unsure about.
 */
#[AsCommand(
    name: 'app:bundle:list',
    description: 'List installed bundles and whether each is Active, Inactive or never activated.',
)]
final class BundleListCommand extends Command
{
    private const STATE_ACTIVE = 'Active';
    private const STATE_INACTIVE = 'Inactive';
    private const STATE_NEVER = 'Never activated';
    private const STATE_ORPHAN = 'Not installed (row only)';

    public function __construct(
        private readonly BundleStatusRepository $bundleStatuses,
        private readonly InstalledBundleDirectory $installed,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $statuses = $this->bundleStatuses->statusBySource();
        $sources = $this->installed->sources();

        $rows = [];
        $counts = [self::STATE_ACTIVE => 0, self::STATE_INACTIVE => 0, self::STATE_NEVER => 0, self::STATE_ORPHAN => 0];

        foreach ($sources as $source) {
            $state = match ($statuses[$source] ?? null) {
                BundleStatus::STATUS_ACTIVE => self::STATE_ACTIVE,
                BundleStatus::STATUS_INACTIVE => self::STATE_INACTIVE,
                default => self::STATE_NEVER,
            };

            ++$counts[$state];
            $rows[] = [$source, 'yes', $state];
        }

        foreach ($statuses as $source => $status) {
            if (in_array($source, $sources, true)) {
                continue;
            }

            ++$counts[self::STATE_ORPHAN];
            $rows[] = [$source, 'no', self::STATE_ORPHAN . ' — ' . $status];
        }

        $io->table(['Source', 'On disk', 'State'], $rows);

        $io->writeln(sprintf(
            '%d installed: %d Active, %d Inactive, %d never activated. %d row(s) with no module on disk.',
            count($sources),
            $counts[self::STATE_ACTIVE],
            $counts[self::STATE_INACTIVE],
            $counts[self::STATE_NEVER],
            $counts[self::STATE_ORPHAN],
        ));

        // Worth saying out loud rather than leaving somebody to infer it from a column of "Never
        // activated". An installation in this state has had no upgrade migration and no activation:
        // every gate answers false and most of the application does nothing.
        if ($counts[self::STATE_ACTIVE] === 0 && $counts[self::STATE_NEVER] > 0) {
            $io->warning('No bundle is Active. Run app:bundle:activate --all-present to switch on everything installed.');
        }

        return Command::SUCCESS;
    }
}
