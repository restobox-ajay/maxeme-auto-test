<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryLotRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\Inventory\InventoryModeResolver;

/**
 * Takes expired stock off sale, by writing real movements (#550).
 *
 * **Expiry is not self-executing.** Nothing makes a lot stop being sellable on its date. Status only
 * ever changes via a movement, so this is the sweep that writes them — `status_change` groups moving
 * `available → expired`, which drops the units out of `SUM(available)` and therefore out of
 * `product_inventory.quantity` in the same transaction. Until it runs, expired stock is on sale, and
 * that is a visible, fixable fact rather than a silent one.
 *
 * Filtering expiry inside the availability query instead would be the obvious shortcut and it is
 * wrong: it would break `quantity == SUM(available)`, which is the one thing holding this layer
 * together, and it would make the core number disagree with itself depending on who asked.
 *
 * Convention: `expiry` is the **last usable day**. Stock dated the 30th is sellable through the 30th
 * and unsellable from the 1st — so the sweep acts on lots whose expiry is strictly before today.
 *
 *   30 0 * * * cd /path/to/app && php bin/console app:inventory-depth:expire-lots >> /var/log/expire-lots.log 2>&1
 */
#[AsCommand(
    name: 'app:inventory-depth:expire-lots',
    description: 'Moves stock in lots past their last usable day from available to expired, as real status_change movements.',
)]
final class ExpireLotsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryLotRepository $lots,
        private readonly InventoryDetailRepository $details,
        private readonly StockMovementService $movements,
        private readonly InventoryModeResolver $inventoryModes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('as-of', null, InputOption::VALUE_REQUIRED, 'Treat this date as today (Y-m-d).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would move without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $asOfRaw = $input->getOption('as-of');
        try {
            $today = \is_string($asOfRaw) && $asOfRaw !== ''
                ? new \DateTimeImmutable($asOfRaw)
                : new \DateTimeImmutable();
        } catch (\Exception) {
            $io->error('--as-of must be a date this machine understands, e.g. 2026-08-21.');

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        // Strictly before today: the expiry date itself is still a usable day.
        $lastExpired = $today->modify('-1 day');

        /** @var list<InventoryLot> $lots */
        $lots = $this->lots->expiringThrough($lastExpired);

        if ($lots === []) {
            $io->success(sprintf('Nothing has expired as of %s.', $today->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $moved = 0;
        $reported = [];
        $sweptLots = 0;
        $skipped = [];

        foreach ($lots as $lot) {
            // A product switched back to `simple` keeps its detail rows and its lots — the switch is
            // deliberately lossless — and this query selects lots by expiry alone, so one reverted
            // product with a dated lot is enough to reach StockMovementService's opt-in guard. That
            // throws, and an uncaught throw here would abandon the rest of the nightly sweep: every
            // lot ordered after it silently stays on sale. Skipped and reported instead.
            // Through the resolver, never the raw column: with InventoryDepthBundle Inactive a
                // stored `dimensional` must read as simple everywhere (#566).
            if (!$this->inventoryModes->isDimensional($lot->getProduct())) {
                $skipped[] = $lot->getLabel();
                continue;
            }

            /** @var list<InventoryDetail> $rows */
            $rows = $this->entityManager->getRepository(InventoryDetail::class)->findBy([
                'lot' => $lot,
                'status' => InventoryDetail::STATUS_AVAILABLE,
            ]);

            // One group per lot, so the ledger reads as "this batch expired" rather than as one
            // undifferentiated nightly sweep across unrelated products.
            $request = MovementRequest::of(
                InventoryMovementGroup::TYPE_STATUS_CHANGE,
                sprintf('expire-lot-%d-%s', $lot->getId() ?? 0, $today->format('Y-m-d')),
                sprintf('Lot %s reached its last usable day', $lot->getLabel()),
                'System',
                null,
                $today,
            );

            foreach ($rows as $row) {
                if ($row->getQuantity() <= 0) {
                    continue;
                }

                $from = new DetailKey(
                    $row->getWarehouse(),
                    $row->getLocation(),
                    $lot,
                    $row->getSerial(),
                    InventoryDetail::STATUS_AVAILABLE,
                );

                // Expired stock keeps its bin — it is still physically sitting there, and somebody
                // has to go and get it. Only sold/scrapped/lost drop their location.
                $request->move($lot->getProduct(), $from, new DetailKey(
                    $row->getWarehouse(),
                    $row->getLocation(),
                    $lot,
                    $row->getSerial(),
                    InventoryDetail::STATUS_EXPIRED,
                ), $row->getQuantity());

                $reported[] = [
                    $row->getProduct()->getSku() ?: ('#' . ($row->getProduct()->getId() ?? '?')),
                    $lot->getLabel(),
                    $row->getWarehouse()->getName(),
                    $row->getLocation()?->getCode() ?? '-',
                    (string) $row->getQuantity(),
                ];
                $moved += $row->getQuantity();
            }

            if ($request->isEmpty()) {
                continue;
            }

            $sweptLots++;

            if ($dryRun) {
                continue;
            }

            $this->movements->apply($request);
        }

        // The second sweep: rows with no lot but a real expiry of their own (#795) — a serialised or
        // untracked product's date lives on `d.expiry` rather than on a batch, so there is no lot to
        // iterate above and this is its own pass over `InventoryDetailRepository::expiringWithNoLot()`.
        // One group per ROW rather than per lot, deliberately: there is no batch identity to fold
        // several rows under, and `-detail-<id>` keeps each row's own client-operation-id distinct
        // from the lot sweep's `-lot-<id>` so the two passes can never collide on one op id and
        // double-sweep the same stock even if a row somehow matched both queries in one run.
        foreach ($this->details->expiringWithNoLot($lastExpired) as $row) {
            if (!$this->inventoryModes->isDimensional($row->getProduct())) {
                $skipped[] = sprintf('%s (%s)', $row->getProduct()->getSku() ?: ('#' . $row->getProduct()->getId()), $row->getExpiry()?->format('Y-m-d') ?? '?');
                continue;
            }

            if ($row->getQuantity() <= 0) {
                continue;
            }

            $request = MovementRequest::of(
                InventoryMovementGroup::TYPE_STATUS_CHANGE,
                sprintf('expire-detail-%d-%s', $row->getId() ?? 0, $today->format('Y-m-d')),
                sprintf('%s reached its last usable day', $row->getExpiry()?->format('Y-m-d') ?? 'stock'),
                'System',
                null,
                $today,
            );

            $from = new DetailKey(
                $row->getWarehouse(),
                $row->getLocation(),
                null,
                $row->getSerial(),
                InventoryDetail::STATUS_AVAILABLE,
                false,
                $row->getExpiry(),
            );

            // Expired stock keeps its bin, same as the lot sweep above — it is still physically
            // sitting there, and somebody has to go and get it.
            $request->move($row->getProduct(), $from, new DetailKey(
                $row->getWarehouse(),
                $row->getLocation(),
                null,
                $row->getSerial(),
                InventoryDetail::STATUS_EXPIRED,
                false,
                $row->getExpiry(),
            ), $row->getQuantity());

            $reported[] = [
                $row->getProduct()->getSku() ?: ('#' . ($row->getProduct()->getId() ?? '?')),
                'exp ' . ($row->getExpiry()?->format('Y-m-d') ?? '?'),
                $row->getWarehouse()->getName(),
                $row->getLocation()?->getCode() ?? '-',
                (string) $row->getQuantity(),
            ];
            $moved += $row->getQuantity();
            $sweptLots++;

            if ($dryRun) {
                continue;
            }

            $this->movements->apply($request);
        }

        if ($skipped !== []) {
            $io->warning(sprintf(
                '%d lot(s) belong to a product that is back on simple inventory and were left alone: %s. '
                . 'Their stock stays sellable until the product is switched back or the lot is written off by hand.',
                \count($skipped),
                implode(', ', $skipped),
            ));
        }

        if ($reported === []) {
            $io->success(sprintf('Every expired lot is already off sale as of %s.', $today->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $io->table(['Product', 'Lot', 'Warehouse', 'Bin', 'Quantity'], $reported);
        // The lots that actually had something to move, not every lot examined.
        $io->success(sprintf(
            '%s %d unit(s) across %d lot(s) from available to expired.',
            $dryRun ? 'Would move' : 'Moved',
            $moved,
            $sweptLots,
        ));

        return Command::SUCCESS;
    }
}
