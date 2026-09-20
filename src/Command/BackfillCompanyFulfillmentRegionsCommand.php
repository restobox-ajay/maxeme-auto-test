<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Service\AppSettings;
use App\Service\CompanyFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time/occasionally-rerun cleanup pass for companies that already exist in the database and
 * are stuck with either zero or many `Active` CompanyFulfillmentRegion rows (both broken states
 * left over from this command's original, now-removed `Company::$priceList`-driven logic — see
 * plan5-general-fixes.md item 7). Collapses each *affected* company down to exactly one Active row
 * (the "Main" region). Companies that already have exactly one Active row are left untouched
 * regardless of which region that is — per the client's confirmed rule, a company's single active
 * region is always correct and must never be silently overwritten, including by this cleanup.
 * Not wired into any new-company code path — CompanyFulfillmentRegionService::backfillForNewCompany()
 * still leaves brand-new companies with zero Active rows (blocked) until an admin explicitly
 * activates a region via the company edit screen.
 */
#[AsCommand(
    name: 'app:backfill-company-fulfillment-regions',
    description: 'Cleans up companies stuck with zero or multiple Active fulfillment regions, collapsing each to a single Active "Main" region'
)]
class BackfillCompanyFulfillmentRegionsCommand extends Command
{
    private const MAIN_REGION_NAME = 'Main';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        private readonly AppSettings $appSettings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $mainRegion = $this->entityManager->getRepository(FulfillmentRegion::class)->findOneBy(['name' => self::MAIN_REGION_NAME]);
        if (!$mainRegion instanceof FulfillmentRegion) {
            $io->error(sprintf('No FulfillmentRegion named "%s" exists — cannot proceed.', self::MAIN_REGION_NAME));

            return Command::FAILURE;
        }

        $priceList = $this->resolveDefaultPriceList();
        if (!$priceList instanceof PriceList) {
            $io->error('No default price list is configured (Admin → Settings → Registration Settings) and no Active price list exists to fall back to — cannot proceed.');

            return Command::FAILURE;
        }

        $companies = $this->entityManager->getRepository(Company::class)->findAll();
        $allRegions = $this->entityManager->getRepository(FulfillmentRegion::class)->findAll();
        $cfrRepo = $this->entityManager->getRepository(CompanyFulfillmentRegion::class);

        $rowsCreated = 0;
        $rowsDeactivated = 0;
        $companiesFixed = 0;

        foreach ($companies as $company) {
            $existingCount = $cfrRepo->count(['company' => $company]);
            $missing = max(0, count($allRegions) - $existingCount);
            $rowsCreated += $missing;

            if (!$dryRun && $missing > 0) {
                $this->companyFulfillmentRegionService->backfillForNewCompany($company);
                $this->entityManager->flush();
            }

            $rows = $cfrRepo->findBy(['company' => $company]);
            $activeCount = count(array_filter($rows, static fn (CompanyFulfillmentRegion $r): bool => $r->isActive()));
            if ($activeCount === 1) {
                // Already valid — exactly one active region, whichever it is. Never overwrite this;
                // that's the exact bug (a legitimate single region silently replaced) this item exists to fix.
                continue;
            }

            $mainRow = null;
            $companyChanged = false;

            foreach ($rows as $row) {
                $isMainRow = $row->getFulfillmentRegion()->getId() === $mainRegion->getId();
                if ($isMainRow) {
                    $mainRow = $row;
                    continue;
                }

                if ($row->isActive()) {
                    ++$rowsDeactivated;
                    $companyChanged = true;
                    if (!$dryRun) {
                        $row->setStatus('Inactive')->touch();
                        $this->entityManager->persist($row);
                    }
                }
            }

            if (!$mainRow instanceof CompanyFulfillmentRegion) {
                // backfillForNewCompany() above should have created this row already; only reachable in dry-run.
                $mainRow = (new CompanyFulfillmentRegion())->setCompany($company)->setFulfillmentRegion($mainRegion);
            }

            $mainAlreadyCorrect = $mainRow->isActive() && $mainRow->getPriceList()?->getId() === $priceList->getId();
            if (!$mainAlreadyCorrect) {
                $companyChanged = true;
                if (!$dryRun) {
                    $mainRow->setStatus('Active')->setPriceList($priceList)->touch();
                    $this->entityManager->persist($mainRow);
                }
            }

            if ($companyChanged) {
                ++$companiesFixed;
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        $io->success(sprintf(
            '%s%d companies processed, %d fixed (collapsed to a single Active "Main" region), %d region row(s) created, %d region row(s) deactivated.',
            $dryRun ? '[DRY RUN] ' : '',
            count($companies),
            $companiesFixed,
            $rowsCreated,
            $rowsDeactivated
        ));

        return Command::SUCCESS;
    }

    private function resolveDefaultPriceList(): ?PriceList
    {
        $configuredId = (int) ($this->appSettings->get('company_registration_default_price_list_id', '') ?: 0);
        if ($configuredId > 0) {
            $priceList = $this->entityManager->find(PriceList::class, $configuredId);
            if ($priceList instanceof PriceList) {
                return $priceList;
            }
        }

        return $this->entityManager->getRepository(PriceList::class)->findOneBy(['status' => 'Active'], ['name' => 'ASC']);
    }
}
