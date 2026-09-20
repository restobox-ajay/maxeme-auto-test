<?php

declare(strict_types=1);

namespace BarcodeBundle\Command;

use App\Command\Demo\DemoSeed;
use App\Command\Demo\DemoVolume;
use App\Command\Demo\DemoVolumeCheckpoint;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use BarcodeBundle\Barcode\BarcodeException;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Barcode\Gtin;
use BarcodeBundle\Entity\ProductBarcode;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * BarcodeBundle's step of `app:seed-demo-volume`: barcodes on existing and new products, of every
 * kind the barcode screen offers.
 *
 * Through BarcodeRegistry::attach() and ::mintInternal() — the barcode screen's only write path —
 * so every code is checked the way a typed one is (check digit for UPC/EAN/GTIN, Code 128 encodable,
 * not already on the product) and the first code on a product becomes its primary. The registry
 * never touches product_core, which is why this may run against products the seeder did not create.
 *
 * Retail codes are built with a GS1 "restricted circulation" prefix (02 / 2x), so a demo code can
 * never collide with a real manufacturer's product in a scanner or a lookup.
 *
 * product_barcode cascades off product_core; on a demo box every product carries the demo-seed
 * sync_source, and DemoDataCleaner removes barcodes with their products.
 */
#[AsCommand(
    name: 'app:seed-demo-volume:barcode',
    description: 'app:seed-demo-volume step: product barcodes of every kind.',
)]
final class SeedBarcodeVolumeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly BundleStatusRepository $bundles,
        private readonly DemoVolumeCheckpoint $checkpoint,
        private readonly BarcodeRegistry $barcodes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many new rows of each object to add.', '200')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run token shared with the other steps.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = max(1, (int) $input->getOption('count'));
        $volume = new DemoVolume($input->getOption('run'), 'barcode');

        if (!$this->bundles->isActive('BarcodeBundle')) {
            $io->error('BarcodeBundle is Inactive; its screens are 404 and there is nothing to seed.');

            return Command::FAILURE;
        }

        $productIds = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM product_core WHERE status = 'Active' ORDER BY id",
        ));
        if ($productIds === []) {
            return Command::SUCCESS;
        }

        $vendors = array_map('strval', $this->connection->fetchFirstColumn(
            sprintf('SELECT name FROM vendor WHERE %s ORDER BY id LIMIT 50', DemoSeed::WHERE_VENDOR),
        )) ?: ['Supplier'];
        $customers = array_map('strval', $this->connection->fetchFirstColumn(
            sprintf('SELECT name FROM company WHERE %s ORDER BY id LIMIT 50', DemoSeed::WHERE_COMPANY),
        )) ?: ['Customer'];

        $created = 0;
        $refused = 0;
        $kinds = [];

        for ($attempt = 0; $created < $count && $attempt < $count * 3; ++$attempt) {
            $product = $this->em->find(ProductCore::class, $volume->pick($productIds));
            if (!$product instanceof ProductCore) {
                continue;
            }

            $kind = $volume->weighted([
                ProductBarcode::KIND_UPC => 30, ProductBarcode::KIND_EAN => 20, ProductBarcode::KIND_GTIN => 10,
                ProductBarcode::KIND_VENDOR_PART => 18, ProductBarcode::KIND_CUSTOMER_PART => 10, ProductBarcode::KIND_INTERNAL => 12,
            ]);

            try {
                if ($kind === ProductBarcode::KIND_INTERNAL) {
                    $this->barcodes->mintInternal($product, 'Printed on the shelf label');
                } else {
                    [$code, $party, $note] = match ($kind) {
                        ProductBarcode::KIND_UPC => [Gtin::withCheckDigit('02' . $this->digits($volume, 9)), null, null],
                        ProductBarcode::KIND_EAN => [Gtin::withCheckDigit('2' . $volume->int(0, 9) . $this->digits($volume, 10)), null, null],
                        ProductBarcode::KIND_GTIN => [Gtin::withCheckDigit('1' . '02' . $this->digits($volume, 10)), null, 'Case of 4'],
                        ProductBarcode::KIND_VENDOR_PART => [sprintf('%s-%05d', $volume->pick(['HK', 'MI', 'PR', 'BF', 'GY', 'TY']), $volume->int(100, 99999)), $volume->pick($vendors), null],
                        default => [sprintf('C%06d', $volume->int(1, 999999)), $volume->pick($customers), 'Customer catalogue number'],
                    };
                    $this->barcodes->attach($product, $code, $kind, $party, $note, $volume->chance(0.1));
                }
                ++$created;
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            } catch (BarcodeException) {
                ++$refused;
            }

            if ($created % 50 === 0) {
                ($this->checkpoint)();
            }
        }

        ($this->checkpoint)();

        ksort($kinds);
        $io->writeln(sprintf('  %-30s %6d', 'product_barcode', $created));
        $io->writeln('  by kind: ' . implode(', ', array_map(static fn (string $k, int $n): string => "$k $n", array_keys($kinds), $kinds)));
        if ($refused > 0) {
            $io->writeln(sprintf('  %-30s %6d', '  refused by the registry', $refused));
        }

        return Command::SUCCESS;
    }

    private function digits(DemoVolume $volume, int $length): string
    {
        $digits = '';
        for ($i = 0; $i < $length; ++$i) {
            $digits .= (string) $volume->int(0, 9);
        }

        return $digits;
    }
}
