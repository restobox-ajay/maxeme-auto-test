<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Maxeme\Service\RepairOrderConverter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives every existing invoice and appointment a repair order (RepairOrderConverter). Run after
 * app:maxeme:import-legacy and app:maxeme:convert-parts; safe to re-run.
 */
#[AsCommand(name: 'app:maxeme:convert-repair-orders', description: 'Turn the existing invoices and appointments into repair orders')]
final class ConvertRepairOrdersCommand
{
    public function __construct(
        private readonly RepairOrderConverter $converter,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->converter->convert();
        $io->success(sprintf('%d invoice(s) and %d appointment(s) without one became repair orders.', $result['invoices'], $result['appointments']));

        return Command::SUCCESS;
    }
}
