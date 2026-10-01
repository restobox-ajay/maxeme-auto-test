<?php

declare(strict_types=1);

namespace App\Maxeme\Command;

use App\Maxeme\Service\PartConverter;
use App\Service\WarehouseFulfillmentRegionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copies the legacy Maxeme parts into core's product catalogue and links their invoice lines
 * (PartConverter). Run after app:maxeme:setup (which creates the warehouse) and after
 * app:maxeme:import-legacy; safe to re-run.
 */
#[AsCommand(name: 'app:maxeme:convert-parts', description: 'Copy the legacy parts into the product catalogue and link their invoice lines')]
final class ConvertPartsCommand
{
    /** @param array{region: string, province: string, country: string} $warehouse */
    public function __construct(
        private readonly PartConverter $converter,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        #[Autowire(param: 'maxeme.warehouse')]
        private readonly array $warehouse,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $warehouse = $this->warehouses->warehouseForRegionName($this->warehouse['region']);
        if ($warehouse === null) {
            $io->error(sprintf('No warehouse serves region "%s". Run app:maxeme:setup first.', $this->warehouse['region']));

            return Command::FAILURE;
        }

        $result = $this->converter->convert($warehouse);
        $io->success(sprintf('%d product(s) created, %d updated; %d invoice line(s) linked. Stock is in warehouse "%s".', $result['created'], $result['updated'], $result['lines'], $warehouse->getName()));

        return Command::SUCCESS;
    }
}
