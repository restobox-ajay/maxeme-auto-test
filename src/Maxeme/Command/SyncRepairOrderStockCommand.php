<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Inventory\RepairOrderStockSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings every repair order that keeps stock in line with the rule "parts leave the stock when the
 * invoice is issued, and come back when it is cancelled" (F15). A repair order is reconciled on its
 * own whenever it or its invoice is saved; this is for the ones invoiced before the rule existed.
 * Safe to run again: only differences move.
 */
#[AsCommand(name: 'app:maxeme:sync-repair-order-stock', description: 'Take invoiced repair orders\' parts off the stock (and put cancelled ones back)')]
final class SyncRepairOrderStockCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepairOrderStockSubscriber $stock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $repairOrders = $this->entityManager->getRepository(RepairOrder::class)->findBy(['holdsStock' => true]);
        foreach ($repairOrders as $repairOrder) {
            $this->stock->reconcile($repairOrder);
        }
        $io->success(sprintf('%d repair order(s) checked.', \count($repairOrders)));

        return Command::SUCCESS;
    }
}
