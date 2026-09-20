<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Command;

use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Number1RimImportBundle\Service\RimApiException;
use Number1RimImportBundle\Service\RimApiImportService;
use Number1RimImportBundle\Service\RimImportConfig;
use Number1RimImportBundle\Service\RimSyncStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The intended primary trigger — meant to be wired into the server's system crontab, same
 * operational shape as number1_inventory's own script/rimsUpdate.php (see RIM_API_IMPORT_PLAN.md
 * §3/§4). Unlike an admin route (at least nominally gated by bundle status at the HTTP layer), a
 * cron-invoked command has no request to gate at all, so it checks BundleStatusRepository itself.
 *
 * Also the single place a sync actually runs — RimImportController never calls
 * RimApiImportService::sync() directly; it spawns this exact command as a detached background
 * process and polls RimSyncStatus for the result, so a slow sync (image downloads can take
 * minutes) never blocks the HTTP request. Writing status here means that's true whether this
 * command was launched by cron or by that spawn — one source of truth either way.
 */
#[AsCommand(
    name: 'number1-rim-import:sync',
    description: 'Pulls the rim/wheel catalog from the configured API and syncs it into ProductCore.'
)]
final class SyncRimProductsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RimApiImportService $importService,
        private readonly RimImportConfig $config,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly RimSyncStatus $status,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->bundleStatusRepo->isActive('Number1RimImportBundle')) {
            $io->note('Number1RimImportBundle is Inactive in Bundle Management — skipping.');

            return Command::SUCCESS;
        }

        if ($this->status->isRunning()) {
            $io->note('A rim sync is already running — skipping this run (prevents overlapping cron + on-demand triggers).');

            return Command::SUCCESS;
        }

        $this->status->markRunning();

        $options = $this->config->resolve($this->entityManager);

        try {
            $result = $this->importService->sync($this->entityManager, $options);
        } catch (RimApiException $e) {
            $this->status->markError($e->getMessage());
            $io->error('Rim API sync failed: ' . $e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            // Anything unexpected still needs to flip the status out of "running" — otherwise a
            // crash leaves the admin screen stuck showing "in progress" until the stale-after
            // timeout passes (RimSyncStatus::STALE_AFTER_SECONDS).
            $this->status->markError('Unexpected error: ' . $e->getMessage());

            throw $e;
        }

        $this->status->markSuccess($result);

        $io->success(sprintf(
            '%d created, %d updated, %d skipped, %d duplicate(s), %d inactivated.',
            $result->created,
            $result->updated,
            $result->skipped,
            $result->duplicates,
            $result->inactivated
        ));

        if ($result->errors !== []) {
            $io->warning(sprintf('%d row error(s) — see the admin config screen for details.', count($result->errors)));
        }

        return Command::SUCCESS;
    }
}
